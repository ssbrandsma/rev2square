<?php

namespace Tests;

use App\Services\Realtime\RealtimeEpoch;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        RealtimeEpoch::forgetMemo();

        parent::tearDown();
    }
}
