<?php
namespace App\Tests;

use App\Tests\Support\BaseTestCase;

final class DetachedTest extends BaseTestCase
{
    public function testRun(): void
    {
        $class = 'App' . '\\' . 'Detached';
        self::assertSame(42, (new $class())->run());
    }
}
