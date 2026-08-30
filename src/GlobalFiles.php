<?php

declare(strict_types=1);

namespace PhpAffected;

/**
 * 「テストを実行すると必ず読み込まれるファイル」を検出する。
 *
 * composer の autoload.files やテストの bootstrap は、コード上どこからも
 * 参照されていなくても全テストプロセスに読み込まれる。ここを依存として
 * 扱わないと「変更したのに関連テストが選ばれない」検出漏れになる。
 *
 * 設定ファイルはプロジェクトルートだけにあるとは限らないので、除外規則を
 * 効かせたまま配下を走査して集める。ただし `packages/foo/composer.json` が
 * 読み込まれるのは `packages/foo/` 配下のテストだけなので、設定ファイルが
 * 置かれたディレクトリを有効範囲 (scope) として一緒に返す。
 */
final readonly class GlobalFiles
{
    public function __construct(private Scanner $scanner) {}

    /**
     * @return list<array{path: string, scope: string}>
     *         path  … 全テストが読み込むファイルの絶対パス
     *         scope … それが効くディレクトリの絶対パス (この配下のテストにだけ効く)
     */
    public function detect(): array
    {
        $found = [];
        foreach ([...$this->fromComposer(), ...$this->fromPhpunit()] as [$path, $scope]) {
            $normalized = self::normalize($path);
            if (is_file($normalized)) {
                $found[$normalized . "\0" . $scope] = ['path' => $normalized, 'scope' => $scope];
            }
        }

        return $this->dropSubsumedScopes(array_values($found));
    }

    /**
     * 同じファイルが複数の scope で登録されることがある。
     *
     * 例えば laravel はルートの composer.json とサブパッケージの composer.json の
     * 両方が同じ autoload.files を持つため、`src/Illuminate/Support/helpers.php` が
     * scope=ルート と scope=src/Illuminate/Support の 2 通りで登録される。
     * 広い scope は狭い scope を包含するので、狭いほうは何も足さない。
     *
     * @param  list<array{path: string, scope: string}> $globals
     * @return list<array{path: string, scope: string}>
     */
    private function dropSubsumedScopes(array $globals): array
    {
        $scopesByPath = [];
        foreach ($globals as $global) {
            $scopesByPath[$global['path']][] = $global['scope'];
        }

        $out = [];
        foreach ($scopesByPath as $path => $scopes) {
            foreach (array_unique($scopes) as $scope) {
                $subsumed = false;
                foreach (array_unique($scopes) as $other) {
                    // $scope が $other の配下にある = $scope のほうが狭い
                    if ($other !== $scope && str_starts_with($scope . '/', $other . '/')) {
                        $subsumed = true;
                        break;
                    }
                }
                if (!$subsumed) {
                    $out[] = ['path' => $path, 'scope' => $scope];
                }
            }
        }

        return $out;
    }

    /**
     * composer.json の autoload.files / autoload-dev.files。
     * パスは composer.json のあるディレクトリ基準で解決される。
     *
     * @return list<array{0: string, 1: string}>
     */
    private function fromComposer(): array
    {
        $out = [];
        foreach ($this->scanner->find(['composer.json']) as $configPath) {
            $directory = dirname($configPath);
            $data = $this->readJson($configPath);

            foreach (['autoload', 'autoload-dev'] as $section) {
                // 外部の JSON なので、期待する形になっているか 1 段ずつ確かめる
                $autoload = $data[$section] ?? null;
                if (!is_array($autoload)) {
                    continue;
                }
                $files = $autoload['files'] ?? null;
                if (!is_array($files)) {
                    continue;
                }
                foreach ($files as $relative) {
                    if (is_string($relative)) {
                        $out[] = [$directory . '/' . ltrim($relative, '/'), $directory];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * phpunit.xml の bootstrap 属性。パスは設定ファイルの位置基準で解決される。
     * 同じディレクトリに両方あれば phpunit.xml が phpunit.xml.dist に優先する。
     *
     * @return list<array{0: string, 1: string}>
     */
    private function fromPhpunit(): array
    {
        $configs = $this->scanner->find(['phpunit.xml', 'phpunit.xml.dist']);

        // ディレクトリごとに 1 つだけ採用する
        $chosen = [];
        foreach ($configs as $configPath) {
            $directory = dirname($configPath);
            if (basename($configPath) === 'phpunit.xml' || !isset($chosen[$directory])) {
                $chosen[$directory] = $configPath;
            }
        }

        $out = [];
        foreach ($chosen as $directory => $configPath) {
            $bootstrap = $this->readBootstrap($configPath);
            if ($bootstrap !== null) {
                $out[] = [$directory . '/' . ltrim($bootstrap, '/'), $directory];
            }
        }

        return $out;
    }

    private function readBootstrap(string $configPath): ?string
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($configPath);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return null;
        }
        $bootstrap = (string) ($xml['bootstrap'] ?? '');

        return $bootstrap === '' ? null : $bootstrap;
    }

    /**
     * '.' と '..' を文字列操作だけで畳む。realpath() と違いシンボリックリンクは解決しない。
     */
    private static function normalize(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return ($isAbsolute ? '/' : '') . implode('/', $segments);
    }

    /** @return array<array-key, mixed> */
    private function readJson(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }
}
