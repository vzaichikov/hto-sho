<?php

namespace App\Http\Requests;

use App\CartRunMode;
use App\Models\Event;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartEventCartRunRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
            'mode' => ['required', Rule::enum(CartRunMode::class)],
            'review_token' => ['required', 'string', 'max:50000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'review_token.required' => 'Гусь просить спершу перевірити маршрут кошика.',
        ];
    }
}
