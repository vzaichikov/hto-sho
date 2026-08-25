<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiscoverSilpoFulfilmentRequest extends FormRequest
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
            'reset_token' => ['required', 'string'],
            'stage' => ['required', Rule::in([
                'intent',
                'address_search',
                'address_options',
                'nova_settlements',
                'nova_offices',
                'nova_branches',
                'slots',
                'review',
            ])],
            'query' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('stage'), [
                    'intent',
                    'address_search',
                    'nova_settlements',
                ], true)),
                'nullable',
                'string',
                'min:2',
                'max:'.($this->input('stage') === 'intent' ? 600 : 120),
            ],
            'token' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('stage'), [
                    'address_options',
                    'nova_settlements',
                    'nova_offices',
                    'nova_branches',
                    'slots',
                    'review',
                ], true)),
                'nullable',
                'string',
                'max:50000',
            ],
            'slot_start' => ['required_if:stage,review', 'nullable', 'string', 'max:80'],
            'slot_end' => ['required_if:stage,review', 'nullable', 'string', 'max:80'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'query.required' => 'Підкажіть Гусю, що шукати.',
            'query.min' => 'Дайте Гусю хоча б дві літери для пошуку.',
            'query.max' => 'Скажіть Гусю маршрут трохи коротше — до 600 символів.',
            'token.required' => 'Гусь загубив попередній вибір. Почніть цей крок ще раз.',
            'slot_start.required_if' => 'Гусь не бачить початок обраного часу.',
            'slot_end.required_if' => 'Гусь не бачить кінець обраного часу.',
        ];
    }
}
