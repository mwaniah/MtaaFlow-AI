<?php

namespace App\Actions\Payments;

use RuntimeException;
use Throwable;

class PayPalGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 502,
        ?Throwable $previous = null,
        public readonly ?int $providerStatus = null,
        public readonly ?string $providerIssue = null,
        public readonly ?string $debugId = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
