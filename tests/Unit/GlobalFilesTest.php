<?php

declare(strict_types=1);

use PhpAffected\GlobalFiles;
use PhpAffected\Scanner;

/**
 * @return list<array{path: string, scope: string}>
 */
function detectGlobals(string $root): array
{
    return (new GlobalFiles(new Scanner($root)))->detect();
}

/**
 * 検証しやすいように [ルートからの相対パス => scope の相対パス] にする。
 *
 * @return array<string, string>
 */
function relativeGlobals(string $root, array $globals): array
{
    $out = [];
    foreach ($globals as $global) {
        $path = substr($global['path'], strlen($root) + 1);
        $scope = $global['scope'] === $root ? '.' : substr($global['scope'], strlen($root) + 1);
        $out[$path] = $scope;
    }
    ksort($out);

    return $out;
}

describe('プロジェクトルートの設定', function () {
    it('composer の autoload.files と autoload-dev.files を拾う', function () {
        $root = makeProject([
            'composer.json' => json_encode([
                'autoload' => ['files' => ['src/helpers.php']],
                'autoload-dev' => ['files' => ['tests/dev.php']],
            ]),
            'src/helpers.php' => '<?php',
            'tests/dev.php' => '<?php',
        ]);

        expect(relativeGlobals($root, detectGlobals($root)))
            ->toBe(['src/helpers.php' => '.', 'tests/dev.php' => '.']);
    });

    it('phpunit.xml の bootstrap を拾う', function () {
        $root = makeProject([
            'phpunit.xml' => '<?xml version="1.0"?><phpunit bootstrap="tests/bootstrap.php"/>',
            'tests/bootstrap.php' => '<?php',
        ]);

        expect(relativeGlobals($root, detectGlobals($root)))->toBe(['tests/bootstrap.php' => '.']);
    });

    it('同じディレクトリでは phpunit.xml が phpunit.xml.dist に優先する', function () {
        $root = makeProject([
            'phpunit.xml' => '<?xml version="1.0"?><phpunit bootstrap="real.php"/>',
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit bootstrap="dist.php"/>',
            'real.php' => '<?php',
            'dist.php' => '<?php',
        ]);

        expect(relativeGlobals($root, detectGlobals($root)))->toBe(['real.php' => '.']);
    });

    it('phpunit.xml がなければ phpunit.xml.dist を見る', function () {
        $root = makeProject([
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit bootstrap="boot.php"/>',
            'boot.php' => '<?php',
        ]);

        expect(relativeGlobals($root, detectGlobals($root)))->toBe(['boot.php' => '.']);
    });
});

describe('サブディレクトリの設定', function () {
    it('ルート以外に置かれた composer.json も拾い、そのディレクトリを有効範囲にする', function () {
        $root = makeProject([
            'packages/alpha/composer.json' => json_encode(['autoload' => ['files' => ['src/a.php']]]),
            'packages/alpha/src/a.php' => '<?php',
            'packages/beta/composer.json' => json_encode(['autoload' => ['files' => ['src/b.php']]]),
            'packages/beta/src/b.php' => '<?php',
        ]);

        expect(relativeGlobals($root, detectGlobals($root)))->toBe([
            'packages/alpha/src/a.php' => 'packages/alpha',
            'packages/beta/src/b.php' => 'packages/beta',
        ]);
    });

    it('ルート以外に置かれた phpunit.xml も拾う', function () {
        $root = makeProject([
            'apps/api/phpunit.xml' => '<?xml version="1.0"?><phpunit bootstrap="tests/boot.php"/>',
            'apps/api/tests/boot.php' => '<?php',
        ]);

        expect(relativeGlobals($root, detectGlobals($root)))
            ->toBe(['apps/api/tests/boot.php' => 'apps/api']);
    });

    // 相対パスは設定ファイルの位置基準。ルート基準で解決すると別物を指してしまう
    it('相対パスを設定ファイルのある場所から解決する', function () {
        $root = makeProject([
            'boot.php' => '<?php',                     // ルートの同名ファイル (これではない)
            'packages/alpha/phpunit.xml' => '<?xml version="1.0"?><phpunit bootstrap="boot.php"/>',
            'packages/alpha/boot.php' => '<?php',
        ]);

        expect(relativeGlobals($root, detectGlobals($root)))
            ->toBe(['packages/alpha/boot.php' => 'packages/alpha']);
    });

    it('除外ディレクトリの設定は拾わない', function () {
        $root = makeProject([
            'vendor/some/pkg/composer.json' => json_encode(['autoload' => ['files' => ['x.php']]]),
            'vendor/some/pkg/x.php' => '<?php',
        ]);

        expect(detectGlobals($root))->toBe([]);
    });
});

describe('壊れた入力', function () {
    it('存在しないファイルは無視する', function () {
        $root = makeProject([
            'composer.json' => json_encode(['autoload' => ['files' => ['src/missing.php']]]),
        ]);

        expect(detectGlobals($root))->toBe([]);
    });

    it('設定ファイルがなければ空を返す', function () {
        expect(detectGlobals(makeProject(['src/A.php' => '<?php'])))->toBe([]);
    });

    it('壊れた設定ファイルでも落ちない', function () {
        $root = makeProject(['composer.json' => '{ not json', 'phpunit.xml' => '<phpunit']);

        expect(detectGlobals($root))->toBe([]);
    });
});
