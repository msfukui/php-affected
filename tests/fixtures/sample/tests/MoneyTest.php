<?php
namespace App\Tests;

use App\Support\Money;
use App\Tests\Support\BaseTestCase;

final class MoneyTest extends BaseTestCase
{
    public function testLabel(): void
    {
        self::assertNotEmpty((new Money(100))->label());
    }
}
