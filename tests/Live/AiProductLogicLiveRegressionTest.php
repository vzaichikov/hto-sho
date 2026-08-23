<?php

namespace Tests\Live;

use App\Services\ContextAnalysisService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AiProductLogicScenarioRepository;
use Tests\Support\AssertsAiProductLogic;
use Tests\TestCase;

#[Group('live-ai')]
class AiProductLogicLiveRegressionTest extends TestCase
{
    use AssertsAiProductLogic;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('AI_LIVE_REGRESSIONS') !== '1') {
            $this->markTestSkipped('Set AI_LIVE_REGRESSIONS=1 to allow real model calls.');
        }

        $provider = (string) config('services.ai.provider');
        $baseUrl = config("services.ai.providers.{$provider}.base_url");

        $this->assertNotEmpty(config('services.ai.api_key'), 'AI_API_KEY must be configured for live regressions.');
        $this->assertNotEmpty(config('services.ai.model'), 'AI_MODEL must be configured for live regressions.');
        $this->assertIsString($baseUrl, 'AI_PROVIDER must reference a configured OpenAI-compatible provider.');

        Http::preventStrayRequests();
        Http::allowStrayRequests([rtrim($baseUrl, '/').'/*']);
    }

    /** @param array<string, mixed> $scenario */
    #[DataProvider('contextScenarios')]
    public function test_configured_model_understands_context_scenario(array $scenario): void
    {
        $state = $this->app->make(ContextAnalysisService::class)
            ->summarizeEvent($scenario['organizer_context'], $scenario['source_batches'])
            ->state;

        $this->assertAiState(
            $state,
            $scenario['expect']['state'],
            AiProductLogicScenarioRepository::label($scenario),
        );
    }

    /** @param array<string, mixed> $scenario */
    #[DataProvider('imageScenarios')]
    public function test_configured_model_understands_image_scenario(array $scenario): void
    {
        $scenarioLabel = AiProductLogicScenarioRepository::label($scenario);
        $path = __DIR__.'/../Fixtures/AiProductLogic/'.$scenario['image']['path'];
        $contents = file_get_contents($path);
        $this->assertIsString($contents, $this->failure($scenarioLabel, 'Image fixture cannot be read.'));

        $image = $this->app->make(ContextAnalysisService::class)
            ->extractImage($contents, $scenario['image']['mime_type']);
        $this->assertAiImage($image, $scenario['expect']['image'], $scenarioLabel);

        $sourceBatches = $image->classification->value === 'irrelevant' ? [] : [[
            'upload_batch' => $scenario['image']['upload_batch'],
            'batch_uploaded_at' => $scenario['image']['uploaded_at'],
            'sources' => [[
                'source_id' => $scenario['image']['source_id'],
                'kind' => 'image',
                'origin' => 'organizer_context',
                'uploaded_at' => $scenario['image']['uploaded_at'],
                'position' => $scenario['image']['position'],
                'classification' => $image->classification->value,
                'ocr_text' => $image->ocrText,
                'message_timeline' => $image->messageTimeline,
                'source_summary' => $image->summary,
            ]],
        ]];
        $state = $this->app->make(ContextAnalysisService::class)
            ->summarizeEvent($scenario['organizer_context'], $sourceBatches)
            ->state;

        $this->assertAiState($state, $scenario['expect']['state'], $scenarioLabel);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function contextScenarios(): array
    {
        return AiProductLogicScenarioRepository::forPipelines('context');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function imageScenarios(): array
    {
        return AiProductLogicScenarioRepository::forPipelines('image_context');
    }
}
