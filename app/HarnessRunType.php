<?php

namespace App;

enum HarnessRunType: string
{
    case DescriptionReview = 'description_review';
    case ImageExtraction = 'image_extraction';
    case ContextSynthesis = 'context_synthesis';
    case ShoppingPlan = 'shopping_plan';
    case SilpoPreflight = 'silpo_preflight';
    case SilpoCart = 'silpo_cart';

    public function label(): string
    {
        return match ($this) {
            self::DescriptionReview => 'Перевірка опису',
            self::ImageExtraction => 'Розбір зображення',
            self::ContextSynthesis => 'Збір контексту',
            self::ShoppingPlan => 'План закупів',
            self::SilpoPreflight => 'Перевірка Сільпо',
            self::SilpoCart => 'Наповнення кошика',
        };
    }
}
