<?php
namespace App\Http;

final class Router
{
    public function dispatch(): void
    {
        // フレームワークと同じく実行時にパスを組み立てて読み込むので静的には辿れない
        $routes = require $this->basePath('routes/web.php');
    }

    private function basePath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . $relative;
    }
}
