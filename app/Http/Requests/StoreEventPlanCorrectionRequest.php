<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventPlanCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && $this->user()?->can('update', $event) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan_state_version' => ['required', 'integer', 'min:1'],
            'correction' => ['required', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'correction.required' => 'Напишіть, що саме Гусю змінити у списку.',
            'correction.max' => 'Коректива не може бути довшою за 2000 символів.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('correction')) {
            $this->merge(['correction' => trim((string) $this->input('correction'))]);
        }
    }
}
