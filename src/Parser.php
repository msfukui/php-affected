<?php

declare(strict_types=1);

namespace PhpAffected;

/**
 * token_get_all() だけを使った PHP ソースの記号抽出器。
 *
 * 構文解析ではなく字句解析のみなので、実行中の PHP より新しい構文
 * (プロパティフック等) を含むソースでも壊れずに走査できる。
 *
 * 設計方針: テスト選択が用途なので「取りこぼし」より「取りすぎ」を選ぶ。
 * 種別を確定できない識別子は anyRefs に入れ、全種別の索引に照合する。
 */
final class Parser
{
    /** 参照として扱っても意味がない予約語・組み込み型。 */
    private const SKIP = [
        'true' => 1, 'false' => 1, 'null' => 1, 'self' => 1, 'static' => 1, 'parent' => 1,
        'int' => 1, 'integer' => 1, 'float' => 1, 'double' => 1, 'string' => 1, 'bool' => 1,
        'boolean' => 1, 'array' => 1, 'object' => 1, 'mixed' => 1, 'void' => 1, 'never' => 1,
        'callable' => 1, 'iterable' => 1, 'resource' => 1, 'this' => 1,
    ];

    /** docblock のうち型を書く位置が決まっているタグだけを見る (散文からの誤検出を避ける)。 */
    private const DOC_TAGS = 'param|return|var|throws|property|property-read|property-write'
        . '|method|mixin|uses|extends|implements|template|template-extends|template-implements'
        . '|psalm-[a-z-]+|phpstan-[a-z-]+';

    private string $file = '';
    /** @var list<array{0:int,1:string}> */
    private array $ts = [];
    private int $n = 0;
    private int $i = 0;

    private string $ns = '';
    /** @var array<string,string> エイリアス(小文字) => FQN */
    private array $useClass = [];
    /** @var array<string,string> */
    private array $useFunc = [];
    /** @var array<string,string> エイリアス(大小区別) => FQN */
    private array $useConst = [];

    // docblock は「どの namespace 節に属するか」をトークン順に追えないため、
    // ファイル全体の use を合算したもので解決する (1 ファイル複数 namespace は稀)。
    /** @var array<string,string> */
    private array $allUseClass = [];
    /** @var array<string,string> */
    private array $allUseFunc = [];
    /** @var array<string,string> */
    private array $allUseConst = [];
    private string $firstNs = '';

    private int $braceDepth = 0;
    /** @var list<int> クラス本体が開いた時点の braceDepth。空ならグローバルスコープ。 */
    private array $classBodyDepths = [];
    private bool $pendingClassBody = false;
    /** いま宣言しているのが interface か。extends 先が interface かの判定に使う */
    private bool $declaringInterface = false;

    private ParsedFile $r;


    public function parse(string $file, string $code): ParsedFile
    {
        $this->file = $file;
        $this->r = new ParsedFile($file);
        $this->ns = '';
        $this->useClass = $this->useFunc = $this->useConst = [];
        $this->allUseClass = $this->allUseFunc = $this->allUseConst = [];
        $this->firstNs = '';
        $this->braceDepth = 0;
        $this->classBodyDepths = [];
        $this->pendingClassBody = false;
        $this->declaringInterface = false;

        $docs = $this->tokenize($code);
        $this->walk();
        $this->scanDocblocks($docs);

        $this->r->compact();

        return $this->r;
    }

