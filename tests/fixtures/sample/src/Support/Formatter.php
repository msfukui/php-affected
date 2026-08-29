<?php
namespace App\Support;

final class Formatter
{
    public function wrap(string $s): string
    {
        return '[' . $s . ']';
    }
}
