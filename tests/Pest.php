<?php

declare(strict_types=1);

use PhpAffected\Graph;
use PhpAffected\ParsedFile;
use PhpAffected\Parser;
use PhpAffected\Scanner;
use PhpAffected\TestDetector;

/**
 * テスト全体で使う補助関数。
 * Pest がこのファイルを自動で読み込む。
 */

/** フィクスチャプロジェクトの絶対パス。 */
function fixture(string $name): string
{
    return dirname(__DIR__) . '/tests/fixtures/' . $name;
}

/** PHP のソース断片を解析する。ユニットテストで使う。 */
function parseCode(string $code, string $file = '/virtual/Example.php'): ParsedFile
{
    return (new Parser())->parse($file, $code);
}

/**
 * ParsedFile の連想配列から依存グラフを組み立てる。
 *
 * @param array<string, ParsedFile> $files
 */
function graphOf(array $files): Graph
{
    return new Graph($files);
}

/**
 * 一時ディレクトリを作り、[相対パス => 内容] のファイルを書き出す。
 * 作ったディレクトリはプロセス終了時にまとめて消す。
 *
 * @param array<string, string> $files
 */
function makeProject(array $files): string
{
    static $roots = [];
    if ($roots === []) {
        register_shutdown_function(static function () use (&$roots): void {
            foreach ($roots as $root) {
                removeDirectory($root);
            }
        });
    }

    $root = sys_get_temp_dir() . '/php-affected-' . bin2hex(random_bytes(6));
    $roots[] = $root;

    foreach ($files as $relative => $contents) {
        $path = $root . '/' . $relative;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    return $root;
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        /** @var SplFileInfo $entry */
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}

/**
 * CLI を子プロセスで実行する。
 *
 * @param  list<string> $args
 * @return array{out: list<string>, raw: string, err: string, code: int}
 */
function runCli(string $projectRoot, array $args): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/php-affected')
        . ' --root=' . escapeshellarg($projectRoot);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }

    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('プロセスを起動できません');
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), fn($l) => $l !== ''));
    sort($lines);

    return ['out' => $lines, 'raw' => $out, 'err' => $err, 'code' => $code];
}

/**
 * 実際のディレクトリを走査してグラフとテスト判定器まで組み立てる。
 *
 * @param  list<string> $globalFiles
 * @return array{Scanner, Graph, TestDetector}
 */
function analyzeProject(string $root, array $globalFiles = []): array
{
    $scanner = new Scanner($root);
    $parser = new Parser();

    $parsed = [];
    foreach ($scanner->scan() as $file) {
        $parsed[$file] = $parser->parse($file, (string) file_get_contents($file));
    }
    $graph = new Graph($parsed);

    return [$scanner, $graph, new TestDetector($scanner, $graph, $globalFiles)];
}
