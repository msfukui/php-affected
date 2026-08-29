<?php

declare(strict_types=1);

use PhpAffected\Graph;
use PhpAffected\ParsedFile;

/** @param array<string, mixed> $fields */
function parsedFile(string $path, array $fields = []): ParsedFile
{
    $parsed = new ParsedFile($path);
    foreach ($fields as $name => $value) {
        $parsed->$name = $value;
    }

    return $parsed;
}

describe('依存辺の構築', function () {
    it('クラス・関数・定数の参照から辺を張る', function () {
        $graph = graphOf([
            '/p/Def.php' => parsedFile('/p/Def.php', [
                'classDefs' => ['app\thing'],
                'funcDefs' => ['app\helper'],
                'constDefs' => ['App\LIMIT'],
            ]),
            '/p/UsesClass.php' => parsedFile('/p/UsesClass.php', ['classRefs' => ['app\thing']]),
            '/p/UsesFunc.php' => parsedFile('/p/UsesFunc.php', ['funcRefs' => ['app\helper']]),
            '/p/UsesConst.php' => parsedFile('/p/UsesConst.php', ['constRefs' => ['App\LIMIT']]),
            '/p/Unrelated.php' => parsedFile('/p/Unrelated.php', ['classRefs' => ['app\other']]),
        ]);

        expect($graph->forward())
            ->toHaveKey('/p/UsesClass.php', ['/p/Def.php'])
            ->toHaveKey('/p/UsesFunc.php', ['/p/Def.php'])
            ->toHaveKey('/p/UsesConst.php', ['/p/Def.php'])
            ->toHaveKey('/p/Unrelated.php', []);
    });

    it('種別が確定しない参照は全種別の索引に照合する', function () {
        $graph = graphOf([
            '/p/Class.php' => parsedFile('/p/Class.php', ['classDefs' => ['app\ambiguous']]),
            '/p/Const.php' => parsedFile('/p/Const.php', ['constDefs' => ['App\Ambiguous']]),
            '/p/User.php' => parsedFile('/p/User.php', ['anyRefs' => ['App\Ambiguous']]),
        ]);

        expect($graph->forward()['/p/User.php'])
            ->toContain('/p/Class.php')
            ->toContain('/p/Const.php');
    });

    // PHP は名前空間内の未修飾クラス名をグローバルへフォールバックしない
    it('グローバルフォールバック候補はクラスの索引に当てない', function () {
        $graph = graphOf([
            '/p/Class.php' => parsedFile('/p/Class.php', ['classDefs' => ['thing']]),
            '/p/Func.php' => parsedFile('/p/Func.php', ['funcDefs' => ['thing']]),
            '/p/User.php' => parsedFile('/p/User.php', ['globalRefs' => ['thing']]),
        ]);

        expect($graph->forward()['/p/User.php'])->toBe(['/p/Func.php']);
    });

    it('require の相手にも辺を張る', function () {
        $graph = graphOf([
            '/p/Lib.php' => parsedFile('/p/Lib.php'),
            '/p/Main.php' => parsedFile('/p/Main.php', ['includes' => ['/p/Lib.php', '/p/Outside.php']]),
        ]);

        // 走査対象外のファイルは辺にしない
        expect($graph->forward()['/p/Main.php'])->toBe(['/p/Lib.php']);
    });

    it('自分自身への参照は辺にしない', function () {
        $graph = graphOf([
            '/p/A.php' => parsedFile('/p/A.php', ['classDefs' => ['a'], 'classRefs' => ['a']]),
        ]);

        expect($graph->forward()['/p/A.php'])->toBe([]);
    });

    it('逆方向の辺を作る', function () {
        $graph = graphOf([
            '/p/A.php' => parsedFile('/p/A.php', ['classDefs' => ['a']]),
            '/p/B.php' => parsedFile('/p/B.php', ['classRefs' => ['a']]),
            '/p/C.php' => parsedFile('/p/C.php', ['classRefs' => ['a']]),
        ]);

        expect($graph->reverse()['/p/A.php'])->toBe(['/p/B.php', '/p/C.php']);
    });
});

