<?php

declare(strict_types=1);

use PhpAffected\Scanner;

it('php ファイルだけを昇順で列挙する', function () {
    $root = makeProject([
        'src/nested/B.php' => '<?php',
        'src/A.php' => '<?php',
        'README.md' => '#',
        'src/notes.txt' => 'x',
    ]);

    expect(relativePaths($root, (new Scanner($root))->scan()))
        ->toBe(['src/A.php', 'src/nested/B.php']);
});

it('依存や成果物のディレクトリを除外する', function (string $excluded) {
    $root = makeProject([
        'src/Keep.php' => '<?php',
        $excluded . '/Skip.php' => '<?php',
    ]);

    expect(relativePaths($root, (new Scanner($root))->scan()))->toBe(['src/Keep.php']);
})->with([
    'vendor',
    'node_modules',
    'build',
    'var/cache',
    'storage/framework',
    'src/vendor',            // 入れ子の vendor も除外する
]);

it('ルートからの相対パスに変換する', function () {
    $scanner = new Scanner('/project');

    expect($scanner->relative('/project/src/A.php'))->toBe('src/A.php')
        ->and($scanner->relative('/elsewhere/B.php'))->toBe('/elsewhere/B.php');
});

it('ルートが存在しなければ空を返す', function () {
    expect((new Scanner('/no/such/directory'))->scan())->toBe([]);
});

/**
 * @param  list<string> $paths
 * @return list<string>
 */
function relativePaths(string $root, array $paths): array
{
    return array_map(static fn(string $p): string => substr($p, strlen($root) + 1), $paths);
}
