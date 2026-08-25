<?php

namespace App\Data;

use App\Models\SilpoCartReset;

final readonly class ConfirmedSilpoFulfilmentData
{
    public function __construct(
        public SilpoCartContextData $cart,
        public SilpoCartReset $reset,
    ) {}
}
