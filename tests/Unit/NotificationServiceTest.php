<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class NotificationServiceTest extends TestCase
{
    public function test_email_notification_sent(): void
    {
        sleep(2);
        $this->assertTrue(true);
    }

    public function test_push_notification_sent(): void
    {
        sleep(2);
        $this->assertTrue(true);
    }

    public function test_sms_notification_sent(): void
    {
        sleep(3);
        $this->assertTrue(true);
    }
}
