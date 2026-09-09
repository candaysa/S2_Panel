<?php

namespace Tests\Unit;

use App\Support\Api;
use PHPUnit\Framework\TestCase;

/**
 * Every index endpoint takes its page size from ?per_page=, so this is
 * client-controlled input on ten routes. The upper bound was always
 * enforced; the lower one was not, which is what let per_page=0 through to
 * the paginator.
 */
class ApiPerPageTest extends TestCase
{
    public function test_absent_value_falls_back_to_the_default(): void
    {
        $this->assertSame(25, Api::perPage(null));
        $this->assertSame(50, Api::perPage(null, 50));
    }

    public function test_value_is_capped_at_the_maximum(): void
    {
        $this->assertSame(100, Api::perPage(500));
        $this->assertSame(200, Api::perPage(500, 25, 200));
    }

    public function test_zero_and_negative_are_clamped_to_one(): void
    {
        $this->assertSame(1, Api::perPage(0));
        $this->assertSame(1, Api::perPage(-100));
        $this->assertSame(1, Api::perPage('0'));
    }

    public function test_non_numeric_input_falls_back_to_the_default(): void
    {
        $this->assertSame(25, Api::perPage('all'));
        $this->assertSame(25, Api::perPage(''));
        $this->assertSame(25, Api::perPage([]));
    }

    public function test_a_valid_value_passes_through(): void
    {
        $this->assertSame(40, Api::perPage(40));
        $this->assertSame(40, Api::perPage('40'));
    }
}
