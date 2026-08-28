<?php

namespace Tests\Feature;

use App\Data\ImageExtractionData;
use App\EventSourceInclusion;
use App\EventSourceStatus;
use App\EventSourceType;
use App\ImageClassification;
use App\ImageExtractionStatus;
use App\Jobs\ProcessImageExtractionJob;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\ImageExtraction;
use App\Models\User;
use App\Services\ContextAnalysisService;
use App\Services\HarnessRecorder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ImageExtractionJobTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::preventStrayRequests();
        config()->set([
            'services.ai.provider' => 'openai',
            'services.ai.model' => 'gpt-5.4-mini',
            'services.ai.api_key' => 'test-key',
        ]);
    }

    public function test_one_vision_request_classifies_ocrs_and_summarizes_an_image(): void
    {
        [$extraction, $source] = $this->queuedImage();
        $requestTimeout = null;
        Http::globalMiddleware(function (callable $handler) use (&$requestTimeout): callable {
            return function ($request, array $options) use ($handler, &$requestTimeout) {
                $requestTimeout = $options['timeout'] ?? null;

                return $handler($request, $options);
            };
        });
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse([
                'classification' => 'chat_screenshot',
                'ocr_text' => 'Оля: шашлик не їм, візьміть овочі.',
                'message_timeline' => [[
                    'sequence' => 0,
                    'author' => 'Оля',
                    'text' => 'Шашлик не їм, візьміть овочі.',
                    'visible_date' => 'Сьогодні',
                    'visible_time' => '10:18',
                    'is_quoted' => false,
                ]],
                'summary' => 'Оля просить овочі замість шашлику.',
                'dismissal_reason' => null,
            ])),
        ]);

        (new ProcessImageExtractionJob($extraction->id))
            ->handle($this->app->make(ContextAnalysisService::class), $this->app->make(HarnessRecorder::class));

        $extraction->refresh();
        $source->refresh();
        $this->assertSame(ImageExtractionStatus::Processed, $extraction->status);
        $this->assertSame(ImageClassification::ChatScreenshot, $extraction->classification);
        $this->assertSame('Оля: шашлик не їм, візьміть овочі.', $extraction->ocr_text);
        $this->assertSame('10:18', $extraction->message_timeline[0]['visible_time']);
        $this->assertFalse($extraction->message_timeline[0]['is_quoted']);
        $this->assertSame(EventSourceStatus::Processed, $source->status);
        $this->assertSame(EventSourceInclusion::Included, $source->inclusion);
        $this->assertSame(150, $requestTimeout);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses'
            && $request['text']['format']['strict'] === true
            && in_array('message_timeline', $request['text']['format']['schema']['required'], true)
            && $request['input'][0]['role'] === 'user'
            && str_contains($request['input'][0]['content'][0]['text'], '"source_type": "attached_image"')
            && str_contains($request['instructions'], 'Сторонній чат про роботу, техніку, новини, меми чи іншу тему — irrelevant')
            && str_contains($request['instructions'], 'Навушники, одяг, автомобіль та інший випадковий каталог товарів без звʼязку з подією — irrelevant')
            && str_contains($request['instructions'], 'короткі повідомлення «Я буду», «О 15:00» чи «Беру лід» можуть бути chat_screenshot')
            && str_contains($request['instructions'], 'пиши з легкою самоіронією від «Гуся Шо»')
            && str_starts_with($request['input'][0]['content'][1]['image_url'], 'data:image/png;base64,'));
    }

    public function test_image_timeout_fits_the_queue_visibility_budget(): void
    {
        $job = new ProcessImageExtractionJob(1);

        $this->assertSame(150, config('services.ai.image_request_timeout'));
        $this->assertSame(170, $job->timeout);
        $this->assertSame(900, $job->uniqueFor);
        $this->assertGreaterThan(config('services.ai.image_request_timeout'), $job->timeout);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
    }

    public function test_irrelevant_image_is_processed_but_dismissed_with_a_reason(): void
    {
        [$extraction, $source] = $this->queuedImage();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse([
                'classification' => 'irrelevant',
                'ocr_text' => '',
                'message_timeline' => [],
                'summary' => '',
                'dismissal_reason' => 'Кіт поважний, але до гостей, меню чи закупів для події він тут ні до чого. Гусь відклав це фото вбік.',
            ])),
        ]);

        (new ProcessImageExtractionJob($extraction->id))
            ->handle($this->app->make(ContextAnalysisService::class), $this->app->make(HarnessRecorder::class));

        $this->assertSame(ImageClassification::Irrelevant, $extraction->refresh()->classification);
        $this->assertSame([], $extraction->message_timeline);
        $this->assertSame(
            'Кіт поважний, але до гостей, меню чи закупів для події він тут ні до чого. Гусь відклав це фото вбік.',
            $extraction->dismissal_reason,
        );
        $this->assertSame(EventSourceInclusion::Dismissed, $source->refresh()->inclusion);
        $this->assertSame(1, $source->event->refresh()->evidence_version);
    }

    public function test_irrelevant_dto_uses_goose_mood_fallback_and_relevant_dto_drops_a_reason(): void
    {
        $irrelevant = ImageExtractionData::from([
            'classification' => 'irrelevant',
            'ocr_text' => '',
            'message_timeline' => [],
            'summary' => '',
            'dismissal_reason' => null,
        ]);
        $relevant = ImageExtractionData::from([
            'classification' => 'product_image',
            'ocr_text' => 'Вугілля 2,5 кг',
            'message_timeline' => [],
            'summary' => 'Пакет вугілля.',
            'dismissal_reason' => 'Зайва причина від моделі.',
        ]);

        $this->assertSame(
            'Гусь покрутив картинку дзьобом, але не знайшов тут чату, продуктів чи корисного контексту події. Відкладаємо.',
            $irrelevant->dismissalReason,
        );
        $this->assertNull($relevant->dismissalReason);
    }

    public function test_ollama_adapter_uses_json_mode_and_returns_the_same_dto(): void
    {
        config()->set([
            'services.ai.provider' => 'ollama',
            'services.ai.model' => 'qwen3.5:397b',
            'services.ai.api_key' => 'ollama-test-key',
        ]);
        Http::fake([
            'https://ollama.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'classification' => 'product_image',
                    'ocr_text' => 'Вугілля 2,5 кг',
                    'message_timeline' => [],
                    'summary' => 'Пакет вугілля вагою 2,5 кг.',
                    'dismissal_reason' => null,
                ], JSON_UNESCAPED_UNICODE)]]],
            ]),
        ]);

        $result = $this->app->make(ContextAnalysisService::class)
            ->extractImage('image-bytes', 'image/png');

        $this->assertSame(ImageClassification::ProductImage, $result->classification);
        $this->assertSame([], $result->messageTimeline);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ollama.com/v1/chat/completions'
            && $request['response_format']['type'] === 'json_object'
            && data_get($request->data(), 'messages.0.role') === 'system'
            && data_get($request->data(), 'messages.1.role') === 'user'
            && str_contains((string) data_get($request->data(), 'messages.0.content.0.text'), 'OCR')
            && str_contains((string) data_get($request->data(), 'messages.1.content.0.text'), '"source_type": "attached_image"')
            && data_get($request->data(), 'messages.1.content.1.image_url.url') === 'data:image/png;base64,'.base64_encode('image-bytes'));
    }

    public function test_final_job_failure_updates_extraction_and_every_reference(): void
    {
        [$extraction, $source] = $this->queuedImage();

        (new ProcessImageExtractionJob($extraction->id))->failed(new RuntimeException('Vision timeout'));

        $this->assertSame(ImageExtractionStatus::Failed, $extraction->refresh()->status);
        $this->assertSame(EventSourceStatus::Failed, $source->refresh()->status);
        $this->assertSame('Vision timeout', $source->processing_error);
    }

    /** @return array{ImageExtraction, EventSource} */
    private function queuedImage(): array
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['evidence_version' => 0]);
        $extraction = ImageExtraction::factory()->for($user)->create([
            'status' => ImageExtractionStatus::Pending,
            'classification' => null,
            'ocr_text' => null,
            'message_timeline' => null,
            'source_summary' => null,
            'processed_at' => null,
        ]);
        $path = "events/{$user->id}/{$event->id}/chat.png";
        Storage::disk('local')->put($path, 'image-bytes');
        $source = EventSource::factory()->for($event)->create([
            'image_extraction_id' => $extraction->id,
            'type' => EventSourceType::Image,
            'text' => null,
            'file_path' => $path,
            'original_name' => 'chat.png',
            'mime_type' => 'image/png',
            'status' => EventSourceStatus::Pending,
            'processed_at' => null,
        ]);

        return [$extraction, $source];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function openAiResponse(array $payload): array
    {
        return [
            'output' => [[
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ]],
            ]],
        ];
    }
}
