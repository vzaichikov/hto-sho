<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventSourcesRequest extends FormRequest
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
            'text' => ['nullable', 'string', 'max:50000', 'required_without:images'],
            'images' => ['nullable', 'array', 'max:10', 'required_without:text'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'text.required_without' => 'Додайте текст або хоча б один скриншот.',
            'text.max' => 'Текст не може бути довшим за 50 000 символів.',
            'images.required_without' => 'Додайте хоча б один скриншот або текст.',
            'images.array' => 'Скриншоти мають бути передані файлами.',
            'images.max' => 'За один раз можна додати не більше 10 скриншотів.',
            'images.*.image' => 'Кожен файл має бути зображенням.',
            'images.*.mimes' => 'Підтримуються лише JPG, PNG і WebP.',
            'images.*.max' => 'Розмір одного скриншота не може перевищувати 8 МБ.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('text')) {
            $this->merge(['text' => trim((string) $this->input('text'))]);
        }
    }
}
