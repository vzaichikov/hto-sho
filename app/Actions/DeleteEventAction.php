<?php

namespace App\Actions;

use App\Models\Event;
use App\Models\EventSource;
use App\Models\ImageExtraction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteEventAction
{
    public function execute(Event $event): void
    {
        $paths = DB::transaction(function () use ($event): array {
            $paths = $event->sources()
                ->whereNotNull('file_path')
                ->pluck('file_path')
                ->all();
            $extractionIds = $event->sources()
                ->whereNotNull('image_extraction_id')
                ->pluck('image_extraction_id')
                ->unique()
                ->all();

            $event->delete();

            foreach ($extractionIds as $extractionId) {
                if (! EventSource::query()->where('image_extraction_id', $extractionId)->exists()) {
                    ImageExtraction::query()->whereKey($extractionId)->delete();
                }
            }

            return $paths;
        });

        Storage::disk('local')->delete($paths);
        Storage::disk('local')->deleteDirectory("events/{$event->user_id}/{$event->id}");
    }
}
