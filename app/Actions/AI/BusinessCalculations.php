<?php

namespace App\Actions\AI;

use App\Currency;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use UnexpectedValueException;

class BusinessCalculations
{
    private const string MaximumInput = '1000000000000';

    private const string MaximumResult = '1000000000000000';

    private const int WorkingPrecision = 28;

    /**
     * @return array{answer: string, conversion?: array{amount: string, from: string, to: string, result: string, rate: string, rate_date: string, retrieved_at: string, source: string, source_url: string, indicative: bool}}
     */
    public function convert(string $amount, string $from, string $to): array
    {
        if (! extension_loaded('bcmath')) {
            return ['answer' => __('Currency calculation is temporarily unavailable. Please try again later.')];
        }

        $from = $this->currencyCode($from);
        $to = $this->currencyCode($to);

        if ($from === null || $to === null) {
            return ['answer' => __('Please specify supported currencies, such as USD, EUR or KES.')];
        }

        $amount = $this->decimal($amount);

        if ($amount === null || str_starts_with($amount, '-')) {
            return ['answer' => __('Please provide a non-negative decimal amount no greater than 1,000,000,000,000.')];
        }

        try {
            $formattedAmount = Currency::format(Currency::minor($amount, $from), $from);
        } catch (InvalidArgumentException) {
            return ['answer' => __('That amount exceeds the source currency precision. Please use a valid monetary amount.')];
        }

        if ($from === $to) {
            return ['answer' => __(':amount :from is already :amount :to; no exchange rate is needed.', ['amount' => $formattedAmount, 'from' => $from, 'to' => $to])];
        }

        try {
            $rate = Cache::remember(
                'ask.fx.frankfurter.v2.'.$from.'.'.$to,
                now()->addMinutes(15),
                function () use ($from, $to): array {
                    $response = Http::acceptJson()
                        ->connectTimeout(2)
                        ->timeout(5)
                        ->withoutRedirecting()
                        ->get('https://api.frankfurter.dev/v2/rate/'.$from.'/'.$to);

                    if ($response->status() !== 200) {
                        throw new UnexpectedValueException('Exchange rate unavailable.');
                    }

                    $data = $this->validatedRate($response->json(), $from, $to);

                    if ($data === null) {
                        throw new UnexpectedValueException('Invalid exchange rate response.');
                    }

                    return $data;
                },
            );
        } catch (ConnectionException|UnexpectedValueException) {
            return ['answer' => __('I could not obtain a current, verified exchange rate for :from to :to. Please try again later; I have not estimated a conversion.', ['from' => $from, 'to' => $to])];
        }

        $rawResult = bcmul($amount, $rate['rate'], self::WorkingPrecision);

        if (bccomp($rawResult, self::MaximumResult, self::WorkingPrecision) > 0) {
            return ['answer' => __('The converted amount is outside the supported calculation range. Please use a smaller amount.')];
        }

        $result = $this->round($rawResult, Currency::precision($to));

        return [
            'answer' => __(':amount :from is approximately :result :to, using 1 :from = :rate :to.', ['amount' => $formattedAmount, 'from' => $from, 'result' => $result, 'to' => $to, 'rate' => $rate['rate']]).' '
                .__('Source: [Frankfurter reference rates](https://frankfurter.dev/), dated :date (retrieved :retrieved).', ['date' => $rate['rate_date'], 'retrieved' => $rate['retrieved_at']]).' '
                .__("This is an indicative conversion; PayPal's exchange rates and fees may differ."),
            'conversion' => [
                'amount' => $formattedAmount,
                'from' => $from,
                'to' => $to,
                'result' => $result,
                ...$rate,
                'source' => 'Frankfurter',
                'source_url' => 'https://frankfurter.dev/',
                'indicative' => true,
            ],
        ];
    }

