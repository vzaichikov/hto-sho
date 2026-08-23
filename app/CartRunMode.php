<?php

namespace App;

enum CartRunMode: string
{
    case Assisted = 'assisted';
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Assisted => 'Спитає лише в глухому куті',
            self::Auto => 'Сам розрулить',
        };
    }
}
