<?php

namespace App\Services\Payments;

use RuntimeException;

/** The payment gateway could not be reached or returned an unusable response. $retryable => safe to try again later. */
class GatewayException extends RuntimeException
{
    public function __construct(string $message, public bool $retryable = true, public ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
