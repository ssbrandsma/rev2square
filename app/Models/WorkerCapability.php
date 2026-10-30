<?php

namespace App\Models;

use App\Services\Binary\Adapters\BinaryAdapter;
use App\Services\Binary\Adapters\SandboxedBackend;
use Illuminate\Database\Eloquent\Model;

/**
 * Versioned capability snapshot captured ON the analysis worker.
 * The web container must never infer worker support from its own
 * binaries: dispatch and the capability matrix consult this table,
 * and the job re-verifies at execution time.
 */
class WorkerCapability extends Model
{
    protected $fillable = [
        'adapter', 'available', 'version', 'targets', 'formats',
        'details', 'captured_at', 'worker',
    ];

    protected function casts(): array
    {
        return [
            'available' => 'boolean',
            'targets' => 'array',
            'formats' => 'array',
            'details' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    /**
     * @return array{verified: bool, version: ?string, note: string}
     */
    public static function verifiedFor(BinaryAdapter $adapter): array
    {
        // In-process adapter: identical code on web and worker, so no
        // snapshot can add information. Everything subprocess-based
        // needs a fresh worker capture.
        if ($adapter->id() === 'php-native') {
            return ['verified' => true, 'version' => $adapter->version(), 'note' => 'Bundled with the application; identical on web and worker.'];
        }

        $row = self::where('adapter', $adapter->id())->first();
        if ($row === null || $row->captured_at === null) {
            return ['verified' => false, 'version' => null, 'note' => 'No worker capability snapshot. Run: php artisan binary:worker-capabilities --refresh'];
        }

        $ttlHours = (int) config('artifacts.binary_worker_snapshot_ttl_hours', 24);
        if ($row->captured_at->lt(now()->subHours($ttlHours))) {
            return ['verified' => false, 'version' => $row->version, 'note' => 'Worker snapshot is stale (captured '.$row->captured_at->toIso8601String().'). Refresh it before queueing.'];
        }
        if (! $row->available) {
            return ['verified' => false, 'version' => $row->version, 'note' => 'The analysis worker reports this backend unavailable: '.($row->details['reason'] ?? 'unknown reason')];
        }

        return ['verified' => true, 'version' => $row->version, 'note' => 'Worker-verified '.($row->version ?? 'unversioned').' (captured '.$row->captured_at->toIso8601String().').'];
    }

    public static function capture(BinaryAdapter $adapter, ?string $worker): self
    {
        $probe = $adapter->probe();
        $details = ['reason' => $probe['reason'] ?? null];
        if ($adapter instanceof SandboxedBackend) {
            // Sandboxed captures identify the isolated execution
            // environment itself (runner arch + image), never the
            // coordinator's local binaries.
            $details = array_merge($details, $adapter->runnerDetails());
        }

        return self::updateOrCreate(
            ['adapter' => $adapter->id()],
            [
                'available' => $probe['available'],
                'version' => $adapter->version(),
                'targets' => $adapter->targets(),
                'formats' => $adapter->formats(),
                'details' => $details,
                'captured_at' => now(),
                'worker' => $worker,
            ],
        );
    }
}
