<?php

declare(strict_types=1);

namespace PhpAffected;

/**
 * 記号の索引と、ファイル間の依存グラフ。
 *
 * 順方向の辺 A -> B は「A が B を必要としている」を意味する。
 * テスト選択で使うのは逆方向 (B が変わったら A に影響が出る) なので
 * reverse() を経由して探索する。
 */
final class Graph
{
    /** @var array<string, list<string>> 小文字 FQN => 定義しているファイル */
    private array $classIndex = [];
    /** @var array<string, list<string>> */
    private array $funcIndex = [];
    /** @var array<string, list<string>> 大小区別 FQN => 定義しているファイル */
    private array $constIndex = [];

    /** @var array<string, ParsedFile> */
    private array $files = [];

    /** @var array<string, list<string>> コード上には現れない暗黙の依存 (bootstrap 等) */
    private array $implicit = [];

    /** @var array<string, string> 小文字 FQN => 元の表記。診断表示にのみ使う */
    private array $display = [];

    /** @var array<string, list<string>>|null */
    private ?array $forward = null;
    /** @var array<string, list<string>>|null */
    private ?array $reverse = null;

    /** @param array<string, ParsedFile> $files パス => 解析結果 */
    public function __construct(array $files)
    {
        $this->files = $files;
        foreach ($files as $path => $parsed) {
            foreach ($parsed->defNames as $raw) {
                $this->display[strtolower($raw)] = $raw;
            }
            foreach ($parsed->classDefs as $fqn) {
                $this->classIndex[$fqn][] = $path;
            }
            foreach ($parsed->funcDefs as $fqn) {
                $this->funcIndex[$fqn][] = $path;
            }
            foreach ($parsed->constDefs as $fqn) {
                $this->constIndex[$fqn][] = $path;
            }
        }
    }

    /** @return array<string, ParsedFile> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return list<string> $fqn (小文字) を定義しているファイル */
    public function definersOfClass(string $fqn): array
    {
        return $this->classIndex[$fqn] ?? [];
    }

    /**
     * コード上の参照からは辿れない依存を辺として追加する。
     * bootstrap のように「全テストが必ず読み込むファイル」を表現するために使う。
     *
     * @param array<string, list<string>> $edges 依存する側 => 依存される側
     */
    public function addImplicitEdges(array $edges): void
    {
        foreach ($edges as $from => $targets) {
            foreach ($targets as $to) {
                if ($from !== $to) {
                    $this->implicit[$from][] = $to;
                }
            }
        }
        $this->forward = null;
        $this->reverse = null;
    }

    /** @return array<string, list<string>> */
    public function forward(): array
    {
        if ($this->forward !== null) {
            return $this->forward;
        }

        $edges = [];
        foreach ($this->files as $path => $parsed) {
            $deps = [];

            foreach ($parsed->classRefs as $ref) {
                foreach ($this->classIndex[$ref] ?? [] as $target) {
                    $deps[$target] = true;
                }
            }
            foreach ($parsed->funcRefs as $ref) {
                foreach ($this->funcIndex[$ref] ?? [] as $target) {
                    $deps[$target] = true;
                }
            }
            foreach ($parsed->constRefs as $ref) {
                foreach ($this->constIndex[$ref] ?? [] as $target) {
                    $deps[$target] = true;
                }
            }
            // 種別を確定できなかった参照は全索引に当てる (過剰検出側に倒す)
            foreach ($parsed->anyRefs as $ref) {
                $lower = strtolower($ref);
                foreach ($this->classIndex[$lower] ?? [] as $target) {
                    $deps[$target] = true;
                }
                foreach ($this->funcIndex[$lower] ?? [] as $target) {
                    $deps[$target] = true;
                }
                foreach ($this->constIndex[$ref] ?? [] as $target) {
                    $deps[$target] = true;
                }
            }
            // DI コンテナや設定配列にクラス名を文字列で書く形を拾う
            foreach ($parsed->strings as $ref) {
                foreach ($this->classIndex[$ref] ?? [] as $target) {
                    $deps[$target] = true;
                }
            }
            // 名前空間内の未修飾名のグローバルフォールバック。クラスには当てない
            foreach ($parsed->globalRefs as $ref) {
                foreach ($this->funcIndex[strtolower($ref)] ?? [] as $target) {
                    $deps[$target] = true;
                }
                foreach ($this->constIndex[$ref] ?? [] as $target) {
                    $deps[$target] = true;
                }
            }
            foreach ($parsed->includes as $target) {
                if (isset($this->files[$target])) {
                    $deps[$target] = true;
                }
            }

            unset($deps[$path]); // 自己参照は辺にしない
            $edges[$path] = array_keys($deps);
        }

        foreach ($this->implicit as $from => $targets) {
            $merged = array_flip($edges[$from] ?? []);
            foreach ($targets as $to) {
                $merged[$to] = true;
            }
            unset($merged[$from]);
            $edges[$from] = array_keys($merged);
        }

        return $this->forward = $edges;
    }

