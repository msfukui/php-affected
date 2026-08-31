<?php
namespace App;

use App\Http\Router;

final class Kernel
{
    public function handle(): void
    {
        (new Router())->dispatch();
    }
}
