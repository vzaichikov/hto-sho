<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ShareTargetController extends Controller
{
    public function __invoke(Request $request): View
    {
        $events = collect();
        $hasPendingShare = $request->boolean('shared')
            || $request->session()->get('share_target.pending', false) === true;

        if ($request->boolean('shared')) {
            $request->session()->put('share_target.pending', true);
        }

        if ($request->user() !== null) {
            Gate::authorize('viewAny', Event::class);

            $events = $request->user()
                ->events()
                ->withCount('sources')
                ->latest('updated_at')
                ->get();

            $request->session()->forget('share_target.return_after_auth');

            if ($hasPendingShare && $events->isEmpty()) {
                $request->session()->put('share_target.return_after_create', true);
            } else {
                $request->session()->forget('share_target.return_after_create');
            }
        } elseif ($hasPendingShare) {
            $request->session()->put('share_target.return_after_auth', true);
        }

        return view('share-target', ['events' => $events]);
    }

    public function discard(Request $request): Response
    {
        $request->session()->forget([
            'share_target.pending',
            'share_target.return_after_auth',
            'share_target.return_after_create',
        ]);

        return response()->noContent();
    }
}
