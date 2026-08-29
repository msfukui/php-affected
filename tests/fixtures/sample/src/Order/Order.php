<?php
namespace App\Order;

final class Order
{
    /**
     * @param \App\Support\Money $money
     */
    public function __construct(public $money, public Status $status = Status::Draft)
    {
    }
}
