<?php

namespace App;

class Currency
{
    /** @return array<string, int> */
    public static function all(): array
    {
        $currencies = [];
        $register = simplexml_load_file(resource_path('currencies.xml'));
        foreach ($register->CcyTbl->CcyNtry as $entry) {
            if (ctype_digit((string) $entry->CcyMnrUnts)) {
                $currencies[(string) $entry->Ccy] = (int) $entry->CcyMnrUnts;
            }
        }
        ksort($currencies);

        return $currencies;
    }

    public static function precision(string $currency): int
    {
        return self::all()[$currency] ?? 2;
    }

    public static function minor(string $amount, string $currency): int
    {
        $precision = self::precision($currency);
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * (10 ** $precision) + (int) str_pad($fraction, $precision, '0');
    }

    public static function format(int $minor, string $currency): string
    {
        $precision = self::precision($currency);
        $factor = 10 ** $precision;

        return (string) intdiv($minor, $factor).($precision ? '.'.str_pad((string) ($minor % $factor), $precision, '0', STR_PAD_LEFT) : '');
    }
}
