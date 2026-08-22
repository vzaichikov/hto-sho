<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRequest extends FormRequest
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
        $event = $this->route('event');
        $descriptionIsUnchanged = $event instanceof Event
            && $this->input('description') === $event->description;

        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => [
                $event instanceof Event && filled($event->description) ? 'required' : 'nullable',
                'string',
                $descriptionIsUnchanged ? 'max:1000' : 'max:500',
            ],
            'people_count' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'budget_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Без назви Гусь не знайде цю пригоду потім.',
            'title.max' => 'Назва розігналася далі 120 символів. Трошки підріжте.',
            'description.required' => 'Задум уже став частиною події — не лишайте Гуся без контексту.',
            'description.max' => 'Гусь просив коротко: до 500 символів, будь ласка.',
            'people_count.integer' => 'Кількість людей має бути цілим числом.',
            'people_count.min' => 'Кількість людей має бути не меншою за 1.',
            'people_count.max' => 'Кількість людей завелика.',
            'budget_amount.numeric' => 'Бюджет має бути числом.',
            'budget_amount.min' => 'Бюджет не може бути відʼємним.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->input('title')),
            'description' => $this->filled('description')
                ? trim((string) $this->input('description'))
                : null,
        ]);
    }
}
