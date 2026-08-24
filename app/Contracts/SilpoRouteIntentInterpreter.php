<?php

namespace App\Contracts;

use App\Data\SilpoRouteIntentData;
use App\Models\HarnessRun;
use Carbon\CarbonImmutable;

interface SilpoRouteIntentInterpreter
{
    public function interpret(
        string $sentence,
        CarbonImmutable $currentDate,
        string $timezone,
        ?HarnessRun $harnessRun = null,
    ): SilpoRouteIntentData;
}
