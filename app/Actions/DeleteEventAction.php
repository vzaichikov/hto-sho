<?php

namespace App\Actions;

use App\Models\Event;
use Illuminate\Support\Facades\Storage;

class DeleteEventAction
{
    public function execute(Event $event): void
    {
        $paths = $event->sources()
            ->whereNotNull('file_path')
            ->pluck('file_path')
            ->all();

        $event->delete();

        Storage::disk('local')->delete($paths);
        Storage::disk('local')->deleteDirectory("events/{$event->user_id}/{$event->id}");
    }
}
