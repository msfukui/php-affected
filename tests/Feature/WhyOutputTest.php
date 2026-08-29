<?php

declare(strict_types=1);

beforeEach(function () {
    $this->sample = fixture('sample');
    $this->bootstrapped = fixture('bootstrapped');
});

describe('--why', function () {
    it('辺の原因になった記号を示す', function () {
        $raw = runCli($this->sample, ['src/Unrelated/Widget.php', '--why'])['raw'];

        expect($raw)
            ->toContain('class App\Unrelated\Widget → src/Unrelated/Widget.php')
            ->toContain('← 指定ファイル');
    });

    it('多段の連鎖の各辺を説明する', function () {
        $raw = runCli($this->sample, ['src/Support/helpers.php', '--why'])['raw'];

        expect($raw)
            ->toContain('class App\Order\Order → src/Order/Order.php')
            ->toContain('class App\Support\Money → src/Support/Money.php')
            ->toContain('function App\Support\format_money()')
            ->toContain('const App\Support\CURRENCY');
    });

    it('require を理由として示す', function () {
        expect(runCli($this->sample, ['container/services.php', '--why'])['raw'])
            ->toContain('require/include → container/services.php');
    });

    it('命名規約由来であることを示す', function () {
        expect(runCli($this->sample, ['src/Detached.php', '--why'])['raw'])
            ->toContain('命名規約による対応付け');
    });

    it('bootstrap 由来であることを示す', function () {
        expect(runCli($this->bootstrapped, ['src/globals.php', '--why'])['raw'])
            ->toContain('全テストが読み込むファイル');
    });

    it('既定では production ファイルの経路も説明する', function () {
        $raw = runCli($this->sample, ['src/Support/helpers.php', '--why'])['raw'];

        expect($raw)
            ->toContain("src/Report/Report.php\n  └─ class App\Support\Money")
            ->toContain("src/Support/helpers.php\n  (指定ファイル自身)")
            ->toContain("tests/OrderTest.php\n  └─ class App\Order\Order");
    });
});

describe('--why=PATH', function () {
    it('指定したファイルだけを説明する', function () {
        $raw = runCli($this->sample, ['--why=tests/OrderTest.php', 'src/Support/helpers.php'])['raw'];

        expect($raw)
            ->toStartWith("tests/OrderTest.php\n")
            ->toContain('class App\Order\Order → src/Order/Order.php')
            ->not->toContain('tests/MoneyTest.php');
    });

    // --tests で絞ったときも、絞られた中間ファイルを追える
    it('--tests で絞られる production ファイルも指定できる', function () {
        $raw = runCli($this->sample, ['--tests', '--why=src/Support/Money.php', 'src/Support/helpers.php'])['raw'];

        expect($raw)
            ->toStartWith("src/Support/Money.php\n")
            ->toContain('function App\Support\format_money()');
    });

    it('production ファイルを指定できる', function () {
        $raw = runCli($this->sample, ['--why=src/Support/Money.php', 'src/Support/helpers.php'])['raw'];

        expect($raw)
            ->toStartWith("src/Support/Money.php\n")
            ->toContain('function App\Support\format_money()');
    });

    it('--tests の有無で結果が変わらない', function () {
        $args = ['--why=src/Order/Order.php', 'src/Support/helpers.php'];

        expect(runCli($this->sample, [...$args, '--tests'])['raw'])
            ->toBe(runCli($this->sample, $args)['raw'])
            ->toContain('src/Order/Order.php');
    });

    it('依存していないファイルは標準出力を空にして知らせる', function () {
        $result = runCli($this->sample, ['--why=tests/WidgetTest.php', 'src/Support/helpers.php']);

        expect(trim($result['raw']))->toBe('')
            ->and($result['err'])->toContain('は指定されたファイルに依存していません')
            ->and($result['code'])->toBe(0);
    });

    it('指定ファイル自身も説明できる', function () {
        expect(runCli($this->sample, ['--why=src/Support/helpers.php', 'src/Support/helpers.php'])['raw'])
            ->toContain('(指定ファイル自身)');
    });

    it('存在しないファイルを指定したらエラーにする', function () {
        $result = runCli($this->sample, ['--why=tests/NoSuchTest.php', 'src/Support/helpers.php']);

        expect($result['err'])->toContain('ファイルが見つかりません')
            ->and($result['code'])->toBe(1);
    });

    it('パスが空ならエラーにする', function () {
        $result = runCli($this->sample, ['--why=', 'src/Support/helpers.php']);

        expect($result['err'])->toContain('ファイルのパスを指定してください')
            ->and($result['code'])->toBe(1);
    });
});
