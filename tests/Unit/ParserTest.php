<?php

declare(strict_types=1);

describe('定義の抽出', function () {
    it('名前空間付きのクラスを小文字の FQN で記録する', function () {
        $parsed = parseCode('<?php namespace App\Domain; class Order {}');

        expect($parsed->classDefs)->toBe(['app\domain\order'])
            ->and($parsed->runnableDefs)->toBe(['app\domain\order']);
    });

    it('表示用に元の大文字小文字を保持する', function () {
        $parsed = parseCode('<?php namespace App\Domain; class Order {}');

        expect($parsed->defNames)->toBe(['App\Domain\Order']);
    });

    it('abstract クラス・interface・trait は実体化できないものとして扱う', function (string $code, array $expected) {
        expect(parseCode($code))
            ->classDefs->toBe(['x'])
            ->runnableDefs->toBe($expected);
    })->with([
        'abstract'  => ['<?php abstract class X {}', []],
        'interface' => ['<?php interface X {}', []],
        'trait'     => ['<?php trait X {}', []],
        'final'     => ['<?php final class X {}', ['x']],
        'readonly'  => ['<?php readonly class X {}', ['x']],
        'enum'      => ['<?php enum X {}', ['x']],
    ]);

    it('Foo::class は定義ではない', function () {
        $parsed = parseCode('<?php namespace App; $name = Order::class;');

        expect($parsed->classDefs)->toBe([])
            ->and($parsed->classRefs)->toContain('app\order');
    });

    it('無名クラスは定義を作らない', function () {
        $parsed = parseCode('<?php namespace App; $x = new class { public function run() {} };');

        expect($parsed->classDefs)->toBe([]);
    });

    it('グローバル関数は記録するがメソッドは記録しない', function () {
        $parsed = parseCode(<<<'PHP'
            <?php
            namespace App;
            function helper(): void {}
            class Service { public function method(): void {} }
            PHP);

        expect($parsed->funcDefs)->toBe(['app\helper']);
    });

    it('関数の中で定義された関数もグローバル関数として扱う', function () {
        $parsed = parseCode('<?php function outer() { function inner() {} }');

        expect($parsed->funcDefs)->toBe(['outer', 'inner']);
    });

    it('トップレベルの const と define() を定数定義として記録する', function () {
        $parsed = parseCode(<<<'PHP'
            <?php
            namespace App;
            const ALPHA = 1, BETA = 2;
            define('GAMMA', 3);
            PHP);

        expect($parsed->constDefs)->toBe(['App\ALPHA', 'App\BETA', 'GAMMA']);
    });

    it('クラス定数はファイル間の記号にならないので記録しない', function () {
        $parsed = parseCode('<?php namespace App; class X { const INNER = 1; }');

        expect($parsed->constDefs)->toBe([]);
    });
});

