<?php

namespace App\Http\Requests;

use App\EventSourceInclusion;
use App\Models\Event;
use App\Models\EventSource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventSourceInclusionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $event = $this->route('event');
        $source = $this->route('source');

        return $event instanceof Event
            && $source instanceof EventSource
            && $source->event_id === $event->id
            && $this->user()?->can('update', $event) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'inclusion' => [
                'required',
                Rule::in([
                    EventSourceInclusion::Dismissed->value,
                    EventSourceInclusion::Forced->value,
                ]),
            ],
        ];
    }
}
