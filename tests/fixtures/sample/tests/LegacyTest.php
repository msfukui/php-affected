<?php
namespace App\Tests;

use App\Tests\Support\BaseTestCase;

require_once __DIR__ . '/../src/Legacy/legacy_bootstrap.php';

final class LegacyTest extends BaseTestCase
{
    public function testSlugify(): void
    {
        self::assertSame('a-b', legacy_slugify('a b'));
    }
}
