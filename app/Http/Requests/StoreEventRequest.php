<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Event::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:500'],
            'alcohol_planned' => ['required', 'boolean'],
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
            'description.required' => 'Підкиньте Гусю хоч кілька слів про задум.',
            'description.max' => 'Гусь просив коротко: до 500 символів, будь ласка.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->input('title')),
            'description' => trim((string) $this->input('description')),
            'alcohol_planned' => $this->boolean('alcohol_planned'),
        ]);
    }
}
