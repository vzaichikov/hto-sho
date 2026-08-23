<?php

namespace Tests\Support;

use JsonException;
use RuntimeException;

final class AiProductLogicScenarioRepository
{
    /** @return array<string, array{array<string, mixed>}> */
    public static function forPipelines(string ...$pipelines): array
    {
        $datasets = [];

        foreach (self::all() as $scenario) {
            if (! in_array($scenario['pipeline'], $pipelines, true)) {
                continue;
            }

            $datasets[self::label($scenario)] = [$scenario];
        }

        return $datasets;
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        $paths = glob(__DIR__.'/../Fixtures/AiProductLogic/*.json');

        if ($paths === false || $paths === []) {
            throw new RuntimeException('AI product-logic regression fixtures were not found.');
        }

        sort($paths);

        return array_map(function (string $path): array {
            try {
                $scenario = json_decode(
                    file_get_contents($path) ?: '',
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $exception) {
                throw new RuntimeException("Invalid AI regression fixture [{$path}].", previous: $exception);
            }

            if (! is_array($scenario) || ! isset($scenario['id'], $scenario['title'], $scenario['pipeline'])) {
                throw new RuntimeException("Incomplete AI regression fixture [{$path}].");
            }

            return $scenario;
        }, $paths);
    }

    /** @param array<string, mixed> $scenario */
    public static function label(array $scenario): string
    {
        return $scenario['id'].' — '.$scenario['title'];
    }
}
