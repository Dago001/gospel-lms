<?php

namespace App\Support;

final class Money
{
    public static function format(int $minor, string $currency = 'NGN'): string
    {
        return $currency.' '.number_format($minor / 100, 2);
    }
}
