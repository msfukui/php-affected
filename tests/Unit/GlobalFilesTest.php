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
 * 検証しやすいように [ルートからの相対パス => scope の相対パス] にする
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

describe('scope の重複', function () {
    it('広い scope に包含される狭い scope は落とす', function () {
        $root = makeProject([
            'composer.json' => json_encode(['autoload' => ['files' => ['packages/alpha/src/h.php']]]),
            'packages/alpha/composer.json' => json_encode(['autoload' => ['files' => ['src/h.php']]]),
            'packages/alpha/src/h.php' => '<?php',
        ]);

        $globals = detectGlobals($root);

        expect($globals)->toHaveCount(1)
            ->and(relativeGlobals($root, $globals))->toBe(['packages/alpha/src/h.php' => '.']);
    });

    it('互いに包含しない scope は両方残す', function () {
        $shared = '<?xml version="1.0"?><phpunit bootstrap="../../shared/bootstrap.php"/>';
        $root = makeProject([
            'packages/alpha/phpunit.xml' => $shared,
            'packages/beta/phpunit.xml' => $shared,
            'shared/bootstrap.php' => '<?php',
        ]);

        $globals = detectGlobals($root);
        $scopes = array_map(static fn(array $g): string => basename($g['scope']), $globals);
        sort($scopes);

        expect($scopes)->toBe(['alpha', 'beta'])
            // '..' は設定ファイルの位置から畳まれる
            ->and($globals[0]['path'])->toBe($root . '/shared/bootstrap.php');
    });
});

describe('パスの正規化', function () {
    it('相対指定の . と .. を畳む', function () {
        $root = makeProject([
            'phpunit.xml' => '<?xml version="1.0"?><phpunit bootstrap="./tests/../boot.php"/>',
            'boot.php' => '<?php',
        ]);

        expect(detectGlobals($root)[0]['path'])->toBe($root . '/boot.php');
    });

    it('シンボリックリンクは解決せず走査結果と同じ表記のままにする', function () {
        $real = makeProject([
            'composer.json' => json_encode(['autoload' => ['files' => ['boot.php']]]),
            'boot.php' => '<?php',
        ]);
        $link = $real . '-link';
        symlink($real, $link);

        try {
            $globals = detectGlobals($link);

            expect($globals)->toHaveCount(1)
                ->and($globals[0]['path'])->toBe($link . '/boot.php')
                ->and($globals[0]['scope'])->toBe($link);
        } finally {
            unlink($link);
        }
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