    /**
     * 空白と通常コメントを捨て、doc コメントは別途返す。
     * これにより prev/next の判定で「直前の有意なトークン」を単純な添字で取れる。
     *
     * @return list<string> doc コメント本文
     */
    private function tokenize(string $code): array
    {
        $ts = [];
        $docs = [];
        foreach (token_get_all($code) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT) {
                    continue;
                }
                if ($t[0] === T_DOC_COMMENT) {
                    $docs[] = $t[1];
                    continue;
                }
                $ts[] = [$t[0], $t[1]];
            } else {
                $ts[] = [0, $t];
            }
        }
        $this->ts = $ts;
        $this->n = count($ts);
        $this->i = 0;

        return $docs;
    }

    private function walk(): void
    {
        while ($this->i < $this->n) {
            [$id, $text] = $this->ts[$this->i];

            if ($id === 0) {
                if ($text === '{') {
                    $this->braceDepth++;
                    if ($this->pendingClassBody) {
                        $this->classBodyDepths[] = $this->braceDepth;
                        $this->pendingClassBody = false;
                    }
                    $this->i++;
                    continue;
                }
                if ($text === '}') {
                    if ($this->classBodyDepths !== [] && end($this->classBodyDepths) === $this->braceDepth) {
                        array_pop($this->classBodyDepths);
                    }
                    $this->braceDepth--;
                    $this->i++;
                    continue;
                }
                $this->i++;
                continue;
            }

            // 文字列補間の "{$x}" / "${x}" も } で閉じるので深さを合わせておく
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $this->braceDepth++;
                $this->i++;
                continue;
            }

            switch ($id) {
                case T_NAMESPACE:
                    $this->handleNamespace();
                    break;
                case T_USE:
                    $this->handleUse();
                    break;
                case T_CLASS:
                case T_INTERFACE:
                case T_TRAIT:
                case T_ENUM:
                    $this->handleClassLike();
                    break;
                case T_EXTENDS:
                case T_IMPLEMENTS:
                    $this->handleInheritance();
                    break;
                case T_CATCH:
                    $this->handleCatch();
                    break;
                case T_FUNCTION:
                    $this->handleFunction();
                    break;
                case T_CONST:
                    $this->handleConst();
                    break;
                case T_REQUIRE:
                case T_REQUIRE_ONCE:
                case T_INCLUDE:
                case T_INCLUDE_ONCE:
                    $this->i++;
                    $this->handleInclude();
                    break;
                case T_CONSTANT_ENCAPSED_STRING:
                    $this->handleStringLiteral($text);
                    $this->i++;
                    break;
                case T_STRING:
                case T_NAME_QUALIFIED:
                case T_NAME_FULLY_QUALIFIED:
                case T_NAME_RELATIVE:
                    $this->handleName();
                    break;
                default:
                    $this->i++;
            }
        }
    }

    // ---------------------------------------------------------------- 宣言系

    private function handleNamespace(): void
    {
        $next = $this->ts[$this->i + 1] ?? null;
        if ($next !== null && $this->isName($next)) {
            $this->ns = ltrim($next[1], '\\');
            if ($this->firstNs === '') {
                $this->firstNs = $this->ns;
            }
            $this->i += 2;
        } else {
            // namespace { ... } (グローバル名前空間ブロック)
            $this->ns = '';
            $this->i++;
        }
        // namespace 節が変わると use の有効範囲もリセットされる
        $this->useClass = $this->useFunc = $this->useConst = [];
    }

    private function handleUse(): void
    {
        $next = $this->ts[$this->i + 1] ?? null;

        // クロージャの use ($x, $y) — 変数のみなので依存関係はない
        if ($next !== null && $next[0] === 0 && $next[1] === '(') {
            $this->i++;
            return;
        }

        // クラス本体の中なら trait の use。名前は参照として拾う
        if ($this->classBodyDepths !== []) {
            $this->i++;
            while ($this->i < $this->n) {
                $t = $this->ts[$this->i];
                if ($this->isName($t)) {
                    $this->addRefs($t[1], RefKind::ClassLike);
                    $this->i++;
                    continue;
                }
                if ($t[0] === 0 && $t[1] === ',') {
                    $this->i++;
                    continue;
                }
                break; // ';' か '{' (競合解決ブロック) で終わり。後者は本流ループに任せる
            }
            return;
        }

        $this->parseImport();
    }

    /**
     * use インポート文。単純形・as 付き・カンマ区切り・グループ use の全形に対応する。
     */
    private function parseImport(): void
    {
        $this->i++; // 'use' を消費

        $stmtKind = RefKind::ClassLike;
        $t = $this->ts[$this->i] ?? null;
        if ($t !== null && $t[0] === T_FUNCTION) {
            $stmtKind = RefKind::Func;
            $this->i++;
        } elseif ($t !== null && $t[0] === T_CONST) {
            $stmtKind = RefKind::Constant;
            $this->i++;
        }

        while ($this->i < $this->n) {
            $t = $this->ts[$this->i];

            if ($t[0] === 0 && $t[1] === ';') {
                $this->i++;
                return;
            }
            if ($t[0] === 0 && $t[1] === ',') {
                $this->i++;
                continue;
            }
            if (!$this->isName($t)) {
                $this->i++; // 想定外のトークンは読み飛ばす
                continue;
            }

            $name = ltrim($t[1], '\\');
            $this->i++;

            // グループ use: `use A\B\{C, D as E};`
            //   → T_NAME_QUALIFIED('A\B') + T_NS_SEPARATOR + '{'
            $t1 = $this->ts[$this->i] ?? null;
            $t2 = $this->ts[$this->i + 1] ?? null;
            $isGroup = $t1 !== null && $t1[0] === T_NS_SEPARATOR
                && $t2 !== null && $t2[0] === 0 && $t2[1] === '{';
            if ($isGroup) {
                $this->i += 2; // '\' と '{' を消費
                $this->parseUseGroup($name, $stmtKind);
                // グループ use は単独で 1 文を構成する (`use A\{B}, C;` は PHP の構文エラー)。
                // ここで抜けないと後続のコードを use の項目として読み続けてしまう。
                return;
            }

            $alias = $this->lastSegment($name);
            $t1 = $this->ts[$this->i] ?? null;
            if ($t1 !== null && $t1[0] === T_AS) {
                $aliasTok = $this->ts[$this->i + 1] ?? null;
                if ($aliasTok !== null && $aliasTok[0] === T_STRING) {
                    $alias = $aliasTok[1];
                    $this->i += 2;
                }
            }

            $this->addImport($stmtKind, $name, $alias);
        }
    }

    private function parseUseGroup(string $prefix, RefKind $stmtKind): void
    {
        while ($this->i < $this->n) {
            $t = $this->ts[$this->i];

            if ($t[0] === 0 && ($t[1] === '}' || $t[1] === ';')) {
                $this->i++;
                if ($t[1] === '}') {
                    // 直後の ';' も消費しておく
                    $semi = $this->ts[$this->i] ?? null;
                    if ($semi !== null && $semi[0] === 0 && $semi[1] === ';') {
                        $this->i++;
                    }
                }
                return;
            }
            if ($t[0] === 0 && $t[1] === ',') {
                $this->i++;
                continue;
            }

            // グループ内の要素ごとに function / const を上書きできる
            $itemKind = $stmtKind;
            if ($t[0] === T_FUNCTION) {
                $itemKind = RefKind::Func;
                $this->i++;
            } elseif ($t[0] === T_CONST) {
                $itemKind = RefKind::Constant;
                $this->i++;
            }

            $t = $this->ts[$this->i] ?? null;
            if ($t === null || !$this->isName($t)) {
                $this->i++;
                continue;
            }

            $full = $prefix . '\\' . ltrim($t[1], '\\');
            $this->i++;

            $alias = $this->lastSegment($full);
            $t1 = $this->ts[$this->i] ?? null;
            if ($t1 !== null && $t1[0] === T_AS) {
                $aliasTok = $this->ts[$this->i + 1] ?? null;
                if ($aliasTok !== null && $aliasTok[0] === T_STRING) {
                    $alias = $aliasTok[1];
                    $this->i += 2;
                }
            }

            $this->addImport($itemKind, $full, $alias);
        }
    }

    /**
     * import は「エイリアス表の登録」と「依存の記録」を兼ねる。
     *
     * use 文が取りうるのは class / function / const の 3 種類だけで
     * RefKind::Any は来ない。ここで弾いておけば、以降の match が
     * 3 種類を網羅していることを静的解析でも保証できる。
     */
    private function addImport(RefKind $kind, string $fqn, string $alias): void
    {
        if ($kind === RefKind::Any) {
            throw new \LogicException('use 文の種類として RefKind::Any は現れない');
        }

        $lowerAlias = strtolower($alias);

        match ($kind) {
            RefKind::ClassLike => $this->useClass[$lowerAlias] = $this->allUseClass[$lowerAlias] = $fqn,
            RefKind::Func      => $this->useFunc[$lowerAlias] = $this->allUseFunc[$lowerAlias] = $fqn,
            RefKind::Constant  => $this->useConst[$alias] = $this->allUseConst[$alias] = $fqn,
        };

        // docblock でしか使われない import も依存として残せるよう、ここで参照も立てる
        match ($kind) {
            RefKind::ClassLike => $this->r->classRefs[] = strtolower($fqn),
            RefKind::Func      => $this->r->funcRefs[] = strtolower($fqn),
            RefKind::Constant  => $this->r->constRefs[] = $fqn,
        };
    }

    private function handleClassLike(): void
    {
        $tokenId = $this->ts[$this->i][0];
        $prev = $this->ts[$this->i - 1] ?? null;

        // Foo::class は定義ではない
        if ($prev !== null && $prev[0] === T_DOUBLE_COLON) {
            $this->i++;
            return;
        }
        // 無名クラス: 定義名はないが本体の波括弧は追跡する
        if ($prev !== null && $prev[0] === T_NEW) {
            $this->pendingClassBody = true;
            $this->i++;
            return;
        }

        // abstract / final / readonly は class の直前に並ぶ。abstract なら実体化できない
        $abstract = false;
        for ($k = $this->i - 1; $k >= 0; $k--) {
            $modifier = $this->ts[$k][0];
            if ($modifier === T_ABSTRACT) {
                $abstract = true;
                continue;
            }
            if ($modifier === T_FINAL || $modifier === T_READONLY) {
                continue;
            }
            break;
        }
        $runnable = !$abstract && ($tokenId === T_CLASS || $tokenId === T_ENUM);
        $this->declaringInterface = $tokenId === T_INTERFACE;

        $this->i++;
        $next = $this->ts[$this->i] ?? null;
        if ($next !== null && $next[0] === T_STRING) {
            $raw = $this->qualify($next[1]);
            $fqn = strtolower($raw);
            $this->r->classDefs[] = $fqn;
            $this->r->defNames[] = $raw;
            if ($runnable) {
                $this->r->runnableDefs[] = $fqn;
            }
            $this->i++;
        }
        $this->pendingClassBody = true;
    }

    private function handleInheritance(): void
    {
        // `implements X` の X は必ず interface。`interface A extends B` の B も同じ
        $isInterface = $this->ts[$this->i][0] === T_IMPLEMENTS || $this->declaringInterface;
        $this->i++;
        while ($this->i < $this->n) {
            $t = $this->ts[$this->i];
            if ($this->isName($t)) {
                foreach ($this->resolve($t[1], RefKind::ClassLike) as [$fqn, $isGlobalFallback]) {
                    if ($isGlobalFallback) {
                        continue;
                    }
                    $lower = strtolower($fqn);
                    $this->r->classRefs[] = $lower;
                    $this->r->parents[] = $lower;
                    if ($isInterface) {
                        $this->r->interfaces[] = $lower;
                    }
                }
                $this->i++;
                continue;
            }
            if ($t[0] === 0 && $t[1] === ',') {
                $this->i++;
                continue;
            }
            break;
        }
    }

    private function handleCatch(): void
    {
        $this->i++;
        $t = $this->ts[$this->i] ?? null;
        if ($t !== null && $t[0] === 0 && $t[1] === '(') {
            $this->i++;
        }
        while ($this->i < $this->n) {
            $t = $this->ts[$this->i];
            if ($this->isName($t)) {
                $this->addRefs($t[1], RefKind::ClassLike);
                $this->i++;
                continue;
            }
            if ($t[0] === 0 && $t[1] === '|') {
                $this->i++;
                continue;
            }
            break; // T_VARIABLE か ')' で終わり
        }
    }

    private function handleFunction(): void
    {
        $this->i++;
        $t = $this->ts[$this->i] ?? null;

        // 参照返し `function &foo()`
        if ($t !== null && $t[0] === 0 && $t[1] === '&') {
            $this->i++;
            $t = $this->ts[$this->i] ?? null;
        }
        if ($t === null || $t[0] !== T_STRING) {
            return; // クロージャ `function (`
        }

        // クラス本体の中ならメソッド。外なら (関数の入れ子であっても) グローバル関数。
        if ($this->classBodyDepths === []) {
            $raw = $this->qualify($t[1]);
            $this->r->funcDefs[] = strtolower($raw);
            $this->r->defNames[] = $raw;
        }
        $this->i++; // 名前をここで消費し、参照として拾われないようにする
    }

    /**
     * const 宣言。値の中の参照は本流ループに拾わせたいので、
     * 名前だけを先読みで記録し、消費はしない。
     */
    private function handleConst(): void
    {
        if ($this->classBodyDepths !== []) {
            $this->i++;
            return; // クラス定数はファイル間の記号にならない
        }

        $depth = 0;
        for ($j = $this->i + 1; $j < $this->n; $j++) {
            [$id, $text] = $this->ts[$j];
            if ($id === 0) {
                if ($text === '(' || $text === '[') {
                    $depth++;
                    continue;
                }
                if ($text === ')' || $text === ']') {
                    $depth--;
                    continue;
                }
                if ($text === ';' && $depth === 0) {
                    break;
                }
            }
            if ($depth !== 0 || $id !== T_STRING) {
                continue;
            }
            $after = $this->ts[$j + 1] ?? null;
            if ($after !== null && $after[0] === 0 && $after[1] === '=') {
                $this->r->constDefs[] = $this->qualify($text);
            }
        }
        $this->i++;
    }

    // ---------------------------------------------------------------- 参照系

    private function handleName(): void
    {
        $t = $this->ts[$this->i];
        $name = $t[1];
        $lower = strtolower($name);

        $prev = $this->ts[$this->i - 1] ?? null;
        $next = $this->ts[$this->i + 1] ?? null;

        // define('CONST_NAME', ...) を定数定義として拾う
        if ($lower === 'define' && $next !== null && $next[0] === 0 && $next[1] === '(') {
            $arg = $this->ts[$this->i + 2] ?? null;
            if ($arg !== null && $arg[0] === T_CONSTANT_ENCAPSED_STRING) {
                $this->r->constDefs[] = ltrim($this->unquote($arg[1]), '\\');
            }
        }

        $this->i++;

        if (isset(self::SKIP[$lower])) {
            return;
        }
        if ($prev !== null && $this->isMemberAccess($prev)) {
            return; // ->foo / ?->foo / Foo::bar の右辺はファイル外の記号ではない
        }
        if ($prev !== null && ($prev[0] === T_NAMESPACE || $prev[0] === T_GOTO
            || ($prev[0] === 0 && $prev[1] === '$'))) {
            return;
        }
        // 名前付き引数 foo(bar: 1) — bar は記号ではない
        if ($next !== null && $next[0] === 0 && $next[1] === ':'
            && $prev !== null && $prev[0] === 0 && ($prev[1] === '(' || $prev[1] === ',')) {
            return;
        }

        $kind = RefKind::Any;
        if ($prev !== null && ($prev[0] === T_NEW || $prev[0] === T_INSTANCEOF || $prev[0] === T_ATTRIBUTE)) {
            $kind = RefKind::ClassLike;
        } elseif ($next !== null && $next[0] === T_DOUBLE_COLON) {
            $kind = RefKind::ClassLike;
        } elseif ($next !== null && $next[0] === 0 && $next[1] === '(') {
            $kind = RefKind::Func;
        }

        $this->addRefs($name, $kind);
    }

    private function addRefs(string $name, RefKind $kind): void
    {
        foreach ($this->resolve($name, $kind) as [$fqn, $isGlobalFallback]) {
            if ($isGlobalFallback) {
                // 名前空間内の未修飾名。PHP がグローバルへフォールバックするのは
                // 関数と定数だけで、クラスはフォールバックしない
                match ($kind) {
                    RefKind::ClassLike => null,
                    RefKind::Func      => $this->r->funcRefs[] = strtolower($fqn),
                    RefKind::Constant  => $this->r->constRefs[] = $fqn,
                    RefKind::Any       => $this->r->globalRefs[] = $fqn,
                };
                continue;
            }

            match ($kind) {
                RefKind::ClassLike => $this->r->classRefs[] = strtolower($fqn),
                RefKind::Func      => $this->r->funcRefs[] = strtolower($fqn),
                RefKind::Constant  => $this->r->constRefs[] = $fqn,
                RefKind::Any       => $this->r->anyRefs[] = $fqn,
            };
        }
    }

    /**
     * 名前を FQN 候補へ解決する。
     *
     * use によるエイリアスが当たった場合はそれが PHP 上の唯一の解決結果なので
     * 候補を 1 つに絞れる。当たらなければ「現 namespace 配下」と
     * 「グローバル」の両方を候補に出す (関数・定数のグローバルフォールバック、
     * および namespace を使わないプロジェクトへの対応)。
     *
     * @return list<array{0:string, 1:bool}> [FQN, グローバルフォールバック候補か]
     */
    private function resolve(string $name, RefKind $kind): array
    {
        if ($name === '') {
            return [];
        }
        if ($name[0] === '\\') {
            return [[ltrim($name, '\\'), false]];
        }
        if (stripos($name, 'namespace\\') === 0) {
            return [[ltrim($this->ns . '\\' . substr($name, 10), '\\'), false]];
        }

        $pos = strpos($name, '\\');
        if ($pos !== false) {
            // 修飾名。先頭セグメントだけが use エイリアスの対象になる
            $first = strtolower(substr($name, 0, $pos));
            $rest = substr($name, $pos);
            if (isset($this->useClass[$first])) {
                return [[$this->useClass[$first] . $rest, false]];
            }
            return [[ltrim($this->ns . '\\' . $name, '\\'), false]];
        }

        $lower = strtolower($name);
        $hits = [];
        if (($kind === RefKind::ClassLike || $kind === RefKind::Any) && isset($this->useClass[$lower])) {
            $hits[] = $this->useClass[$lower];
        }
        if (($kind === RefKind::Func || $kind === RefKind::Any) && isset($this->useFunc[$lower])) {
            $hits[] = $this->useFunc[$lower];
        }
        if (($kind === RefKind::Constant || $kind === RefKind::Any) && isset($this->useConst[$name])) {
            $hits[] = $this->useConst[$name];
        }
        if ($hits !== []) {
            // use によるインポートが当たったならそれが PHP 上の唯一の解決結果
            return array_map(static fn(string $fqn): array => [$fqn, false], array_values(array_unique($hits)));
        }

        if ($this->ns === '') {
            return [[$name, false]];
        }

        return [[$this->ns . '\\' . $name, false], [$name, true]];
    }

    // ---------------------------------------------------------------- その他

    /**
     * require/include のパスを可能な範囲で静的評価する。
     * 対応: 文字列リテラル / __DIR__ / __FILE__ / dirname(..., n) / '.' 連結 / 括弧。
     */
    private function handleInclude(): void
    {
        $expr = [];
        $depth = 0;
        while ($this->i < $this->n) {
            [$id, $text] = $this->ts[$this->i];
            if ($id === 0) {
                if ($text === '(' || $text === '[') {
                    $depth++;
                } elseif ($text === ')' || $text === ']') {
                    if ($depth === 0) {
                        break; // 自分を囲む括弧の終わり
                    }
                    $depth--;
                } elseif (($text === ';' || $text === ',') && $depth === 0) {
                    break;
                }
            }
            $expr[] = $this->ts[$this->i];
            $this->i++;
        }

        $pos = 0;
        $value = $this->evalConcat($expr, $pos);
        if ($value === null) {
            return; // 変数を含む動的な require は追えない
        }

        $resolved = $this->resolveIncludePath($value);
        if ($resolved !== null) {
            $this->r->includes[] = $resolved;
        }
    }

    /** @param list<array{0:int,1:string}> $ts */
    private function evalConcat(array $ts, int &$pos): ?string
    {
        $out = $this->evalTerm($ts, $pos);
        if ($out === null) {
            return null;
        }
        while ($pos < count($ts)) {
            $t = $ts[$pos];
            if ($t[0] !== 0 || $t[1] !== '.') {
                break;
            }
            $pos++;
            $rhs = $this->evalTerm($ts, $pos);
            if ($rhs === null) {
                return null;
            }
            $out .= $rhs;
        }

        return $out;
    }

    /** @param list<array{0:int,1:string}> $ts */
    private function evalTerm(array $ts, int &$pos): ?string
    {
        $t = $ts[$pos] ?? null;
        if ($t === null) {
            return null;
        }

        if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $pos++;
            return $this->unquote($t[1]);
        }
        if ($t[0] === T_DIR) {
            $pos++;
            return dirname($this->file);
        }
        if ($t[0] === T_FILE) {
            $pos++;
            return $this->file;
        }
        if ($t[0] === 0 && $t[1] === '(') {
            $pos++;
            $inner = $this->evalConcat($ts, $pos);
            $close = $ts[$pos] ?? null;
            if ($close !== null && $close[0] === 0 && $close[1] === ')') {
                $pos++;
            }
            return $inner;
        }
        if ($t[0] === T_STRING && strtolower($t[1]) === 'dirname') {
            $open = $ts[$pos + 1] ?? null;
            if ($open === null || $open[0] !== 0 || $open[1] !== '(') {
                return null;
            }
            $pos += 2;
            $inner = $this->evalConcat($ts, $pos);
            if ($inner === null) {
                return null;
            }
            $levels = 1;
            $t2 = $ts[$pos] ?? null;
            if ($t2 !== null && $t2[0] === 0 && $t2[1] === ',') {
                $num = $ts[$pos + 1] ?? null;
                if ($num === null || $num[0] !== T_LNUMBER) {
                    return null;
                }
                $levels = (int) $num[1];
                $pos += 2;
            }
            $close = $ts[$pos] ?? null;
            if ($close !== null && $close[0] === 0 && $close[1] === ')') {
                $pos++;
            }
            return $levels >= 1 ? dirname($inner, $levels) : $inner;
        }

        return null; // 変数など動的な要素が混ざっている
    }

    private function resolveIncludePath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        $candidates = [];
        if ($path[0] === '/') {
            $candidates[] = $path;
        } else {
            $candidates[] = dirname($this->file) . '/' . $path;
        }
        foreach ($candidates as $c) {
            $real = realpath($c);
            if ($real !== false && is_file($real)) {
                return $real;
            }
        }

        return null;
    }

    /**
     * DI コンテナや設定配列に現れる 'App\Foo\Bar' 形式の文字列を参照として拾う。
     *
     * 名前空間区切りを含むものだけに限定している。区切りのない 'User' のような
     * 文字列まで拾うと、ログのメッセージや設定値と区別がつかず誤検出が増えるため。
     */
    private function handleStringLiteral(string $raw): void
    {
        $value = ltrim($this->unquote($raw), '\\');
        if ($value === '' || strlen($value) > 255) {
            return;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $value) !== 1) {
            return;
        }

        $this->r->strings[] = strtolower($value);
    }

    /** @param list<string> $docs */
    private function scanDocblocks(array $docs): void
    {
        if ($docs === []) {
            return;
        }
        // docblock はファイル全体の use を合算した表で解決する
        $this->ns = $this->firstNs;
        $this->useClass = $this->allUseClass;
        $this->useFunc = $this->allUseFunc;
        $this->useConst = $this->allUseConst;

        foreach ($docs as $doc) {
            if (!preg_match_all('/@(?:' . self::DOC_TAGS . ')\s+(\S+)/', $doc, $m)) {
                continue;
            }
            foreach ($m[1] as $typeExpr) {
                foreach (preg_split('/[|&,<>()\[\]{}]+/', $typeExpr) ?: [] as $piece) {
                    $piece = trim($piece, "?* \t\r\n");
                    if ($piece === '' || isset(self::SKIP[strtolower($piece)])) {
                        continue;
                    }
                    if (preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $piece) !== 1) {
                        continue;
                    }
                    $this->addRefs($piece, RefKind::ClassLike);
                }
            }
        }
    }

    // ---------------------------------------------------------------- 補助

    /** @param array{0:int,1:string} $t */
    private function isName(array $t): bool
    {
        return $t[0] === T_STRING
            || $t[0] === T_NAME_QUALIFIED
            || $t[0] === T_NAME_FULLY_QUALIFIED
            || $t[0] === T_NAME_RELATIVE;
    }

    /** @param array{0:int,1:string} $t */
    private function isMemberAccess(array $t): bool
    {
        return $t[0] === T_OBJECT_OPERATOR
            || $t[0] === T_NULLSAFE_OBJECT_OPERATOR
            || $t[0] === T_DOUBLE_COLON
            || $t[0] === T_FUNCTION
            || $t[0] === T_CONST
            || $t[0] === T_CLASS
            || $t[0] === T_INTERFACE
            || $t[0] === T_TRAIT
            || $t[0] === T_ENUM;
    }

    private function qualify(string $name): string
    {
        return ltrim($this->ns . '\\' . $name, '\\');
    }

    private function lastSegment(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }

    /**
     * 文字列リテラルから引用符とエスケープを外す。
     *
     * 二重引用符に stripcslashes() は使えない。PHP は `"App\Payment\X"` の
     * `\P` をエスケープとして解釈せずバックスラッシュを残すが、stripcslashes() は
     * 落としてしまい `AppPaymentX` になってクラス名として認識できなくなる。
     * ここで必要なのはクラス名とパスの復元だけなので、`\\` を `\` に畳むだけでよい。
     */
    private function unquote(string $raw): string
    {
        if (strlen($raw) < 2) {
            return $raw;
        }
        $quote = $raw[0];
        $body = substr($raw, 1, -1);

        return $quote === "'"
            ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body)
            : str_replace('\\\\', '\\', $body);
    }
}
