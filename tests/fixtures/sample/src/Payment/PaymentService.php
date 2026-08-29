<?php
namespace App\Payment;

use App\Contract\PaymentGateway;

final class PaymentService
{
    public function __construct(private PaymentGateway $gateway)
    {
    }

    public function pay(int $amount): bool
    {
        return $this->gateway->charge($amount);
    }
}
