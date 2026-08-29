<?php
namespace App\Tests;

use App\Tests\Support\BaseTestCase;

final class ContainerTest extends BaseTestCase
{
    public function testServices(): void
    {
        $services = require __DIR__ . '/../container/services.php';
        self::assertArrayHasKey('payment.gateway', $services);
    }
}
