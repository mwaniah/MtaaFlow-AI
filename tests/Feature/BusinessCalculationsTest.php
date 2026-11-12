<?php

use App\Actions\AI\BusinessCalculations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('conversions use verified reference rates and identify the source date and PayPal caveat', function () {
    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(12, 0));
    Http::preventStrayRequests();
    Http::fake([
        'https://api.frankfurter.dev/v2/rate/USD/KES' => function (Request $request, array $options) {
            expect($options)->toMatchArray([
                'connect_timeout' => 2,
                'timeout' => 5,
                'allow_redirects' => false,
            ]);
            expect($request->method())->toBe('GET');
            expect($request->data())->toBe([]);

            return Http::response(['date' => '2026-11-10', 'base' => 'USD', 'quote' => 'KES', 'rate' => 129.81]);
        },
    ]);

    $result = app(BusinessCalculations::class)->convert('2', 'dollars', 'Kenyan shillings');

    expect($result['conversion'])->toMatchArray([
        'amount' => '2.00',
        'from' => 'USD',
        'to' => 'KES',
        'result' => '259.62',
        'rate' => '129.81',
        'rate_date' => '2026-11-10',
        'source' => 'Frankfurter',
        'source_url' => 'https://frankfurter.dev/',
        'indicative' => true,
    ]);
    expect($result['answer'])->toContain('2.00 USD', '259.62 KES', 'Frankfurter', '2026-11-10', 'indicative', "PayPal's exchange rates and fees may differ");
    Http::assertSentCount(1);
});

test('conversion rounds half up to the target currency precision', function (string $target, string $rate, string $expected) {
    $this->freezeTime();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.frankfurter.dev/v2/rate/USD/'.$target => Http::response([
            'date' => now('UTC')->toDateString(), 'base' => 'USD', 'quote' => $target, 'rate' => $rate,
        ]),
    ]);

    $result = app(BusinessCalculations::class)->convert('2', '$', $target);

    expect($result['conversion']['result'])->toBe($expected);
    Http::assertSentCount(1);
})->with([
    'two decimal shillings' => ['KES', '129.8125', '259.63'],
    'zero decimal yen' => ['JPY', '150.25', '301'],
    'three decimal dinars' => ['KWD', '0.30625', '0.613'],
]);

test('valid rates are reused for fifteen minutes without caching individual amounts', function () {
    $this->freezeTime();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.frankfurter.dev/v2/rate/EUR/USD' => Http::sequence()
            ->push(['date' => now('UTC')->toDateString(), 'base' => 'EUR', 'quote' => 'USD', 'rate' => '1.15'])
            ->push(['date' => now('UTC')->toDateString(), 'base' => 'EUR', 'quote' => 'USD', 'rate' => '1.16']),
    ]);
    $calculator = app(BusinessCalculations::class);

    $first = $calculator->convert('100', '€', 'USD');
    $cached = $calculator->convert('200', 'EUR', 'USD');
    $this->travel(16)->minutes();
    $refreshed = $calculator->convert('100', 'EUR', 'USD');

    expect($first['conversion']['result'])->toBe('115.00');
    expect($cached['conversion']['result'])->toBe('230.00');
    expect($refreshed['conversion']['result'])->toBe('116.00');
    Http::assertSentCount(2);
});

test('an exchange service HTTP failure returns no fabricated result and is not cached or retried', function (int $status) {
    $this->freezeTime();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.frankfurter.dev/v2/rate/USD/KES' => Http::response(['message' => 'Private upstream diagnostic'], $status),
    ]);
    $calculator = app(BusinessCalculations::class);

    $first = $calculator->convert('2', 'USD', 'KES');
    $second = $calculator->convert('3', 'USD', 'KES');

    expect($first)->toHaveKeys(['answer'])->not->toHaveKey('conversion');
    expect($first['answer'])->toContain('could not obtain', 'have not estimated')->not->toContain('Private upstream diagnostic');
    expect($second)->not->toHaveKey('conversion');
    Http::assertSentCount(2);
})->with([302, 404, 429, 503]);

test('connection timeouts return a helpful answer without an estimate or retry', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.frankfurter.dev/v2/rate/USD/KES' => Http::failedConnection('Connection timed out'),
    ]);

    $result = app(BusinessCalculations::class)->convert('2', 'USD', 'KES');

    expect($result)->not->toHaveKey('conversion');
    expect($result['answer'])->toContain('Please try again later', 'have not estimated')->not->toContain('Connection timed out');
    Http::assertSentCount(1);
});

test('malformed stale or mismatched exchange rates never produce conversion answers', function (array $changes) {
    $this->travelTo(now('UTC')->setDate(2026, 11, 10)->setTime(12, 0));
    Http::preventStrayRequests();
    Http::fake([
        'https://api.frankfurter.dev/v2/rate/USD/KES' => Http::response(array_replace([
            'date' => '2026-11-10', 'base' => 'USD', 'quote' => 'KES', 'rate' => 129.81,
        ], $changes)),
    ]);

    $result = app(BusinessCalculations::class)->convert('2', 'USD', 'KES');

    expect($result)->not->toHaveKey('conversion');
    expect($result['answer'])->toContain('could not obtain', 'have not estimated');
    Http::assertSentCount(1);
})->with([
    'wrong base' => [['base' => 'EUR']],
    'wrong quote' => [['quote' => 'EUR']],
    'zero rate' => [['rate' => 0]],
    'negative rate' => [['rate' => -1]],
    'unbounded rate' => [['rate' => '1000000001']],
    'not a number' => [['rate' => 'NaN']],
    'exponent text' => [['rate' => '1e20']],
    'structured rate' => [['rate' => ['value' => 129.81]]],
    'boolean rate' => [['rate' => true]],
    'missing rate' => [['rate' => null]],
    'stale date' => [['date' => '2026-11-02']],
    'future date' => [['date' => '2026-11-11']],
    'invalid calendar date' => [['date' => '2026-11-31']],
    'invalid date type' => [['date' => ['2026-11-10']]],
]);

