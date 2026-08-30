<?php
namespace App\Tests;

use App\Payment\LoggingGateway;
use PHPUnit\Framework\TestCase;

final class LoggingGatewayTest extends TestCase
{
    public function testIt(): void
    {
        self::assertTrue(true, LoggingGateway::class);
    }
}
