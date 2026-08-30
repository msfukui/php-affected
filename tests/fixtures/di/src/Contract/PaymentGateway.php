<?php
namespace App\Contract;

interface PaymentGateway
{
    public function charge(int $amount): bool;
}
