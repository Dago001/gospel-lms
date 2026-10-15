<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A business-rule violation that is safe to show to the client (HTTP 422 with a stable machine code). */
class DomainException extends RuntimeException
{
    public function __construct(string $message, public string $errorCode = 'domain_error', public int $status = 422, public array $details = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter(['message' => $this->getMessage(), 'code' => $this->errorCode, 'details' => $this->details ?: null]), $this->status);
    }
}
