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
        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
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
            'title.required' => 'Вкажіть назву події.',
            'title.max' => 'Назва не може бути довшою за 120 символів.',
            'description.max' => 'Опис не може бути довшим за 1000 символів.',
            'people_count.integer' => 'Кількість людей має бути цілим числом.',
            'people_count.min' => 'Кількість людей має бути не меншою за 1.',
            'people_count.max' => 'Кількість людей завелика.',
            'budget_amount.numeric' => 'Бюджет має бути числом.',
            'budget_amount.min' => 'Бюджет не може бути відʼємним.',
        ];
    }
}
