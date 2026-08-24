<?php

namespace App\Http\Controllers;

use App\Actions\CreateEventAction;
use App\Actions\DeleteEventAction;
use App\Actions\UpdateEventAction;
use App\HarnessRunType;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Models\Event;
use App\Services\ContextAnalysisService;
use App\Services\HarnessRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class EventController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Event::class);

        $events = $request->user()
            ->events()
            ->withCount('sources')
            ->latest('updated_at')
            ->paginate(12);

        return view('events.index', ['events' => $events]);
    }

    public function create(): View
    {
        Gate::authorize('create', Event::class);

        return view('events.create');
    }

    public function store(
        StoreEventRequest $request,
        ContextAnalysisService $analysis,
        HarnessRecorder $harnessRecorder,
        CreateEventAction $createEvent,
    ): JsonResponse|RedirectResponse|Response {
        $attributes = $request->validated();
        $harnessRun = $harnessRecorder->start(
            event: null,
            type: HarnessRunType::DescriptionReview,
            correlationId: (string) Str::ulid(),
        );

        try {
            $review = $analysis->reviewEventDescription($attributes['description'], $harnessRun);
        } catch (Throwable $throwable) {
            $harnessRecorder->fail($harnessRun, $throwable);
            $harnessRun->delete();
            report($throwable);

            return $this->aiUnavailableResponse($request, creating: true);
        }

        if (! $review->accepted) {
            $harnessRun->delete();

            throw ValidationException::withMessages([
                'description' => $review->reason->message(),
            ]);
        }

        try {
            $event = $createEvent->execute($request->user(), $attributes);
        } catch (Throwable $throwable) {
            $harnessRun->delete();

            throw $throwable;
        }

        $harnessRecorder->attach($harnessRun, $event);
        $harnessRecorder->finish($harnessRun);

        if ($request->expectsJson()) {
            return response()->json([
                'redirect' => route('events.show', $event),
            ], 201);
        }

        return redirect()
            ->route('events.show', $event)
            ->with('success', 'Задум смачний. Гусь уже розгрібає перший контекст.');
    }

    public function show(Request $request, Event $event): View
    {
        Gate::authorize('view', $event);

        $activeTab = in_array($request->query('tab'), ['context', 'questions', 'plan', 'silpo'], true)
            ? $request->query('tab')
            : 'context';

        $event->load([
            'sources' => fn ($query) => $query
                ->with('imageExtraction')
                ->oldest('created_at')
                ->oldest('id'),
            'contextVersions' => fn ($query) => $query
                ->where('state_version', '<', $event->state_version)
                ->latest('state_version'),
            'latestCartRun',
        ]);

        return view('events.show', [
            'event' => $event,
            'activeTab' => $activeTab,
            'questions' => $event->unansweredQuestions()->all(),
            'needsQuestionRefresh' => $event->questionsNeedRefresh(),
        ]);
    }

    public function update(
        UpdateEventRequest $request,
        Event $event,
        ContextAnalysisService $analysis,
        HarnessRecorder $harnessRecorder,
        UpdateEventAction $updateEvent,
    ): JsonResponse|RedirectResponse {
        $attributes = $request->validated();
        $descriptionChanged = ($attributes['description'] ?? null) !== $event->description;

        if ($descriptionChanged && filled($attributes['description'] ?? null)) {
            $harnessRun = $harnessRecorder->start(
                event: $event,
                type: HarnessRunType::DescriptionReview,
                correlationId: (string) Str::ulid(),
            );

            try {
                $review = $analysis->reviewEventDescription($attributes['description'], $harnessRun);
                $harnessRecorder->finish($harnessRun);
            } catch (Throwable $throwable) {
                $harnessRecorder->fail($harnessRun, $throwable);
                report($throwable);

                return $this->aiUnavailableResponse($request, creating: false);
            }

            if (! $review->accepted) {
                throw ValidationException::withMessages([
                    'description' => $review->reason->message(),
                ]);
            }
        }

        $updateEvent->execute($event, $attributes);

        return redirect()
            ->route('events.show', ['event' => $event, 'tab' => 'context'])
            ->with('success', 'Гусь запамʼятав зміни й уже оновлює план.');
    }

    public function destroy(Event $event, DeleteEventAction $deleteEvent): RedirectResponse
    {
        Gate::authorize('delete', $event);

        $deleteEvent->execute($event);

        return redirect()->route('events.index')->with('success', 'Подію та всі її матеріали видалено.');
    }

    private function aiUnavailableResponse(Request $request, bool $creating): JsonResponse|RedirectResponse|Response
    {
        $message = 'Гусь завис над задумом. Нічого не зберегли — спробуйте ще раз.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 503);
        }

        if (! $creating) {
            return back()->withInput()->with('error', $message);
        }

        return response()->view('events.create', [
            'failureMessage' => $message,
            'form' => $request->only(['title', 'description', 'alcohol_planned']),
            'initialStep' => 2,
        ], 503);
    }
}