describe('名前解決', function () {
    it('use のエイリアスを解決する', function () {
        $parsed = parseCode(<<<'PHP'
            <?php
            namespace App;
            use Vendor\Library\Client as Api;
            $x = new Api();
            PHP);

        expect($parsed->classRefs)->toContain('vendor\library\client')
            ->and($parsed->classRefs)->not->toContain('app\api');
    });

    // グループ use を読んだあとに文の終わりで抜けないと、
    // 後続のコード全体を use の項目として読み続けてしまう
    it('グループ use のエイリアスを解決し、後続の宣言を壊さない', function () {
        $parsed = parseCode(<<<'PHP'
            <?php
            namespace App\Report;
            use App\Support\{Money, Formatter as F};

            final class Report
            {
                public function render(Money $money): string
                {
                    return (new F())->wrap($money->label());
                }
            }
            PHP);

        expect($parsed->classDefs)->toBe(['app\report\report'])
            ->and($parsed->classRefs)->toContain('app\support\money')
            ->and($parsed->classRefs)->toContain('app\support\formatter');
    });

    it('use function と use const を解決する', function () {
        $parsed = parseCode(<<<'PHP'
            <?php
            namespace App;
            use function Support\format_money;
            use const Support\CURRENCY;
            echo format_money(1) . CURRENCY;
            PHP);

        expect($parsed->funcRefs)->toContain('support\format_money')
            ->and($parsed->constRefs)->toContain('Support\CURRENCY');
    });

    it('グループ use の中で function と const を混在できる', function () {
        $parsed = parseCode('<?php namespace App; use Support\{Helper, function fmt, const UNIT};');

        expect($parsed->classRefs)->toContain('support\helper')
            ->and($parsed->funcRefs)->toContain('support\fmt')
            ->and($parsed->constRefs)->toContain('Support\UNIT');
    });

    it('完全修飾名と namespace 相対名を解決する', function () {
        $parsed = parseCode('<?php namespace App\Sub; $a = new \Other\Thing(); $b = new namespace\Local();');

        expect($parsed->classRefs)->toContain('other\thing')
            ->and($parsed->classRefs)->toContain('app\sub\local');
    });

    // PHP がグローバルへフォールバックするのは関数と定数だけ。
    // クラスまでフォールバックさせると偽の依存が大量に生まれる
    it('未修飾名のグローバルフォールバックを関数と定数にだけ適用する', function () {
        $parsed = parseCode('<?php namespace App; new Thing(); helper(); echo LIMIT;');

        expect($parsed->classRefs)->toBe(['app\thing'])
            ->and($parsed->funcRefs)->toBe(['app\helper', 'helper'])
            ->and($parsed->anyRefs)->toContain('App\LIMIT')
            ->and($parsed->globalRefs)->toContain('LIMIT');
    });

    it('名前空間のないファイルではフォールバック候補を作らない', function () {
        $parsed = parseCode('<?php helper();');

        expect($parsed->funcRefs)->toBe(['helper'])
            ->and($parsed->globalRefs)->toBe([]);
    });

    it('namespace 宣言ごとに use の表をリセットする', function () {
        $parsed = parseCode(<<<'PHP'
            <?php
            namespace A { use Vendor\Thing; $x = new Thing(); }
            namespace B { $y = new Thing(); }
            PHP);

        expect($parsed->classRefs)->toContain('vendor\thing')
            ->and($parsed->classRefs)->toContain('b\thing');
    });
});

describe('参照の抽出', function () {
    it('継承元を参照かつ親として記録する', function () {
        $parsed = parseCode('<?php namespace App; class X extends Base implements Alpha, Beta {}');

        expect($parsed->parents)->toBe(['app\base', 'app\alpha', 'app\beta'])
            ->and($parsed->classRefs)->toContain('app\base');
    });

    // DI で注入されるのは通常 interface なので、起点を広げる対象を interface に限る。
    // 基底クラスまで広げると影響範囲が跳ね上がる (laravel で 1 件 -> 983 件)
    it('implements 先だけを interface として記録する', function () {
        $parsed = parseCode('<?php namespace App; class X extends Base implements Alpha, Beta {}');

        expect($parsed->parents)->toBe(['app\base', 'app\alpha', 'app\beta'])
            ->and($parsed->interfaces)->toBe(['app\alpha', 'app\beta']);
    });

    it('interface の extends 先も interface として記録する', function () {
        $parsed = parseCode('<?php namespace App; interface X extends Alpha, Beta {}');

        expect($parsed->interfaces)->toBe(['app\alpha', 'app\beta']);
    });

    it('クラス本体の use は trait の参照として扱う', function () {
        $parsed = parseCode('<?php namespace App; class X { use Loggable, Cacheable; }');

        expect($parsed->classRefs)->toContain('app\loggable')
            ->and($parsed->classRefs)->toContain('app\cacheable');
    });

    it('クロージャの use は変数なので依存にしない', function () {
        $parsed = parseCode('<?php namespace App; $f = function () use ($captured) { return $captured; };');

        expect($parsed->classRefs)->toBe([]);
    });

    it('catch の型を参照として拾う', function () {
        $parsed = parseCode('<?php namespace App; try { x(); } catch (FirstError | SecondError $e) {}');

        expect($parsed->classRefs)->toContain('app\firsterror')
            ->and($parsed->classRefs)->toContain('app\seconderror');
    });

    it('属性を参照として拾う', function () {
        $parsed = parseCode('<?php namespace App; #[Route("/x")] class X {}');

        expect($parsed->classRefs)->toContain('app\route');
    });

    it('docblock の型を参照として拾う', function () {
        $parsed = parseCode(<<<'PHP'
            <?php
            namespace App;
            class X {
                /**
                 * @param \Support\Money $money
                 * @return Alpha|Beta
                 * @throws \RuntimeError
                 */
                public function run($money) {}
            }
            PHP);

        expect($parsed->classRefs)
            ->toContain('support\money')
            ->toContain('app\alpha')
            ->toContain('app\beta')
            ->toContain('runtimeerror');
    });

    it('メソッド呼び出しやプロパティは記号として扱わない', function () {
        $parsed = parseCode('<?php namespace App; $o->method(); $o?->prop; Holder::member();');

        expect($parsed->classRefs)->toBe(['app\holder'])
            ->and($parsed->funcRefs)->toBe([])
            ->and($parsed->anyRefs)->toBe([]);
    });

    it('名前付き引数を記号として扱わない', function () {
        $parsed = parseCode('<?php namespace App; render(width: 10, height: 20);');

        expect($parsed->anyRefs)->toBe([])
            ->and($parsed->funcRefs)->toBe(['app\render', 'render']);
    });

    it('組み込みの型名を参照にしない', function () {
        $parsed = parseCode('<?php namespace App; function f(int $a, string $b): bool { return true; }');

        expect($parsed->classRefs)->toBe([])
            ->and($parsed->anyRefs)->toBe([]);
    });
});