    /** @return array<string, list<string>> 依存されている側 => 依存している側 */
    public function reverse(): array
    {
        if ($this->reverse !== null) {
            return $this->reverse;
        }

        $rev = [];
        foreach ($this->forward() as $from => $targets) {
            foreach ($targets as $to) {
                $rev[$to][] = $from;
            }
        }

        return $this->reverse = $rev;
    }

    /**
     * $from が $to に依存する理由となった記号を列挙する。
     * 「なぜこのテストが選ばれたのか」を人が確認するための診断用。
     *
     * @return list<string> 例: "class App\\Support\\Money", "function app\\support\\format_money"
     */
    public function edgeReasons(string $from, string $to): array
    {
        $source = $this->files[$from] ?? null;
        $target = $this->files[$to] ?? null;
        if ($source === null || $target === null) {
            return [];
        }

        $classDefs = array_flip($target->classDefs);
        $funcDefs = array_flip($target->funcDefs);
        $constDefs = array_flip($target->constDefs);
        $reasons = [];

        $addClass = function (string $fqn) use (&$reasons, $classDefs): void {
            if (isset($classDefs[$fqn])) {
                $reasons['class ' . ($this->display[$fqn] ?? $fqn)] = true;
            }
        };
        $addFunc = function (string $fqn) use (&$reasons, $funcDefs): void {
            if (isset($funcDefs[$fqn])) {
                $reasons['function ' . ($this->display[$fqn] ?? $fqn) . '()'] = true;
            }
        };
        $addConst = function (string $fqn) use (&$reasons, $constDefs): void {
            if (isset($constDefs[$fqn])) {
                $reasons['const ' . $fqn] = true;
            }
        };

        foreach ($source->classRefs as $ref) {
            $addClass($ref);
        }
        foreach ($source->funcRefs as $ref) {
            $addFunc($ref);
        }
        foreach ($source->constRefs as $ref) {
            $addConst($ref);
        }
        foreach ($source->anyRefs as $ref) {
            $addClass(strtolower($ref));
            $addFunc(strtolower($ref));
            $addConst($ref);
        }
        foreach ($source->globalRefs as $ref) {
            $addFunc(strtolower($ref));
            $addConst($ref);
        }
        foreach ($source->strings as $ref) {
            if (isset($classDefs[$ref])) {
                $reasons['class ' . ($this->display[$ref] ?? $ref) . ' (文字列リテラル)'] = true;
            }
        }
        if (in_array($to, $source->includes, true)) {
            $reasons['require/include'] = true;
        }
        if (in_array($to, $this->implicit[$from] ?? [], true)) {
            $reasons['全テストが読み込むファイル (bootstrap 等)'] = true;
        }

        return array_keys($reasons);
    }

