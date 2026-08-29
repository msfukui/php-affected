<?php

declare(strict_types=1);

namespace PhpAffected;

/**
 * 1 つの PHP ファイルから抽出した「定義」と「参照」。
 *
 * クラス名・関数名は PHP の言語仕様上大文字小文字を区別しないため小文字で保持する。
 * 定数名は区別されるため元の表記のまま保持する。
 */
final class ParsedFile
{
    /**
     * このファイルが定義する class/interface/trait/enum の FQN (小文字)。
     *
     * @var list<string>
     */
    public array $classDefs = [];

    /**
     * classDefs のうち実体化できるもの (abstract/interface/trait を除く)。
     *
     * @var list<string>
     */
    public array $runnableDefs = [];

    /**
     * classDefs / funcDefs の元の表記。診断表示にのみ使う。
     *
     * @var list<string>
     */
    public array $defNames = [];

    /**
     * このファイルが定義するグローバル関数の FQN (小文字)。
     *
     * @var list<string>
     */
    public array $funcDefs = [];

    /**
     * このファイルが定義する定数の FQN (大小区別)。
     *
     * @var list<string>
     */
    public array $constDefs = [];

    /**
     * クラスとして参照している FQN (小文字)。
     *
     * @var list<string>
     */
    public array $classRefs = [];

    /**
     * 関数として参照している FQN (小文字)。
     *
     * @var list<string>
     */
    public array $funcRefs = [];

    /**
     * 定数として参照している FQN (大小区別)。
     *
     * @var list<string>
     */
    public array $constRefs = [];

    /**
     * 種別を確定できなかった参照。全種別の索引に照合する (元の表記)。
     *
     * @var list<string>
     */
    public array $anyRefs = [];

    /**
     * 名前空間内の未修飾名のグローバルフォールバック候補。
     * PHP はクラスをグローバルへフォールバックしないので関数・定数の索引にだけ照合する。
     *
     * @var list<string>
     */
    public array $globalRefs = [];

    /**
     * require/include で解決できた絶対パス。
     *
     * @var list<string>
     */
    public array $includes = [];

    /**
     * extends/implements 先の FQN (小文字)。テスト判定に使う。
     *
     * @var list<string>
     */
    public array $parents = [];

    public function __construct(public readonly string $path = '') {}

    /**
     * 重複を落としてメモリを節約する。
     *
     * 動的なプロパティアクセスにすると静的解析が型を追えなくなるので、
     * 手数は増えるが 1 つずつ書いている。
     */
    public function compact(): void
    {
        $this->classDefs = self::unique($this->classDefs);
        $this->runnableDefs = self::unique($this->runnableDefs);
        $this->defNames = self::unique($this->defNames);
        $this->funcDefs = self::unique($this->funcDefs);
        $this->constDefs = self::unique($this->constDefs);
        $this->classRefs = self::unique($this->classRefs);
        $this->funcRefs = self::unique($this->funcRefs);
        $this->constRefs = self::unique($this->constRefs);
        $this->anyRefs = self::unique($this->anyRefs);
        $this->globalRefs = self::unique($this->globalRefs);
        $this->includes = self::unique($this->includes);
        $this->parents = self::unique($this->parents);
    }

    /**
     * @param  list<string> $values
     * @return list<string>
     */
    private static function unique(array $values): array
    {
        return array_values(array_unique($values));
    }
}