describe('文字列リテラル', function () {
    // DI コンテナや設定配列にクラス名を文字列で書く形を拾う
    it('名前空間区切りを含む文字列をクラス参照の候補にする', function () {
        $parsed = parseCode(<<<'PHP'
            <?php
            return ['gateway' => 'App\Payment\StripeGateway'];
            PHP);

        expect($parsed->strings)->toBe(['app\payment\stripegateway']);
    });

    // 区切りのない文字列まで拾うと、設定値やメッセージと区別がつかず誤検出が増える
    it('名前空間区切りのない文字列は拾わない', function () {
        $parsed = parseCode('<?php $a = "User"; $b = "処理に失敗しました"; $c = "a/b/c";');

        expect($parsed->strings)->toBe([]);
    });

    // 表記は揺れるが同じクラスを指す。二重引用符に stripcslashes() を使うと
    // "App\Money" の \M が落ちて AppMoney になり、クラス名として認識できなくなる
    it('引用符とエスケープの違いを吸収して揃える', function () {
        $parsed = parseCode(<<<'PHP'
            <?php
            $a = '\App\Money';
            $b = "App\\Money";
            $c = "App\Money";
            PHP);

        expect($parsed->strings)->toBe(['app\money']);
    });
});

describe('require / include のパス解決', function () {
    it('__DIR__ との連結を解決する', function () {
        $root = makeProject([
            'app/main.php' => "<?php require __DIR__ . '/lib.php';",
            'app/lib.php' => '<?php',
        ]);
        $file = $root . '/app/main.php';

        expect(parseCode((string) file_get_contents($file), $file)->includes)
            ->toBe([realpath($root . '/app/lib.php')]);
    });

    it('dirname(__DIR__, 2) を解決する', function () {
        $root = makeProject([
            'a/b/c/main.php' => "<?php require_once dirname(__DIR__, 2) . '/target.php';",
            'a/target.php' => '<?php',
        ]);
        $file = $root . '/a/b/c/main.php';

        expect(parseCode((string) file_get_contents($file), $file)->includes)
            ->toBe([realpath($root . '/a/target.php')]);
    });

    it('変数を含む動的な require は諦める', function () {
        $root = makeProject(['main.php' => '<?php require $path . "/x.php";']);
        $file = $root . '/main.php';

        expect(parseCode((string) file_get_contents($file), $file)->includes)->toBe([]);
    });

    it('存在しないパスは辺にしない', function () {
        $root = makeProject(['main.php' => "<?php require __DIR__ . '/missing.php';"]);
        $file = $root . '/main.php';

        expect(parseCode((string) file_get_contents($file), $file)->includes)->toBe([]);
    });
});
