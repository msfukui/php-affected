<?php

declare(strict_types=1);

use PhpAffected\GlobalFiles;

it('composer の autoload.files と autoload-dev.files を拾う', function () {
    $root = makeProject([
        'composer.json' => json_encode([
            'autoload' => ['files' => ['src/helpers.php']],
            'autoload-dev' => ['files' => ['tests/dev.php']],
        ]),
        'src/helpers.php' => '<?php',
        'tests/dev.php' => '<?php',
    ]);

    expect((new GlobalFiles($root))->detect())->toBe([
        realpath($root . '/src/helpers.php'),
        realpath($root . '/tests/dev.php'),
    ]);
});

it('phpunit.xml の bootstrap を拾う', function () {
    $root = makeProject([
        'phpunit.xml' => '<?xml version="1.0"?><phpunit bootstrap="tests/bootstrap.php"/>',
        'tests/bootstrap.php' => '<?php',
    ]);

    expect((new GlobalFiles($root))->detect())->toBe([realpath($root . '/tests/bootstrap.php')]);
});

it('phpunit.xml がなければ phpunit.xml.dist を見る', function () {
    $root = makeProject([
        'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit bootstrap="boot.php"/>',
        'boot.php' => '<?php',
    ]);

    expect((new GlobalFiles($root))->detect())->toBe([realpath($root . '/boot.php')]);
});

it('存在しないファイルは無視する', function () {
    $root = makeProject([
        'composer.json' => json_encode(['autoload' => ['files' => ['src/missing.php']]]),
    ]);

    expect((new GlobalFiles($root))->detect())->toBe([]);
});

it('設定ファイルがなければ空を返す', function () {
    expect((new GlobalFiles(makeProject(['src/A.php' => '<?php'])))->detect())->toBe([]);
});

it('壊れた設定ファイルでも落ちない', function () {
    $root = makeProject([
        'composer.json' => '{ not json',
        'phpunit.xml' => '<phpunit',
    ]);

    expect((new GlobalFiles($root))->detect())->toBe([]);
});
