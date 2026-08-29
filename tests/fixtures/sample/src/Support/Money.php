<?php
namespace App\Support;

use function App\Support\format_money;
use const App\Support\CURRENCY;

final class Money
{
    public function __construct(private int $amount)
    {
    }

    public function label(): string
    {
        return format_money($this->amount) . '/' . CURRENCY;
    }
}
