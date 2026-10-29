<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A privately stored project upload (ELF firmware or network capture)
 * plus its deterministic extraction state.
 *
 * Raw bytes live on the non-served artifacts disk under a generated
 * storage key; the original filename is display metadata only. Parsed
 * output is cached as an immutable JSON snapshot beside the bytes.
 */
class ProjectArtifact extends Model
{
    public const STATE_UPLOADED = 'uploaded';

    public const STATE_QUEUED = 'queued';

    public const STATE_EXTRACTING = 'extracting';

    public const STATE_READY = 'ready';

    public const STATE_PARTIAL = 'partial';

    public const STATE_FAILED = 'failed';

    public const STATE_NEEDS_CONFIGURATION = 'needs_configuration';

    public const FORMAT_ELF = 'elf';

    public const FORMAT_PCAP = 'pcap';

    public const FORMAT_PCAPNG = 'pcapng';

    public const FORMAT_PE = 'pe';

    public const FORMAT_MACHO = 'macho';

    public const FORMAT_DEX = 'dex';

    public const FORMAT_APK = 'apk';

    public const FORMAT_CLASS = 'class';

    public const FORMAT_IHEX = 'ihex';

    public const FORMAT_SREC = 'srec';

    public const FORMAT_PRG = 'prg';

    public const FORMAT_ARCHIVE = 'archive';

    public const FORMAT_RAW = 'raw';

    public const FORMAT_UNKNOWN = 'unknown';

    public const EXTRACTION_SCHEMA = '1';

    protected $fillable = [
        'project_id',
        'uploader_id',
        'original_name',
        'byte_size',
        'sha256',
        'storage_key',
        'format',
        'arch',
        'state',
        'failure_code',
        'failure_message',
        'extractor',
        'extraction_schema',
        'warnings',
        'limits_applied',
        'tech_attributes',
        'extracted_at',
    ];

    protected function casts(): array
    {
        return [
            'warnings' => 'array',
            'limits_applied' => 'array',
            'tech_attributes' => 'array',
            'extracted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function isUsable(): bool
    {
        return in_array($this->state, [self::STATE_READY, self::STATE_PARTIAL], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, [self::STATE_READY, self::STATE_PARTIAL, self::STATE_FAILED], true);
    }

    public function needsConfiguration(): bool
    {
        return $this->state === self::STATE_NEEDS_CONFIGURATION;
    }

    public function rawPath(): string
    {
        return $this->project_id.'/'.$this->storage_key.'.bin';
    }

    public function snapshotPath(): string
    {
        return $this->project_id.'/'.$this->storage_key.'.extraction.json';
    }

    /** @return array<string, mixed>|null */
    public function readSnapshot(): ?array
    {
        $disk = Storage::disk('artifacts');

        if (! $disk->exists($this->snapshotPath())) {
            return null;
        }

        $decoded = json_decode($disk->get($this->snapshotPath()), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            self::STATE_UPLOADED => 'Uploaded',
            self::STATE_QUEUED => 'Queued',
            self::STATE_EXTRACTING => 'Extracting',
            self::STATE_READY => 'Ready',
            self::STATE_PARTIAL => 'Partial',
            self::STATE_FAILED => 'Failed',
            self::STATE_NEEDS_CONFIGURATION => 'Needs configuration',
            default => $this->state,
        };
    }
}
