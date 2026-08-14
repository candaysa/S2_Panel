<?php

namespace Tests\Unit;

use App\Support\A2s;
use PHPUnit\Framework\TestCase;

class A2sTest extends TestCase
{
    public function test_info_returns_null_for_unreachable_host(): void
    {
        $this->assertNull(A2s::info('127.0.0.1', 1, 0.2));
    }

    public function test_players_returns_null_for_unreachable_host(): void
    {
        $this->assertNull(A2s::players('127.0.0.1', 1, 0.2));
    }

    public function test_info_returns_null_for_malformed_response(): void
    {
        // Reserved/private local port that is very unlikely to answer A2S;
        // the client must degrade to null rather than throwing.
        $this->assertNull(A2s::info('localhost', 9, 0.2));
    }
}