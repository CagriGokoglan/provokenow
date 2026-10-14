<?php

namespace App\Services\Payments;

final readonly class CheckoutRedirect
{
    public function __construct(
        public string $url,
        /** Provider session reference (e.g. Stripe cs_...). */
        public string $reference,
    ) {}
}
