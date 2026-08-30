<?php
namespace App\Tests;

use App\Payment\PaymentService;
use PHPUnit\Framework\TestCase;

final class PaymentServiceTest extends TestCase
{
    public function testIt(): void
    {
        self::assertTrue(true, PaymentService::class);
    }
}
