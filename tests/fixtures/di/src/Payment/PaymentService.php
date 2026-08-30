<?php
namespace App\Payment;

use App\Contract\PaymentGateway;

// interface しか型宣言していない利用側
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
