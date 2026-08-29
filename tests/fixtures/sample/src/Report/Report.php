<?php
namespace App\Report;

use App\Support\{Money, Formatter as F};

final class Report
{
    public function render(Money $money): string
    {
        return (new F())->wrap($money->label());
    }
}
