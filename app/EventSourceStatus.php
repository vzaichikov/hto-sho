<?php

namespace App;

enum EventSourceStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'У черзі',
            self::Processing => 'Гусь роздивляється',
            self::Processed => 'Готово',
            self::Failed => 'Не розібрав',
        };
    }
}
