<?php

namespace App;

enum EventDescriptionReviewReason: string
{
    case Accepted = 'accepted';
    case Unrelated = 'unrelated';
    case Meaningless = 'meaningless';

    public function message(): string
    {
        return match ($this) {
            self::Accepted => 'О, це вже схоже на смачну історію.',
            self::Unrelated => 'Гусь покрутив задум дзьобом і не знайшов тут їжі, напоїв чи самої гулянки. Додайте, що за подія і який у неї смак.',
            self::Meaningless => 'Гусь прочитав. Потім ще раз. Дзьобом теж спробував — задум не склався. Напишіть коротко: пікнік, шашлик, вечірка або «хочемо щось нове».',
        };
    }
}
