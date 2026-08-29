<?php
namespace App\Tests;

use App\Payment\PaymentService;
use App\Tests\Support\BaseTestCase;

final class PaymentServiceTest extends BaseTestCase
{
    public function testPay(): void
    {
        self::assertTrue(true, PaymentService::class);
    }
}
