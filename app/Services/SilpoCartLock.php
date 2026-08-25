<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

class SilpoCartLock
{
    public function execute(int $userId, Closure $callback): mixed
    {
        return Cache::lock('silpo-cart:user:'.$userId, 120)->block(3, $callback);
    }
}
