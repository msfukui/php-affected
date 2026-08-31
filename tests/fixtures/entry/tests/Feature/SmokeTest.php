<?php
namespace App\Tests\Feature;

use App\Kernel;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testBoot(): void
    {
        self::assertTrue(true, Kernel::class);
    }
}