/** C.php -> B.php -> A.php という一本鎖 (矢印は依存の向き)。 */
function chainGraph(): Graph
{
    return graphOf([
        '/p/A.php' => parsedFile('/p/A.php', ['classDefs' => ['a']]),
        '/p/B.php' => parsedFile('/p/B.php', ['classDefs' => ['b'], 'classRefs' => ['a']]),
        '/p/C.php' => parsedFile('/p/C.php', ['classRefs' => ['b']]),
        '/p/D.php' => parsedFile('/p/D.php'),
    ]);
}

describe('影響の探索', function () {
    it('指定ファイルから逆方向に推移的にたどる', function () {
        ['depth' => $depth] = chainGraph()->impacted(['/p/A.php']);

        expect($depth)->toBe(['/p/A.php' => 0, '/p/B.php' => 1, '/p/C.php' => 2]);
    });

    it('選ばれた経路の親を記録する', function () {
        ['from' => $from] = chainGraph()->impacted(['/p/A.php']);

        expect($from)->toBe([
            '/p/A.php' => null,
            '/p/B.php' => '/p/A.php',
            '/p/C.php' => '/p/B.php',
        ]);
    });

    it('複数のファイルを同時に起点にできる', function () {
        ['depth' => $depth] = chainGraph()->impacted(['/p/A.php', '/p/D.php']);

        expect($depth)->toHaveKey('/p/D.php', 0)->toHaveKey('/p/C.php', 2);
    });
});

describe('暗黙の依存辺', function () {
    it('コード上に現れない辺を追加できる', function () {
        $graph = graphOf([
            '/p/Bootstrap.php' => parsedFile('/p/Bootstrap.php'),
            '/p/Test.php' => parsedFile('/p/Test.php'),
        ]);
        expect($graph->impacted(['/p/Bootstrap.php'])['depth'])->not->toHaveKey('/p/Test.php');

        $graph->addImplicitEdges(['/p/Test.php' => ['/p/Bootstrap.php']]);

        // 追加後は memo が破棄され、辺が反映される
        expect($graph->impacted(['/p/Bootstrap.php'])['depth'])->toHaveKey('/p/Test.php', 1);
    });
});

describe('辺の理由', function () {
    it('原因になった記号を元の表記で返す', function () {
        $graph = graphOf([
            '/p/Def.php' => parsedFile('/p/Def.php', [
                'classDefs' => ['app\money'],
                'funcDefs' => ['app\format'],
                'constDefs' => ['App\UNIT'],
                'defNames' => ['App\Money', 'App\format'],
            ]),
            '/p/User.php' => parsedFile('/p/User.php', [
                'classRefs' => ['app\money'],
                'funcRefs' => ['app\format'],
                'constRefs' => ['App\UNIT'],
            ]),
        ]);

        expect($graph->edgeReasons('/p/User.php', '/p/Def.php'))
            ->toBe(['class App\Money', 'function App\format()', 'const App\UNIT']);
    });

    it('require と暗黙の辺も理由として説明する', function () {
        $graph = graphOf([
            '/p/Lib.php' => parsedFile('/p/Lib.php'),
            '/p/Main.php' => parsedFile('/p/Main.php', ['includes' => ['/p/Lib.php']]),
            '/p/Test.php' => parsedFile('/p/Test.php'),
        ]);
        $graph->addImplicitEdges(['/p/Test.php' => ['/p/Lib.php']]);

        expect($graph->edgeReasons('/p/Main.php', '/p/Lib.php'))->toBe(['require/include'])
            ->and($graph->edgeReasons('/p/Test.php', '/p/Lib.php'))
            ->toBe(['全テストが読み込むファイル (bootstrap 等)']);
    });

    it('依存していない組では空を返す', function () {
        $graph = graphOf([
            '/p/A.php' => parsedFile('/p/A.php', ['classDefs' => ['a']]),
            '/p/B.php' => parsedFile('/p/B.php'),
        ]);

        expect($graph->edgeReasons('/p/B.php', '/p/A.php'))->toBe([]);
    });
});
