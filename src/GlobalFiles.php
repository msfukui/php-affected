<?php

declare(strict_types=1);

namespace PhpAffected;

/**
 * 「どのテストを実行しても必ず読み込まれるファイル」を検出する
 *
 * composer の autoload.files やテストの bootstrap は、コード上どこからも
 * 参照されていなくても全テストプロセスに読み込まれる。ここを依存として
 * 扱わないと「変更したのに関連テストが選ばれない」検出漏れになる。
 */
final readonly class GlobalFiles
{
    public function __construct(private string $root) {}

    /** @return list<string> 絶対パス */
    public function detect(): array
    {
        $found = [];
        foreach ([...$this->fromComposer(), ...$this->fromPhpunit()] as $path) {
            $real = realpath($path);
            if ($real !== false && is_file($real)) {
                $found[$real] = true;
            }
        }

        return array_keys($found);
    }

    /** @return list<string> */
    private function fromComposer(): array
    {
        $data = $this->readJson($this->root . '/composer.json');

        $out = [];
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
                    $out[] = $this->root . '/' . ltrim($relative, '/');
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function fromPhpunit(): array
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $candidate) {
            $path = $this->root . '/' . $candidate;
            if (!is_file($path)) {
                continue;
            }
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_file($path);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $bootstrap = $xml === false ? '' : (string) ($xml['bootstrap'] ?? '');
            if ($bootstrap !== '') {
                return [$this->root . '/' . ltrim($bootstrap, '/')];
            }
        }

        return [];
    }

    /** @return array<array-key, mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }
}
