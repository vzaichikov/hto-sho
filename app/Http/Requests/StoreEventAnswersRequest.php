<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

class StoreEventAnswersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && $this->user()?->can('update', $event) === true;
    }

    protected function prepareForValidation(): void
    {
        $answers = collect($this->input('answers', []))
            ->map(function (mixed $answer): mixed {
                if (! is_array($answer)) {
                    return $answer;
                }

                $selection = Arr::get($answer, 'selection');

                return [
                    'question_key' => Arr::get($answer, 'question_key'),
                    'answer' => match ($selection) {
                        '__custom__' => Arr::get($answer, 'custom'),
                        null => Arr::get($answer, 'answer'),
                        default => $selection,
                    },
                ];
            })
            ->filter(fn (mixed $answer): bool => is_array($answer) && filled($answer['answer'] ?? null))
            ->values()
            ->all();

        $this->merge(['answers' => $answers]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'state_version' => ['required', 'integer', 'min:1'],
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_key' => ['required', 'string', 'max:80', 'distinct'],
            'answers.*.answer' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'answers.required' => 'Дайте Гусю хоча б одну відповідь.',
            'answers.*.answer.required' => 'Оберіть варіант або напишіть свою відповідь.',
            'answers.*.question_key.distinct' => 'Одне питання випадково повторилося двічі.',
        ];
    }
}
