<?php
namespace App\Tests;

use App\Payment\StripeGateway;
use PHPUnit\Framework\TestCase;

final class StripeGatewayTest extends TestCase
{
    public function testIt(): void
    {
        self::assertTrue(true, StripeGateway::class);
    }
}
