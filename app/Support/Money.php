<?php

namespace App\Support;

use InvalidArgumentException;

/** Monetary values are stored as integer centavos to avoid float arithmetic. */
final class Money
{
    public static function toCents(string $amount): int
    {
        $amount = trim($amount);
        if (! preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $amount)) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function display(int $cents): string
    {
        $absolute = abs($cents);

        return ($cents < 0 ? '-' : '').'₱'.preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', (string) intdiv($absolute, 100)).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function input(int $cents): string
    {
        return ($cents < 0 ? '-' : '').sprintf('%d.%02d', intdiv(abs($cents), 100), abs($cents) % 100);
    }
}
