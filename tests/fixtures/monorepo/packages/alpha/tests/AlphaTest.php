<?php
namespace Alpha\Tests;

use Alpha\Alpha;
use PHPUnit\Framework\TestCase;

final class AlphaTest extends TestCase
{
    public function testName(): void
    {
        self::assertSame('alpha', (new Alpha())->name());
    }
}