    /**
     * 指定されたファイルが実装する interface の「利用側」を起点に加える。
     *
     * DI コンテナ経由でしか実装クラスに触れないコードでは、テストは interface しか
     * 参照していない。実装クラスを変更したとき、その interface を受け取る側は
     * 実行時に変更後の実装を渡される可能性があるので影響を受ける。
     *
     * 一方、同じ interface を実装している別のクラスは影響を受けない。interface 自体が
     * 変わっていない以上、兄弟の実装には何の関係もないため起点に加えない。
     * ただし「実装しつつ自身も注入を受ける」デコレータ等は利用側でもあるので加える。
     *
     * 基底クラス (`extends`) は辿らない。laravel の `class Warn extends Component` で
     * 計測したところ対象が 1 件から 983 件 (全テスト) に膨れたため、
     * DI で注入される interface に限定している。
     *
     * @param  list<string> $seeds
     * @return list<string>
     */
    public function expandToInterfaceConsumers(array $seeds): array
    {
        $out = array_fill_keys($seeds, true);
        $reverse = $this->reverse();

        foreach ($this->interfacesOf($seeds) as $interfaceFqn) {
            foreach ($this->classIndex[$interfaceFqn] ?? [] as $definer) {
                foreach ($reverse[$definer] ?? [] as $dependent) {
                    if (isset($out[$dependent]) || $this->onlyImplements($dependent, $interfaceFqn)) {
                        continue;
                    }
                    $out[$dependent] = true;
                }
            }
        }

        return array_keys($out);
    }

    /**
     * 指定されたファイルが実装する interface を、interface 同士の継承もたどって集める。
     *
     * @param  list<string> $seeds
     * @return list<string> 小文字の FQN
     */
    private function interfacesOf(array $seeds): array
    {
        $queue = [];
        foreach ($seeds as $seed) {
            foreach ($this->files[$seed]->interfaces ?? [] as $fqn) {
                $queue[] = $fqn;
            }
        }

        $seen = [];
        for ($head = 0; $head < count($queue); $head++) {
            $fqn = $queue[$head];
            if (isset($seen[$fqn])) {
                continue;
            }
            $seen[$fqn] = true;
            foreach ($this->classIndex[$fqn] ?? [] as $definer) {
                foreach ($this->files[$definer]->interfaces ?? [] as $parent) {
                    $queue[] = $parent;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * $file が $interfaceFqn を実装しているだけで、利用側ではないか。
     *
     * 型宣言や `new` のような「利用位置」に現れた参照は種別を確定できず anyRefs に入る。
     * implements 節にしか現れないクラスはここに入らないので、両者を区別できる。
     */
    private function onlyImplements(string $file, string $interfaceFqn): bool
    {
        $parsed = $this->files[$file] ?? null;
        if ($parsed === null || !in_array($interfaceFqn, $parsed->interfaces, true)) {
            return false;
        }
        foreach ($parsed->anyRefs as $ref) {
            if (strtolower($ref) === $interfaceFqn) {
                return false;
            }
        }

        return true;
    }

    /**
     * 指定されたファイル群から逆方向に幅優先探索し、影響を受けるファイルを返す。
     *
     * @param  list<string> $seeds 絶対パス
     * @return array{depth: array<string,int>, from: array<string,string|null>}
     *         depth[path] = 指定ファイルからの距離 (0 は指定ファイル自身)
     *         from[path]  = そのファイルが「どのファイルに依存していたから」選ばれたか
     */
    public function impacted(array $seeds): array
    {
        $rev = $this->reverse();
        $depth = [];
        $from = [];
        $queue = [];

        foreach ($seeds as $seed) {
            if (isset($depth[$seed])) {
                continue;
            }
            $depth[$seed] = 0;
            $from[$seed] = null;
            $queue[] = $seed;
        }

        for ($head = 0; $head < count($queue); $head++) {
            $current = $queue[$head];
            $d = $depth[$current];
            foreach ($rev[$current] ?? [] as $dependent) {
                if (isset($depth[$dependent])) {
                    continue;
                }
                $depth[$dependent] = $d + 1;
                $from[$dependent] = $current;
                $queue[] = $dependent;
            }
        }

        return ['depth' => $depth, 'from' => $from];
    }
}
