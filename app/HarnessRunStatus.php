<?php

namespace App;

enum HarnessRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Виконується',
            self::Completed => 'Завершено',
            self::Failed => 'Помилка',
        };
    }
}
