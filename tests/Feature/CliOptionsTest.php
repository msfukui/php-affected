<?php

declare(strict_types=1);

beforeEach(function () {
    $this->sample = fixture('sample');
});

describe('出力の既定と --tests', function () {
    it('既定では影響を受ける全ファイルを出力する', function () {
        expect(runCli($this->sample, ['src/Support/helpers.php'])['out'])
            ->toEqualCanonicalizing([
                'src/Order/Order.php',
                'src/Report/Report.php',
                'src/Support/Money.php',
                'src/Support/helpers.php',
                'tests/MoneyTest.php',
                'tests/OrderTest.php',
                'tests/ReportTest.php',
            ]);
    });

    it('--tests で実行対象のテストだけに絞る', function () {
        expect(runCli($this->sample, ['src/Support/helpers.php', '--tests'])['out'])
            ->toEqualCanonicalizing([
                'tests/MoneyTest.php',
                'tests/OrderTest.php',
                'tests/ReportTest.php',
            ]);
    });
});

describe('--stats', function () {
    it('プロジェクト統計と影響統計を stderr に出す', function () {
        $result = runCli($this->sample, ['src/Support/helpers.php', '--stats']);

        expect($result['err'])
            ->toContain('プロジェクト: ファイル 23 件 / 依存辺 25 / 対象テスト 8 件')
            ->toContain('影響: 指定 1 件 → 到達 7 件 → 出力 7 件');
    });

    it('標準出力の内容を変えない', function () {
        expect(runCli($this->sample, ['src/Support/helpers.php', '--stats'])['out'])
            ->toEqualCanonicalizing(runCli($this->sample, ['src/Support/helpers.php'])['out']);
    });

    // プロジェクト統計は指定ファイルに依存しないので単独で出せる
    it('ファイルを指定しなくてもプロジェクト統計だけ出せる', function () {
        $result = runCli($this->sample, ['--stats']);

        expect($result['err'])
            ->toContain('プロジェクト: ファイル 23 件 / 依存辺 25 / 対象テスト 8 件')
            ->not->toContain('影響:');
        expect(trim($result['raw']))->toBe('')
            ->and($result['code'])->toBe(0);
    });

    // 選択率はテストに絞ったときだけ意味を持つ
    it('--tests のときだけ選択率を出す', function () {
        expect(runCli($this->sample, ['src/Support/helpers.php', '--tests', '--stats'])['err'])
            ->toContain('影響: 指定 1 件 → 到達 7 件 → 出力 3 件 (テスト全体の 38%)');

        expect(runCli($this->sample, ['src/Support/helpers.php', '--stats'])['err'])
            ->not->toContain('テスト全体の');
    });
});

describe('引数の扱い', function () {
    it('引数がなければ使い方を表示する', function () {
        $result = runCli($this->sample, []);

        expect($result['raw'])->toContain('使い方:')
            ->and($result['code'])->toBe(0);
    });

    it('存在しないファイルは警告して続行する', function () {
        $result = runCli($this->sample, ['src/NoSuchFile.php']);

        expect($result['err'])->toContain('ファイルが見つかりません')
            ->and($result['code'])->toBe(0);
    });

    it('不明なオプションはエラーにする', function () {
        $result = runCli($this->sample, ['--nope', 'src/Detached.php']);

        expect($result['err'])->toContain('不明なオプション')
            ->and($result['code'])->toBe(1);
    });

    it('--help を表示する', function () {
        $result = runCli($this->sample, ['--help']);

        expect($result['raw'])->toContain('--tests')
            ->and($result['code'])->toBe(0);
    });
});