test('non JSON exchange responses are unavailable instead of being exposed', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.frankfurter.dev/v2/rate/USD/KES' => Http::response('<html>Internal gateway failure</html>'),
    ]);

    $result = app(BusinessCalculations::class)->convert('2', 'USD', 'KES');

    expect($result)->not->toHaveKey('conversion');
    expect($result['answer'])->not->toContain('<html>', 'Internal gateway');
    Http::assertSentCount(1);
});

test('currency and amount validation prevents malformed outbound requests', function (string $amount, string $from, string $to, string $message) {
    Http::preventStrayRequests();

    $result = app(BusinessCalculations::class)->convert($amount, $from, $to);

    expect($result)->not->toHaveKey('conversion');
    expect($result['answer'])->toContain($message);
    Http::assertNothingSent();
})->with([
    'model supplied URL' => ['2', 'https://attacker.test', 'KES', 'supported currencies'],
    'unknown currency' => ['2', 'USD', 'ZZZ', 'supported currencies'],
    'negative amount' => ['-2', 'USD', 'KES', 'non-negative'],
    'invalid expression' => ['2+2', 'USD', 'KES', 'non-negative'],
    'input range' => ['1000000000001', 'USD', 'KES', 'no greater than'],
    'source precision' => ['2.001', 'USD', 'KES', 'source currency precision'],
    'source zero precision' => ['2.5', 'JPY', 'KES', 'source currency precision'],
]);

test('identical currencies require no external exchange service', function () {
    Http::preventStrayRequests();

    $result = app(BusinessCalculations::class)->convert('25', 'euros', 'EUR');

    expect($result['answer'])->toBe('25.00 EUR is already 25.00 EUR; no exchange rate is needed.');
    Http::assertNothingSent();
});

test('conversion results outside the supported range are rejected', function () {
    $this->freezeTime();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.frankfurter.dev/v2/rate/USD/KES' => Http::response([
            'date' => now('UTC')->toDateString(), 'base' => 'USD', 'quote' => 'KES', 'rate' => 2000,
        ]),
    ]);

    $result = app(BusinessCalculations::class)->convert('1000000000000', 'USD', 'KES');

    expect($result)->not->toHaveKey('conversion');
    expect($result['answer'])->toContain('outside the supported calculation range');
    Http::assertSentCount(1);
});

test('server side arithmetic preserves decimal precision and readable answers', function (string $operation, string $left, string $right, string $expected, bool $approximate) {
    Http::preventStrayRequests();

    $result = app(BusinessCalculations::class)->calculate($operation, $left, $right);

    expect($result['calculation'])->toMatchArray([
        'operation' => $operation, 'result' => $expected, 'approximate' => $approximate,
    ]);
    expect($result['answer'])->toContain($expected)->not->toContain('{', '}');
    Http::assertNothingSent();
})->with([
    'percentage' => ['percent', '15', '250', '37.5', false],
    'decimal addition' => ['add', '0.1', '0.2', '0.3', false],
    'subtraction' => ['subtract', '12.50', '15.25', '-2.75', false],
    'multiplication' => ['multiply', '-12.5', '2.4', '-30', false],
    'exact division' => ['divide', '5', '2', '2.5', false],
    'repeating division' => ['divide', '1', '3', '0.333333333333', true],
    'positive halfway rounding' => ['multiply', '0.000000000001', '0.5', '0.000000000001', true],
    'negative halfway rounding' => ['multiply', '-0.000000000001', '0.5', '-0.000000000001', true],
    'normalizes leading zeros' => ['add', '0002.00', '0003.00', '5', false],
]);

test('arithmetic refuses unsafe expressions zero divisors and out of range results', function (string $operation, string $left, string $right, string $message) {
    Http::preventStrayRequests();

    $result = app(BusinessCalculations::class)->calculate($operation, $left, $right);

    expect($result)->not->toHaveKey('calculation');
    expect($result['answer'])->toContain($message);
    Http::assertNothingSent();
})->with([
    'unsupported operation' => ['eval', '1', '2', 'add, subtract'],
    'zero divisor' => ['divide', '2', '-0.00', 'Division by zero'],
    'expression injection' => ['add', 'phpinfo()', '2', 'decimal numbers'],
    'exponent input' => ['add', '1e12', '2', 'decimal numbers'],
    'input size' => ['add', '1000000000001', '2', 'no greater than'],
    'decimal precision' => ['add', '0.0000000000001', '2', 'at most 12 decimal'],
    'result size' => ['multiply', '1000000000000', '1000000000000', 'outside the supported calculation range'],
]);
