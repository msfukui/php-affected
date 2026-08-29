<?php
namespace App\Tests;

use App\Report\Report;
use App\Tests\Support\BaseTestCase;

final class ReportTest extends BaseTestCase
{
    public function testRender(): void
    {
        self::assertIsString((new Report())->render(new \App\Support\Money(1)));
    }
}
