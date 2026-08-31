<?php
namespace App\Tests\Unit;

use App\Service\ReportService;
use PHPUnit\Framework\TestCase;

final class ReportServiceTest extends TestCase
{
    public function testRender(): void
    {
        self::assertSame('ok', (new ReportService())->render());
    }
}
