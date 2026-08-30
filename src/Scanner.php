<?php

declare(strict_types=1);

namespace PhpAffected;

/**
 * プロジェクト配下の PHP ファイルを列挙する。
 */
final readonly class Scanner
{
    /** 除外するディレクトリ / ファイル (root からの相対パスに対する fnmatch)。 */
    private const EXCLUDE = [
        'vendor/*', '*/vendor/*',
        'node_modules/*', '*/node_modules/*',
        '.git/*', '*/.git/*',
        'var/cache/*', 'storage/framework/*', 'bootstrap/cache/*',
        'build/*', 'public/build/*', '.phpunit.cache/*',
    ];

    public function __construct(private string $root) {}

    /**
     * 解析対象の PHP ファイル。
     *
     * @return list<string> 絶対パスの昇順リスト
     */
    public function scan(): array
    {
        return $this->collect(
            static fn(\SplFileInfo $info): bool => strtolower($info->getExtension()) === 'php',
        );
    }

    /**
     * 指定した名前のファイルを探す。composer.json や phpunit.xml のように
     * 拡張子では絞れない設定ファイルを、除外規則を効かせたまま集めるために使う。
     *
     * @param  list<string> $basenames
     * @return list<string> 絶対パスの昇順リスト
     */
    public function find(array $basenames): array
    {
        $wanted = array_fill_keys($basenames, true);

        return $this->collect(static fn(\SplFileInfo $info): bool => isset($wanted[$info->getFilename()]));
    }

    /**
     * @param  \Closure(\SplFileInfo): bool $accept
     * @return list<string>
     */
    private function collect(\Closure $accept): array
    {
        if (!is_dir($this->root)) {
            return [];
        }

        $directories = new \RecursiveDirectoryIterator(
            $this->root,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS,
        );
        // ディレクトリ単位で枝刈りすることで vendor/ 配下の数万ファイルを最初から触らない
        $filtered = new \RecursiveCallbackFilterIterator(
            $directories,
            fn(\SplFileInfo $info): bool => !$this->isExcluded($this->relative($info->getPathname())),
        );

        $files = [];
        foreach (new \RecursiveIteratorIterator($filtered) as $info) {
            /** @var \SplFileInfo $info */
            if ($info->isFile() && $accept($info)) {
                $files[] = $info->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    public function relative(string $absolute): string
    {
        return str_starts_with($absolute, $this->root . '/')
            ? substr($absolute, strlen($this->root) + 1)
            : $absolute;
    }

    private function isExcluded(string $relative): bool
    {
        foreach (self::EXCLUDE as $pattern) {
            if (fnmatch($pattern, $relative) || fnmatch($pattern, $relative . '/')) {
                return true;
            }
        }

        return false;
    }
}
