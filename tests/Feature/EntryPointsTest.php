<?php

declare(strict_types=1);

/**
 * --entry で宣言した「実行単位の入口」のうち、どれが影響を受けるか。
 *
 * 逆探索の結果を絞り込むだけで、依存グラフには手を加えない。
 * テストの選択結果は --entry の有無で変わらない。
 */

beforeEach(function () {
    $this->entry = fixture('entry');
});

describe('--entry', function () {
    it('影響が届いた入口だけを出力する', function () {
        // ReportService は ImportCommand 経由で CLI の入口から辿れる
        expect(runCli($this->entry, ['--entry=public/index.php', '--entry=bin/console.php', 'src/Service/ReportService.php'])['out'])
            ->toEqualCanonicalizing(['bin/console.php']);
    });

    it('複数の入口が影響を受ければすべて出力する', function () {
        expect(runCli($this->entry, [
            '--entry=public/index.php',
            '--entry=bin/console.php',
            'src/Service/ReportService.php',
            'src/Kernel.php',
        ])['out'])->toEqualCanonicalizing(['bin/console.php', 'public/index.php']);
    });

    it('入口そのものを変更した場合もその入口を出力する', function () {
        expect(runCli($this->entry, ['--entry=public/index.php', '--entry=bin/console.php', 'public/index.php'])['out'])
            ->toEqualCanonicalizing(['public/index.php']);
    });

    // 実行時にパスを組み立てて読み込むファイル (routes/web.php) でグラフが切れるため、
    // 実際には Web の入口が影響を受けるのに何も出力されない。
    // 「出力された入口は影響あり」は信頼できるが、その逆は保証できないことを固定しておく
    it('動的な読み込みで連鎖が切れていると入口に到達できない', function () {
        $result = runCli($this->entry, ['--entry=public/index.php', 'src/Http/HomeController.php']);

        expect($result['out'])->toBe([])
            ->and($result['code'])->toBe(0);
    });

    // 上の穴は、実行時に読み込まれるファイル自体を入口として宣言すれば塞げる
    it('実行時に読み込まれるファイルを入口として宣言できる', function () {
        expect(runCli($this->entry, ['--entry=routes/web.php', 'src/Http/HomeController.php'])['out'])
            ->toEqualCanonicalizing(['routes/web.php']);
    });

    it('--why と併用すると入口までの経路を示す', function () {
        expect(runCli($this->entry, ['--entry=bin/console.php', '--why', 'src/Service/ReportService.php'])['raw'])
            ->toContain('bin/console.php')
            ->toContain('class App\Service\ReportService')
            ->toContain('← 指定ファイル');
    });

    it('--stats に宣言した入口の件数を出す', function () {
        expect(runCli($this->entry, ['--entry=public/index.php', '--entry=bin/console.php', '--stats', 'src/Service/ReportService.php'])['err'])
            ->toContain('影響: 指定 1 件 → 到達 6 件 → 出力 1 件 (宣言した実行単位 2 件中)');
    });

    it('テストの選択結果には影響しない', function () {
        expect(runCli($this->entry, ['--tests', 'src/Service/ReportService.php'])['out'])
            ->toEqualCanonicalizing(['tests/Unit/ReportServiceTest.php']);
    });
});

describe('--entry の入力の扱い', function () {
    it('見つからない入口は警告して残りを処理する', function () {
        $result = runCli($this->entry, ['--entry=public/nope.php', '--entry=bin/console.php', 'src/Service/ReportService.php']);

        expect($result['err'])->toContain('ファイルが見つかりません: public/nope.php')
            ->and($result['out'])->toBe(['bin/console.php'])
            ->and($result['code'])->toBe(0);
    });

    it('依存グラフに含まれない入口は警告して無視する', function () {
        $root = makeProject([
            'src/Service.php' => "<?php\nnamespace App;\nfinal class Service {}\n",
            'bin/worker.sh' => "#!/bin/sh\nexec php worker\n",
        ]);
        $result = runCli($root, ['--entry=bin/worker.sh', 'src/Service.php']);

        expect($result['err'])->toContain('依存グラフに含まれないため無視します: bin/worker.sh')
            ->and($result['code'])->toBe(0);
    });

    it('解析できる入口が 1 つもなければ何も出力しない', function () {
        $result = runCli($this->entry, ['--entry=public/nope.php', 'src/Service/ReportService.php']);

        expect($result['err'])->toContain('実行単位として解析できるファイルがありません。')
            ->and($result['out'])->toBe([])
            ->and($result['code'])->toBe(0);
    });

    it('パスのない --entry= はエラーにする', function () {
        $result = runCli($this->entry, ['--entry=', 'src/Kernel.php']);

        expect($result['err'])->toContain('--entry= にはファイルのパスを指定してください')
            ->and($result['code'])->toBe(1);
    });

    // 出力するものが「テスト」と「実行単位」で食い違うので、黙って片方を捨てない
    it('--tests とは併用できない', function () {
        $result = runCli($this->entry, ['--entry=public/index.php', '--tests', 'src/Kernel.php']);

        expect($result['err'])->toContain('--entry と --tests は同時に指定できません')
            ->and($result['code'])->toBe(1);
    });

    it('--why=PATH とは併用できない', function () {
        $result = runCli($this->entry, ['--entry=public/index.php', '--why=bin/console.php', 'src/Kernel.php']);

        expect($result['err'])->toContain('--entry と --why=PATH は同時に指定できません')
            ->and($result['code'])->toBe(1);
    });
});
