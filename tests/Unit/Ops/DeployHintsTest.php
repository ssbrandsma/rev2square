<?php

namespace Tests\Unit\Ops;

use App\Services\Ops\DeployHints;
use PHPUnit\Framework\TestCase;

class DeployHintsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['DEPLOY_VARIANT']);
        parent::tearDown();
    }

    public function test_virtualmin_hints_name_exact_commands(): void
    {
        $_SERVER['DEPLOY_VARIANT'] = 'virtualmin';

        $this->assertSame('virtualmin', DeployHints::variant());
        $this->assertSame(
            'docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin',
            DeployHints::composeBase()
        );
        $this->assertStringContainsString(
            'docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin up -d analysis-coordinator',
            DeployHints::startRunnersHint()
        );
        $this->assertStringContainsString(
            'docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin exec -T analysis-coordinator php artisan binary:worker-capabilities --refresh',
            DeployHints::refreshSnapshotsHint()
        );
        $this->assertSame(
            'docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin exec -T analysis-coordinator php artisan analysis:diagnostics',
            DeployHints::coordinatorArtisan('analysis:diagnostics')
        );
    }

    public function test_prod_hints_point_at_provisioning_not_missing_services(): void
    {
        $_SERVER['DEPLOY_VARIANT'] = 'prod';

        // compose.prod.yaml ships no runners/coordinator: hints must say
        // so instead of emitting commands against missing services.
        $this->assertStringContainsString('ships no analysis runners', DeployHints::startRunnersHint());
        $this->assertStringContainsString('ships no analysis coordinator', DeployHints::refreshSnapshotsHint());
        $this->assertStringContainsString('ships none', DeployHints::coordinatorArtisan('analysis:diagnostics'));
        $this->assertStringNotContainsString('up -d analysis-coordinator', DeployHints::startRunnersHint());
    }

    public function test_unknown_variant_falls_back_to_doc_pointers(): void
    {
        unset($_SERVER['DEPLOY_VARIANT']);
        $this->assertNull(DeployHints::variant());
        $this->assertNull(DeployHints::composeBase());
        $this->assertStringContainsString('docs/binary-analysis-setup.md', DeployHints::startRunnersHint());
        $this->assertStringContainsString('docs/binary-analysis-setup.md', DeployHints::refreshSnapshotsHint());

        $_SERVER['DEPLOY_VARIANT'] = 'staging';
        $this->assertNull(DeployHints::variant());
    }
}
