<?php
namespace App\Order;

enum Status: string
{
    case Draft = 'draft';
    case Paid = 'paid';
}
