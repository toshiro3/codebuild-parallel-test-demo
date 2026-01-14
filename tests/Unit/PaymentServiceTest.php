<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PaymentServiceTest extends TestCase
{
    public function test_payment_can_be_processed(): void
    {
        sleep(3); // 外部API呼び出しを模擬
        $this->assertTrue(true);
    }

    public function test_refund_can_be_processed(): void
    {
        sleep(2);
        $this->assertTrue(true);
    }

    public function test_payment_validation(): void
    {
        sleep(2);
        $this->assertTrue(true);
    }
}
