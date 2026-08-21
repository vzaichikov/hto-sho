<?php

namespace App;

enum EventStatus: string
{
    case Draft = 'draft';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Чернетка',
            self::Processing => 'Аналізуємо',
            self::Ready => 'Готово',
            self::Failed => 'Потрібна увага',
        };
    }
}
