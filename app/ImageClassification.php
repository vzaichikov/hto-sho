<?php

namespace App;

enum ImageClassification: string
{
    case ChatScreenshot = 'chat_screenshot';
    case ProductImage = 'product_image';
    case Irrelevant = 'irrelevant';

    public function label(): string
    {
        return match ($this) {
            self::ChatScreenshot => 'Скриншот чату',
            self::ProductImage => 'Товар або продукт',
            self::Irrelevant => 'Не схоже на корисне джерело',
        };
    }
}
