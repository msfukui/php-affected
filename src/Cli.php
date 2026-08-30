<?php

declare(strict_types=1);

namespace PhpAffected;

final class Cli
{
    private const USAGE = <<<TXT
    php-affected — 指定されたある PHP ファイル群に依存している PHP ファイルを列挙する

    使い方:
      php-affected [オプション] <ファイル> [<ファイル>...]

    既定では影響を受けるプロジェクトルート配下の全 PHP ファイルを出力する
    対象をテストファイルのみに絞るには --tests を指定する

    オプション:
      --root=DIR    プロジェクトルート (既定: カレントディレクトリ)
      --tests       対象をテストファイルだけに絞る
      --why         選ばれた理由の連鎖を表示
      --why=PATH    PATH 1 つだけについて理由の連鎖を表示する
      --stats       統計を stderr に出力
      -h, --help    このヘルプ

    例:
      php-affected src/Payment/Gateway.php
      php-affected --tests src/Payment/Gateway.php
      php-affected --stats
      php-affected --why src/Support/helpers.php
      php-affected --why=tests/OrderTest.php src/Support/helpers.php
      php-affected --tests --root=/path/to/project $(git diff --name-only)
    TXT;

    private string $root = '';
    private bool $tests = false;
    private bool $why = false;
    private ?string $whyTarget = null;
    private bool $stats = false;
    /** @var list<string> */
    private array $inputs = [];

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        try {
            if (!$this->parseArgs($argv)) {
                return 0;
            }
            return $this->execute();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'エラー: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /**
     * @param  list<string> $argv
     * @return bool 続行するなら true (ヘルプ表示のみなら false)
     */
    private function parseArgs(array $argv): bool
    {
        $this->root = (string) getcwd();

        foreach (array_slice($argv, 1) as $arg) {
            switch (true) {
                case $arg === '-h' || $arg === '--help':
                    echo self::USAGE, PHP_EOL;
                    return false;
                case $arg === '--tests':
                    $this->tests = true;
                    break;
                case $arg === '--why':
                    $this->why = true;
                    break;
                case str_starts_with($arg, '--why='):
                    $this->why = true;
                    $this->whyTarget = substr($arg, 6);
                    if ($this->whyTarget === '') {
                        throw new \RuntimeException('--why= にはファイルのパスを指定してください');
                    }
                    break;
                case $arg === '--stats':
                    $this->stats = true;
                    break;
                case str_starts_with($arg, '--root='):
                    $root = realpath(substr($arg, 7));
                    if ($root === false) {
                        throw new \RuntimeException('ディレクトリが見つかりません: ' . substr($arg, 7));
                    }
                    $this->root = $root;
                    break;
                case str_starts_with($arg, '-'):
                    throw new \RuntimeException("不明なオプション: {$arg}");
                default:
                    $this->inputs[] = $arg;
            }
        }

        // --stats だけならプロジェクト全体の統計を出すのでファイルの指定は要らない
        if ($this->inputs === [] && !$this->stats) {
            echo self::USAGE, PHP_EOL;
            return false;
        }

        return true;
    }

    private function execute(): int
    {
        $statsOnly = $this->inputs === [];

        $specified = $statsOnly ? [] : $this->resolveSpecifiedFiles();
        if (!$statsOnly && $specified === []) {
            return 0;
        }

        $scanner = new Scanner($this->root);
        $files = $scanner->scan();
        $graph = new Graph($this->parseAll($files));
        $known = $graph->files();

        // bootstrap 等は全テストが読み込むので、テスト -> bootstrap という依存を先に張る
        // 逆探索より前に張らないと bootstrap 経由の影響が伝わらない
        $globals = array_values(array_filter(
            (new GlobalFiles($scanner))->detect(),
            static fn(array $global): bool => isset($known[$global['path']]),
        ));
        $detector = new TestDetector($scanner, $graph, array_column($globals, 'path'));
        if ($globals !== []) {
            $edges = [];
            foreach (array_keys($known) as $path) {
                if (!$detector->isTest($path)) {
                    continue;
                }
                // 設定ファイルが効くのは、それが置かれたディレクトリ配下のテストだけ。
                // モノレポで 1 パッケージの bootstrap が全テストを巻き込まないようにする
                $applicable = [];
                foreach ($globals as $global) {
                    if (str_starts_with($path, $global['scope'] . '/')) {
                        $applicable[] = $global['path'];
                    }
                }
                if ($applicable !== []) {
                    $edges[$path] = $applicable;
                }
            }
            $graph->addImplicitEdges($edges);
        }

        $testCount = 0;
        if ($this->stats) {
            foreach (array_keys($known) as $path) {
                if ($detector->isRunnableTest($path)) {
                    $testCount++;
                }
            }
            $this->note(sprintf(
                'プロジェクト: ファイル %d 件 / ファイル間の依存 %d / 対象テスト %d 件',
                count($known),
                $this->countEdges($graph),
                $testCount,
            ));
        }
        if ($statsOnly) {
            return 0;
        }

        $seeds = [];
        foreach ($specified as $path) {
            if (isset($known[$path])) {
                $seeds[] = $path;
            } else {
                $this->note('警告: 依存グラフに含まれないため無視します: ' . $scanner->relative($path));
            }
        }
        if ($seeds === []) {
            $this->note('解析対象のファイルがありません。');
            return 0;
        }

        ['depth' => $depth, 'from' => $from] = $graph->impacted($seeds);

        // 静的な参照がなくても Foo.php <-> FooTest.php は対応付ける
        foreach ($detector->pairByName($specified, $files) as $paired) {
            if (!isset($depth[$paired])) {
                $depth[$paired] = 1;
                $from[$paired] = null; // 命名規約由来であることを null で表す
            }
        }

        $selected = [];
        foreach ($depth as $path => $distance) {
            if (!$this->tests || $detector->isRunnableTest($path)) {
                $selected[$path] = $distance;
            }
        }
        // 距離が近い順、同距離ならパス順に安定させる
        uksort($selected, fn(string $a, string $b): int => [$selected[$a], $a] <=> [$selected[$b], $b]);

        if ($this->stats) {
            $this->note(sprintf(
                '影響: 指定 %d 件 → 到達 %d 件 → 出力 %d 件%s',
                count($specified),
                count($depth),
                count($selected),
                // 選択率は「このリポジトリで導入する価値があるか」の目安になる
                // 出力にテスト以外が混ざる既定の動作では意味を成さないので --tests のときだけ出す
                $this->tests && $testCount > 0
                    ? sprintf(' (テスト全体の %d%%)', (int) round(count($selected) * 100 / $testCount))
                    : '',
            ));
        }

        if (!$this->why) {
            foreach (array_keys($selected) as $path) {
                echo $scanner->relative($path), PHP_EOL;
            }
            return 0;
        }

        if ($this->whyTarget === null) {
            foreach (array_keys($selected) as $path) {
                $this->explain($path, $from, $depth, $scanner, $graph);
            }
            return 0;
        }

        // --why=PATH: 指定されたファイル 1 つだけを説明する
        // --tests で絞られるファイルも対象にしたいので $selected では絞らない
        $target = $this->resolvePath($this->whyTarget);
        if ($target === null) {
            return 1;
        }
        if (!isset($depth[$target])) {
            $this->note(sprintf(
                '%s は指定されたファイルに依存していません。',
                $scanner->relative($target),
            ));
            return 0;
        }
        $this->explain($target, $from, $depth, $scanner, $graph);

        return 0;
    }

    /** @return list<string> 絶対パス */
    private function resolveSpecifiedFiles(): array
    {
        $specified = [];
        foreach ($this->inputs as $input) {
            $real = $this->resolvePath($input);
            if ($real !== null) {
                $specified[$real] = true;
            }
        }

        return array_keys($specified);
    }

    /** 入力パスを絶対パスにする。見つからなければ警告して null を返す */
    private function resolvePath(string $input): ?string
    {
        $absolute = str_starts_with($input, '/') ? $input : $this->root . '/' . $input;
        $real = realpath($absolute);
        if ($real === false) {
            $this->note("警告: ファイルが見つかりません: {$input}");
            return null;
        }

        return $real;
    }

    /**
     * @param  list<string> $files
     * @return array<string, ParsedFile>
     */
    private function parseAll(array $files): array
    {
        $parser = new Parser();
        $parsed = [];
        foreach ($files as $file) {
            $code = @file_get_contents($file);
            if ($code === false) {
                $this->note("警告: 読み込めません: {$file}");
                continue;
            }
            try {
                $parsed[$file] = $parser->parse($file, $code);
            } catch (\Throwable $e) {
                // 1 ファイルの失敗で全体を止めない
                $this->note("警告: 解析に失敗しました: {$file}: {$e->getMessage()}");
            }
        }

        return $parsed;
    }

    /**
     * 1 ファイルについて、指定ファイルまでの経路と各辺の原因になった記号を表示する
     *
     * 経路は幅優先探索の親 ($from) を辿って得た最短の 1 本
     * 複数の経路がある場合でも代表の 1 本だけを示す
     *
     * @param array<string,string|null> $from  探索木の親
     * @param array<string,int>         $depth
     */
    private function explain(string $path, array $from, array $depth, Scanner $scanner, Graph $graph): void
    {
        echo $scanner->relative($path), PHP_EOL;

        if (($depth[$path] ?? -1) === 0) {
            echo '  (指定ファイル自身)', PHP_EOL;
            return;
        }
        $next = $from[$path] ?? null;
        if ($next === null) {
            echo '  └─ 命名規約による対応付け', PHP_EOL;
            return;
        }

        $cursor = $path;
        $indent = 2;
        $seen = [$path => true];
        while ($next !== null && !isset($seen[$next])) {
            $reasons = $graph->edgeReasons($cursor, $next);
            $label = $reasons === []
                ? '(理由を特定できず)'
                : implode(', ', array_slice($reasons, 0, 3));
            if (count($reasons) > 3) {
                $label .= ' 他 ' . (count($reasons) - 3) . ' 件';
            }
            $mark = ($depth[$next] ?? -1) === 0 ? '   ← 指定ファイル' : '';

            echo str_repeat(' ', $indent), '└─ ', $label, ' → ', $scanner->relative($next), $mark, PHP_EOL;

            $seen[$next] = true;
            $cursor = $next;
            $next = $from[$next] ?? null;
            $indent += 4;
        }
    }

    private function countEdges(Graph $graph): int
    {
        $edges = 0;
        foreach ($graph->forward() as $targets) {
            $edges += count($targets);
        }

        return $edges;
    }

    private function note(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
}
