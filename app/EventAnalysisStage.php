<?php

namespace App;

enum EventAnalysisStage: string
{
    case WaitingForQuiet = 'waiting_for_quiet';
    case WaitingForImages = 'waiting_for_images';
    case Summarizing = 'summarizing';
    case Completed = 'completed';
    case CompletedWithWarnings = 'completed_with_warnings';
    case Failed = 'failed';

    public function progress(): int
    {
        return match ($this) {
            self::WaitingForQuiet => 10,
            self::WaitingForImages => 20,
            self::Summarizing => 82,
            self::Completed, self::CompletedWithWarnings, self::Failed => 100,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::WaitingForQuiet => 'Ніфіга собі ви тут понаписували. Гусь рахує до пʼяти.',
            self::WaitingForImages => 'Гусь Шо дивиться на ваші картинки. Пильно. Трохи осудливо.',
            self::Summarizing => 'Оце у вас вимоги. Складаю все докупи.',
            self::Completed => 'Готово. Гусь усе розгріб і навіть не дуже бурчав.',
            self::CompletedWithWarnings => 'Готово, але кілька картинок дали Гусю відкоша.',
            self::Failed => 'Гусь заплутався. Тут потрібна людська рука.',
        };
    }
}
