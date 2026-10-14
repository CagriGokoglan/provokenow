<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A request that is well-formed but violates a domain rule (out of stock, already owned, ...). */
class BusinessRuleException extends RuntimeException
{
    /** @param array<string, mixed>|null $errors */
    public function __construct(string $message, private readonly int $status = 422, private readonly ?array $errors = null)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->getMessage(), $this->status, $this->errors);
    }
}
