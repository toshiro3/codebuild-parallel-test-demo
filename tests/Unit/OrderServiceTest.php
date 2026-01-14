<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class OrderServiceTest extends TestCase
{
    public function test_order_can_be_placed(): void
    {
        sleep(3); // 重い処理を模擬
        $this->assertTrue(true);
    }

    public function test_order_can_be_cancelled(): void
    {
        sleep(2);
        $this->assertTrue(true);
    }

    public function test_order_total_calculation(): void
    {
        sleep(2);
        $this->assertTrue(true);
    }
}
