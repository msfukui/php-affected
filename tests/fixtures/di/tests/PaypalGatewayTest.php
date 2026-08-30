<?php
namespace App\Tests;

use App\Payment\PaypalGateway;
use PHPUnit\Framework\TestCase;

final class PaypalGatewayTest extends TestCase
{
    public function testIt(): void
    {
        self::assertTrue(true, PaypalGateway::class);
    }
}
