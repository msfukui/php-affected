<?php
namespace App\Payment;

use App\Contract\PaymentGateway;

// interface を実装しているだけ。互いに何の関係もない
final class StripeGateway implements PaymentGateway
{
    public function charge(int $amount): bool
    {
        return $amount > 0;
    }
}
