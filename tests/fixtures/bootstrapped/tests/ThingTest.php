<?php
namespace Boot\Tests;

use Boot\Thing;
use PHPUnit\Framework\TestCase;

final class ThingTest extends TestCase
{
    public function testValue(): void
    {
        self::assertSame(1, (new Thing())->value());
    }
}