    /**
     * @return array{answer: string, calculation?: array{operation: string, left: string, right: string, result: string, approximate: bool}}
     */
    public function calculate(string $operation, string $left, string $right): array
    {
        if (! extension_loaded('bcmath')) {
            return ['answer' => __('Precise calculation is temporarily unavailable. Please try again later.')];
        }

        if (! in_array($operation, ['percent', 'add', 'subtract', 'multiply', 'divide'], true)) {
            return ['answer' => __('I can add, subtract, multiply, divide or calculate a percentage using two decimal numbers.')];
        }

        $left = $this->decimal($left);
        $right = $this->decimal($right);

        if ($left === null || $right === null) {
            return ['answer' => __('Please provide decimal numbers with at most 12 decimal places and an absolute value no greater than 1,000,000,000,000.')];
        }

        if ($operation === 'divide' && bccomp($right, '0', self::WorkingPrecision) === 0) {
            return ['answer' => __('Division by zero is undefined. Please choose a non-zero divisor.')];
        }

        $rawResult = match ($operation) {
            'percent' => bcdiv(bcmul($left, $right, self::WorkingPrecision), '100', self::WorkingPrecision),
            'add' => bcadd($left, $right, self::WorkingPrecision),
            'subtract' => bcsub($left, $right, self::WorkingPrecision),
            'multiply' => bcmul($left, $right, self::WorkingPrecision),
            'divide' => bcdiv($left, $right, self::WorkingPrecision),
        };

        if (bccomp(ltrim($rawResult, '-'), self::MaximumResult, self::WorkingPrecision) > 0) {
            return ['answer' => __('The result is outside the supported calculation range. Please use smaller numbers.')];
        }

        $result = $this->compact($this->round($rawResult, 12));
        $approximate = bccomp($rawResult, $result, self::WorkingPrecision) !== 0;
        $equals = $approximate ? __('is approximately') : __('is');
        $expression = match ($operation) {
            'percent' => __(':left% of :right', ['left' => $left, 'right' => $right]),
            'add' => "{$left} + {$right}",
            'subtract' => "{$left} - {$right}",
            'multiply' => "{$left} × {$right}",
            'divide' => "{$left} ÷ {$right}",
        };

        return [
            'answer' => "{$expression} {$equals} {$result}.",
            'calculation' => [
                'operation' => $operation,
                'left' => $left,
                'right' => $right,
                'result' => $result,
                'approximate' => $approximate,
            ],
        ];
    }

    private function currencyCode(string $currency): ?string
    {
        $currency = strtolower(trim($currency));
        $currency = match ($currency) {
            '$', 'dollar', 'dollars', 'us dollar', 'us dollars' => 'USD',
            '€', 'euro', 'euros' => 'EUR',
            'ksh', 'kshs', 'kenyan shilling', 'kenyan shillings' => 'KES',
            '£', 'pound', 'pounds', 'british pound', 'british pounds' => 'GBP',
            default => strtoupper($currency),
        };

        return preg_match('/\A[A-Z]{3}\z/', $currency) && array_key_exists($currency, Currency::all())
            ? $currency
            : null;
    }

    /**
     * @return numeric-string|null
     */
    private function decimal(string $value): ?string
    {
        $value = trim($value);

        if (strlen($value) > 40 || ! is_numeric($value) || ! preg_match('/\A-?\d+(?:\.\d{1,12})?\z/', $value)) {
            return null;
        }

        if (bccomp($value, self::MaximumInput, self::WorkingPrecision) > 0
            || bccomp($value, '-'.self::MaximumInput, self::WorkingPrecision) < 0) {
            return null;
        }

        return $this->compact(bcadd($value, '0', 12));
    }

    /**
     * @return array{rate: numeric-string, rate_date: string, retrieved_at: string}|null
     */
    private function validatedRate(mixed $data, string $from, string $to): ?array
    {
        if (! is_array($data) || ($data['base'] ?? null) !== $from || ($data['quote'] ?? null) !== $to) {
            return null;
        }

        $date = $data['date'] ?? null;
        $rawRate = $data['rate'] ?? null;

        if (! is_string($date) || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)) {
            return null;
        }

        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date
            || $date > now('UTC')->toDateString() || $date < now('UTC')->subDays(7)->toDateString()) {
            return null;
        }

        if (! is_int($rawRate) && ! is_float($rawRate) && ! is_string($rawRate)) {
            return null;
        }

        if (is_float($rawRate)) {
            if (! is_finite($rawRate)) {
                return null;
            }

            $rawRate = sprintf('%.12F', $rawRate);
        }

        $rate = (string) $rawRate;

        if (strlen($rate) > 30 || ! is_numeric($rate) || ! preg_match('/\A\d+(?:\.\d{1,12})?\z/', $rate)
            || bccomp($rate, '0', 12) <= 0 || bccomp($rate, '1000000000', 12) > 0) {
            return null;
        }

        return [
            'rate' => $this->compact($rate),
            'rate_date' => $date,
            'retrieved_at' => now('UTC')->format('Y-m-d H:i:s').' UTC',
        ];
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private function round(string $value, int $precision): string
    {
        return bcround($value, $precision);
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private function compact(string $value): string
    {
        $value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
        $value = $value === '-0' ? '0' : $value;

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('The calculation must remain a decimal number.');
        }

        return $value;
    }
}
