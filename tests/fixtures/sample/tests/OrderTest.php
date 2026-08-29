<?php
namespace App\Tests;

use App\Order\Order;
use App\Tests\Support\BaseTestCase;

final class OrderTest extends BaseTestCase
{
    public function testConstruct(): void
    {
        self::assertInstanceOf(Order::class, new Order(null));
    }
}
