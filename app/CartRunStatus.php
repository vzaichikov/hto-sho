<?php

namespace App;

enum CartRunStatus: string
{
    case Running = 'running';
    case WaitingForAnswer = 'waiting_for_answer';
    case WaitingForConfirmation = 'waiting_for_confirmation';
    case Committing = 'committing';
    case Synced = 'synced';
    case Partial = 'partial';
    case Stale = 'stale';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return in_array($this, [
            self::Running,
            self::WaitingForAnswer,
            self::WaitingForConfirmation,
            self::Committing,
        ], true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Гусь збирає кошик',
            self::WaitingForAnswer => 'Гусь уперся й чекає',
            self::WaitingForConfirmation => 'Кошик чекає на ваше підтвердження',
            self::Committing => 'Гусь переносить товари',
            self::Synced => 'Кошик готовий',
            self::Partial => 'Кошик зібрано частково',
            self::Stale => 'Список або маршрут змінився',
            self::Failed => 'Гусь перечепився',
        };
    }
}
