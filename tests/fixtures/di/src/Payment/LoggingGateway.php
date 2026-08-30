<?php
namespace App\Payment;

use App\Contract\PaymentGateway;

// interface を実装しつつ、自身も注入を受けるデコレータ。
// 実行時に StripeGateway を渡される可能性があるので影響を受ける
final class LoggingGateway implements PaymentGateway
{
    public function __construct(private PaymentGateway $inner)
    {
    }

    public function charge(int $amount): bool
    {
        return $this->inner->charge($amount);
    }
}
