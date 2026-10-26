<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BootstrapSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthcheck_endpoint_is_ok(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_homepage_renders(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_database_connection_uses_mariadb(): void
    {
        $version = DB::selectOne('SELECT VERSION() AS version')->version;

        $this->assertStringContainsStringIgnoringCase('mariadb', $version);
    }

    public function test_queue_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('job_batches') || Schema::hasTable('failed_jobs'));
    }
}
