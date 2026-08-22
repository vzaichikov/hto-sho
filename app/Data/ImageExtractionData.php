<?php

namespace App\Data;

use App\ImageClassification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class ImageExtractionData
{
    public function __construct(
        public ImageClassification $classification,
        public string $ocrText,
        /** @var array<int, array{sequence: int, author: ?string, text: string, visible_date: ?string, visible_time: ?string, is_quoted: bool}> */
        public array $messageTimeline,
        public string $summary,
        public ?string $dismissalReason,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function from(array $payload): self
    {
        $validated = Validator::make($payload, [
            'classification' => ['required', Rule::enum(ImageClassification::class)],
            'ocr_text' => ['present', 'string'],
            'message_timeline' => ['present', 'array'],
            'message_timeline.*.sequence' => ['required', 'integer', 'min:0'],
            'message_timeline.*.author' => ['nullable', 'string', 'max:255'],
            'message_timeline.*.text' => ['required', 'string', 'max:50000'],
            'message_timeline.*.visible_date' => ['nullable', 'string', 'max:255'],
            'message_timeline.*.visible_time' => ['nullable', 'string', 'max:64'],
            'message_timeline.*.is_quoted' => ['required', 'boolean'],
            'summary' => ['present', 'string'],
            'dismissal_reason' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        $classification = ImageClassification::from($validated['classification']);

        if ($classification === ImageClassification::Irrelevant && blank($validated['dismissal_reason'] ?? null)) {
            $validated['dismissal_reason'] = 'Гусь покрутив картинку дзьобом, але не знайшов тут чату, продуктів чи корисного контексту події. Відкладаємо.';
        }

        $messageTimeline = $classification === ImageClassification::ChatScreenshot
            ? array_map(fn (array $message): array => [
                'sequence' => $message['sequence'],
                'author' => filled($message['author'] ?? null) ? trim($message['author']) : null,
                'text' => trim($message['text']),
                'visible_date' => filled($message['visible_date'] ?? null) ? trim($message['visible_date']) : null,
                'visible_time' => filled($message['visible_time'] ?? null) ? trim($message['visible_time']) : null,
                'is_quoted' => $message['is_quoted'],
            ], $validated['message_timeline'])
            : [];

        usort($messageTimeline, fn (array $left, array $right): int => $left['sequence'] <=> $right['sequence']);

        return new self(
            classification: $classification,
            ocrText: trim($validated['ocr_text']),
            messageTimeline: $messageTimeline,
            summary: trim($validated['summary']),
            dismissalReason: $classification === ImageClassification::Irrelevant && isset($validated['dismissal_reason'])
                ? trim($validated['dismissal_reason'])
                : null,
        );
    }
}
