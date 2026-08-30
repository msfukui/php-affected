<?php

declare(strict_types=1);

/**
 * ファイルを指定したときに、どのテストが選ばれるか。
 * ツールの中心的な振る舞いなので実際に CLI を起動して確かめる。
 *
 * 既定の出力は「影響を受ける全ファイル」なので、テストの選択を見る場合は
 * --tests を付ける。既定の動作そのものは CliOptionsTest で確かめている。
 */

beforeEach(function () {
    $this->sample = fixture('sample');
    $this->bootstrapped = fixture('bootstrapped');
    $this->monorepo = fixture('monorepo');
});

it('末端クラスを変更したら対応するテストだけを選ぶ', function () {
    expect(runCli($this->sample, ['src/Unrelated/Widget.php', '--tests'])['out'])
        ->toEqualCanonicalizing(['tests/WidgetTest.php']);
});

it('関数と定数の変更が推移的に波及する', function () {
    // helpers.php -> Money (use function/use const) -> Order (docblock) / Report (group use)
    expect(runCli($this->sample, ['src/Support/helpers.php', '--tests'])['out'])
        ->toEqualCanonicalizing([
            'tests/MoneyTest.php',
            'tests/OrderTest.php',
            'tests/ReportTest.php',
        ]);
});

it('interface の変更が実装クラスと利用側の両方に波及する', function () {
    // 実装クラス StripeGateway 経由で container のテストにも届く
    expect(runCli($this->sample, ['src/Contract/PaymentGateway.php', '--tests'])['out'])
        ->toEqualCanonicalizing(['tests/ContainerTest.php', 'tests/PaymentServiceTest.php']);
});

it('名前空間のないレガシーコードを require チェーン越しに追う', function () {
    expect(runCli($this->sample, ['src/Legacy/legacy_util.php', '--tests'])['out'])
        ->toEqualCanonicalizing(['tests/LegacyTest.php']);
});

it('require でしか到達しないファイルも追う', function () {
    expect(runCli($this->sample, ['container/services.php', '--tests'])['out'])
        ->toEqualCanonicalizing(['tests/ContainerTest.php']);
});

it('グループ use のエイリアス経由の依存を追う', function () {
    expect(runCli($this->sample, ['src/Support/Formatter.php', '--tests'])['out'])
        ->toEqualCanonicalizing(['tests/ReportTest.php']);
});

it('テストの基底クラスを変更したら全テストを選ぶが基底自体は出さない', function () {
    expect(runCli($this->sample, ['tests/Support/BaseTestCase.php', '--tests'])['out'])
        ->toEqualCanonicalizing([
            'tests/ContainerTest.php',
            'tests/DetachedTest.php',
            'tests/LegacyTest.php',
            'tests/MoneyTest.php',
            'tests/OrderTest.php',
            'tests/PaymentServiceTest.php',
            'tests/ReportTest.php',
            'tests/WidgetTest.php',
        ]);
});

it('静的な参照がなくても命名規約で対応付ける', function () {
    expect(runCli($this->sample, ['src/Detached.php', '--tests'])['out'])
        ->toEqualCanonicalizing(['tests/DetachedTest.php']);
});

describe('DI コンテナ経由でしか参照されない実装クラス', function () {
    // StripeGateway はコンテナに文字列で登録されているだけで、
    // 利用側の PaymentService は interface しか型宣言していない。
    // 文字列リテラルの照合と、継承元への起点拡張の 2 経路で到達する
    it('文字列リテラルと継承元の両方をたどって到達する', function () {
        expect(runCli($this->sample, ['src/Payment/StripeGateway.php', '--tests'])['out'])
            ->toEqualCanonicalizing(['tests/ContainerTest.php', 'tests/PaymentServiceTest.php']);
    });

    it('文字列リテラル経由であることを説明できる', function () {
        expect(runCli($this->sample, ['src/Payment/StripeGateway.php', '--why'])['raw'])
            ->toContain('class App\Payment\StripeGateway (文字列リテラル) → src/Payment/StripeGateway.php');
    });

    it('interface をたどった起点であることを説明できる', function () {
        expect(runCli($this->sample, ['src/Payment/StripeGateway.php', '--why'])['raw'])
            ->toContain("src/Contract/PaymentGateway.php\n  (指定ファイルの interface)")
            ->toContain('← 指定ファイルの interface');
    });
});

describe('全テストが読み込むファイル', function () {
    it('composer の autoload.files の変更は全テストに波及する', function () {
        expect(runCli($this->bootstrapped, ['src/globals.php', '--tests'])['out'])
            ->toEqualCanonicalizing(['tests/OtherTest.php', 'tests/ThingTest.php']);
    });

    it('phpunit の bootstrap が依存するクラスも全テストに波及する', function () {
        expect(runCli($this->bootstrapped, ['src/BootSupport.php', '--tests'])['out'])
            ->toEqualCanonicalizing(['tests/OtherTest.php', 'tests/ThingTest.php']);
    });

    it('通常のクラスは絞り込まれたままにする', function () {
        expect(runCli($this->bootstrapped, ['src/Thing.php', '--tests'])['out'])
            ->toEqualCanonicalizing(['tests/ThingTest.php']);
    });
});

describe('モノレポ (サブディレクトリに設定ファイルがある)', function () {
    it('ルートの composer.json は全パッケージのテストに波及する', function () {
        expect(runCli($this->monorepo, ['shared/global_helpers.php', '--tests'])['out'])
            ->toEqualCanonicalizing([
                'packages/alpha/tests/AlphaTest.php',
                'packages/beta/tests/BetaTest.php',
            ]);
    });

    // 設定ファイルが置かれたディレクトリ配下にだけ効く。
    // 全体に効くことにすると、モノレポでは 1 パッケージの変更で全テストが選ばれてしまう
    it('パッケージの composer.json はそのパッケージのテストにだけ波及する', function () {
        expect(runCli($this->monorepo, ['packages/alpha/src/alpha_helpers.php', '--tests'])['out'])
            ->toEqualCanonicalizing(['packages/alpha/tests/AlphaTest.php']);
    });

    it('パッケージの phpunit.xml の bootstrap も同様に絞られる', function () {
        expect(runCli($this->monorepo, ['packages/beta/src/BetaSupport.php', '--tests'])['out'])
            ->toEqualCanonicalizing(['packages/beta/tests/BetaTest.php']);
    });

    it('通常のクラスは従来どおり静的な参照で追う', function () {
        expect(runCli($this->monorepo, ['packages/alpha/src/Alpha.php', '--tests'])['out'])
            ->toEqualCanonicalizing(['packages/alpha/tests/AlphaTest.php']);
    });
});
