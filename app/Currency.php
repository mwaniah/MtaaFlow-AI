<?php

namespace App;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;

class Currency
{
    /**
     * @return array<string, int>
     */
    public static function all(): array
    {
        return Cache::remember(
            'currency.iso4217.registry.v1',
            now()->addDay(),
            static function (): array {
                $xml = file_get_contents(resource_path('currencies.xml'));

                if ($xml === false) {
                    throw new RuntimeException('Unable to read the ISO 4217 currency registry.');
                }

                $previousErrorHandling = libxml_use_internal_errors(true);

                try {
                    $registry = simplexml_load_string(
                        $xml,
                        SimpleXMLElement::class,
                        LIBXML_NONET | LIBXML_NOBLANKS
                    );

                    if ($registry === false || ! isset($registry->CcyTbl->CcyNtry)) {
                        throw new RuntimeException('The ISO 4217 currency registry is invalid.');
                    }

                    $currencies = [];

                    foreach ($registry->CcyTbl->CcyNtry as $entry) {
                        $code = (string) $entry->Ccy;
                        $minorUnits = (string) $entry->CcyMnrUnts;

                        if (! preg_match('/\A[A-Z]{3}\z/', $code) || ! ctype_digit($minorUnits)) {
                            continue;
                        }

                        $precision = (int) $minorUnits;

                        if (isset($currencies[$code]) && $currencies[$code] !== $precision) {
                            throw new RuntimeException('The ISO 4217 currency registry contains conflicting precision values.');
                        }

                        $currencies[$code] = $precision;
                    }

                    if ($currencies === []) {
                        throw new RuntimeException('The ISO 4217 currency registry contains no supported currencies.');
                    }

                    ksort($currencies);

                    return $currencies;
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previousErrorHandling);
                }
            }
        );
    }

    public static function precision(string $currency): int
    {
        $currencies = self::all();

        if (! array_key_exists($currency, $currencies)) {
            throw new InvalidArgumentException('Unsupported ISO 4217 currency.');
        }

        return $currencies[$currency];
    }

    public static function minor(string $amount, string $currency): int
    {
        $precision = self::precision($currency);

        if (! preg_match('/\A(\d+)(?:\.(\d+))?\z/', $amount, $matches)) {
            throw new InvalidArgumentException('The monetary amount must be a non-negative decimal string.');
        }

        $whole = ltrim($matches[1], '0') ?: '0';
        $fraction = $matches[2] ?? '';
        $significantExcess = substr($fraction, $precision);

        if ($significantExcess !== '' && trim($significantExcess, '0') !== '') {
            throw new InvalidArgumentException('The monetary amount exceeds the currency precision.');
        }

        $factor = 10 ** $precision;
        $maximumWhole = (string) intdiv(PHP_INT_MAX, $factor);

        if (
            strlen($whole) > strlen($maximumWhole)
            || (strlen($whole) === strlen($maximumWhole) && strcmp($whole, $maximumWhole) > 0)
        ) {
            throw new InvalidArgumentException('The monetary amount is too large.');
        }

        $wholeMinor = (int) $whole * $factor;
        $fractionMinor = (int) substr(str_pad($fraction, $precision, '0'), 0, $precision);

        if ($fractionMinor > PHP_INT_MAX - $wholeMinor) {
            throw new InvalidArgumentException('The monetary amount is too large.');
        }

        return $wholeMinor + $fractionMinor;
    }

    public static function format(int $minor, string $currency): string
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('The monetary amount must not be negative.');
        }

        $precision = self::precision($currency);
        $factor = 10 ** $precision;
        $whole = (string) intdiv($minor, $factor);

        if ($precision === 0) {
            return $whole;
        }

        return $whole.'.'.str_pad((string) ($minor % $factor), $precision, '0', STR_PAD_LEFT);
    }
}
