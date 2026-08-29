<?php
namespace App\Tests;

use App\Unrelated\Widget;
use App\Tests\Support\BaseTestCase;

final class WidgetTest extends BaseTestCase
{
    public function testName(): void
    {
        self::assertSame('widget', (new Widget())->name());
    }
}
