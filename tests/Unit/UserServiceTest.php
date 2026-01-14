<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class UserServiceTest extends TestCase
{
    public function test_user_can_be_created(): void
    {
        sleep(2); // 重い処理を模擬
        $this->assertTrue(true);
    }

    public function test_user_can_be_updated(): void
    {
        sleep(2);
        $this->assertTrue(true);
    }

    public function test_user_can_be_deleted(): void
    {
        sleep(2);
        $this->assertTrue(true);
    }

    public function test_user_validation_works(): void
    {
        sleep(1);
        $this->assertTrue(true);
    }
}
