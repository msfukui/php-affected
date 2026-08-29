<?php
namespace App\Payment;

use App\Contract\PaymentGateway;

final class StripeGateway implements PaymentGateway
{
    public function charge(int $amount): bool
    {
        return $amount > 0;
    }
}
