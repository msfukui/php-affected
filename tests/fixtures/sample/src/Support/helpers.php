<?php
namespace App\Support;

const CURRENCY = 'JPY';

function format_money(int $amount): string
{
    return $amount . ' ' . CURRENCY;
}
