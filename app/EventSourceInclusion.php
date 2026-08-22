<?php

namespace App;

enum EventSourceInclusion: string
{
    case Included = 'included';
    case Dismissed = 'dismissed';
    case Forced = 'forced';

    public function isIncluded(): bool
    {
        return $this !== self::Dismissed;
    }
}
