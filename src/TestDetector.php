<?php

declare(strict_types=1);

namespace PhpAffected;

/**
 * テストファイルの判定。
 *
 * 2 つの概念を分けている。
 *   isTest()         … テストコードの一部か (フィクスチャや基底クラスも含む広い判定)
 *   isRunnableTest() … テストランナーに渡して意味があるか (実際に出力するもの)
 *
 * tests/ 配下にはフィクスチャ・スタブ・基底クラスが同居しているので、
 * 「tests/ 配下だから実行対象」とすると PHPUnit に警告を出させるだけの
 * ファイルまで並べてしまう。実行対象は名前規約か TestCase の継承で絞る。
 */
final class TestDetector
{
    /** ここに含まれるパスはテストコード扱い (root からの相対パスに対する fnmatch)。 */
    private const TEST_PATHS = [
        'tests/*', 'test/*', 'Tests/*',
        '*/tests/*', '*/test/*', '*/Tests/*',
    ];

    /** ファイル名 (basename) に対する fnmatch パターン。 */
    private const TEST_PATTERNS = ['*Test.php', '*TestCase.php', '*Spec.php', '*_test.php'];

    /** これらを継承していればテストとみなす FQN (小文字)。 */
    private const TEST_BASE_CLASSES = [
        'phpunit\framework\testcase',
        'codeception\test\unit',
        'pestphp\pest\testcase',
        'phpspec\objectbehavior',
    ];

    /** @var array<string, bool> */
    private array $isTestMemo = [];
    /** @var array<string, bool> */
    private array $inheritsMemo = [];
    /** @var array<string, bool> bootstrap 等。テスト用ディレクトリにあってもテストではない */
    private array $globalFiles = [];

    /** @param list<string> $globalFiles */
    public function __construct(
        private readonly Scanner $scanner,
        private readonly Graph $graph,
        array $globalFiles = [],
    ) {
        $this->globalFiles = array_fill_keys($globalFiles, true);
    }

    public function isTest(string $absolutePath): bool
    {
        if (isset($this->globalFiles[$absolutePath])) {
            return false;
        }
        if (isset($this->isTestMemo[$absolutePath])) {
            return $this->isTestMemo[$absolutePath];
        }
        $this->isTestMemo[$absolutePath] = false;

        $relative = $this->scanner->relative($absolutePath);
        foreach (self::TEST_PATHS as $pattern) {
            if (fnmatch($pattern, $relative)) {
                return $this->isTestMemo[$absolutePath] = true;
            }
        }
        if ($this->inheritsTestCase($absolutePath)) {
            return $this->isTestMemo[$absolutePath] = true;
        }

        return $this->isTestMemo[$absolutePath] = $this->looksLikeTestByName($absolutePath);
    }

    /**
     * テストランナーに渡して意味のあるファイルか。
     *
     * 判断材料は 2 つ。
     *   - 既知の TestCase を (間接的にでも) 継承した具象クラスを定義している
     *   - テストのファイル名規約に合致している (クラスを持たない形式も許容)
     * abstract な基底クラス、trait、interface、フィクスチャはここで落ちる。
     */
    public function isRunnableTest(string $absolutePath): bool
    {
        if (!$this->isTest($absolutePath)) {
            return false;
        }

        $parsed = $this->graph->files()[$absolutePath] ?? null;
        if ($parsed === null) {
            return true; // 解析できていないので安全側に倒す
        }

        $hasConcreteClass = $parsed->runnableDefs !== [];
        if ($hasConcreteClass && $this->inheritsTestCase($absolutePath)) {
            return true;
        }
        if (!$this->matchesTestName($absolutePath)) {
            return false;
        }

        return $parsed->classDefs === [] || $hasConcreteClass;
    }

    /**
     * 既知の TestCase を継承しているか。プロジェクト内の中間基底クラスも辿る。
     */
    private function inheritsTestCase(string $absolutePath): bool
    {
        if (isset($this->inheritsMemo[$absolutePath])) {
            return $this->inheritsMemo[$absolutePath];
        }
        $this->inheritsMemo[$absolutePath] = false; // 循環継承でも止まるように先に置く

        $parsed = $this->graph->files()[$absolutePath] ?? null;
        if ($parsed === null) {
            return false;
        }

        foreach ($parsed->parents as $parentFqn) {
            if (in_array($parentFqn, self::TEST_BASE_CLASSES, true)) {
                return $this->inheritsMemo[$absolutePath] = true;
            }
            foreach ($this->graph->definersOfClass($parentFqn) as $definer) {
                if ($definer !== $absolutePath && $this->inheritsTestCase($definer)) {
                    return $this->inheritsMemo[$absolutePath] = true;
                }
            }
        }

        return false;
    }

    private function matchesTestName(string $absolutePath): bool
    {
        foreach (self::TEST_PATTERNS as $pattern) {
            if (fnmatch($pattern, basename($absolutePath))) {
                return true;
            }
        }

        return false;
    }

    /**
     * テスト用ディレクトリの外にあり、既知の TestCase も継承していないファイルの判定。
     *
     * ファイル名だけで決めると FooTest という名前の本番クラスを拾ってしまう
     * (Laravel の ChainedBatchTruthTest や CreatesMatchingTest trait など)。
     * テストクラスは本番コードから参照されない、という性質を追加の条件にする。
     */
    private function looksLikeTestByName(string $absolutePath): bool
    {
        if (!$this->matchesTestName($absolutePath)) {
            return false;
        }

        $parsed = $this->graph->files()[$absolutePath] ?? null;
        if ($parsed === null || $parsed->classDefs === []) {
            return true;
        }

        return ($this->graph->reverse()[$absolutePath] ?? []) === [];
    }

    /**
     * 命名規約による対応付け。src/Foo.php <-> tests/FooTest.php のように、
     * 静的な参照がなくてもテスト対象が明らかなペアを拾う安全網。
     *
     * @param  list<string> $changed
     * @param  list<string> $allFiles
     * @return list<string>
     */
    public function pairByName(array $changed, array $allFiles): array
    {
        $wanted = [];
        foreach ($changed as $path) {
            $stem = basename($path, '.php');
            foreach (['Test', 'Tests', 'TestCase', 'Spec'] as $suffix) {
                $wanted[strtolower($stem . $suffix . '.php')] = true;
                $wanted[strtolower($suffix . $stem . '.php')] = true;
            }
        }

        $out = [];
        foreach ($allFiles as $path) {
            if (isset($wanted[strtolower(basename($path))]) && $this->isRunnableTest($path)) {
                $out[] = $path;
            }
        }

        return $out;
    }
}
