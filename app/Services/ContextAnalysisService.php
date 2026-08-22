<?php

namespace App\Services;

use App\Data\EventContextData;
use App\Data\ImageExtractionData;
use Illuminate\Http\Client\Response;
use JsonException;
use RuntimeException;

final class ContextAnalysisService
{
    public function __construct(private readonly AiRequestFactory $requestFactory) {}

    public function extractImage(string $imageContents, string $mimeType): ImageExtractionData
    {
        $prompt = <<<'PROMPT'
Ти обробляєш одне джерело для українського застосунку планування подій «Хто Шо?». Однією відповіддю:
1. класифікуй зображення як chat_screenshot, product_image або irrelevant;
2. дослівно зчитай видимий корисний текст (OCR), зберігаючи імена, кількості, дати та заперечення;
3. для chat_screenshot окремо запиши кожне видиме повідомлення в message_timeline у порядку зверху вниз;
4. стисло українською підсумуй лише факти із зображення.

Для кожного message_timeline item:
- sequence — позиція повідомлення зверху вниз, починаючи з 0;
- author — видимий автор або null, якщо його не можна надійно визначити;
- text — текст саме цього повідомлення без сусідніх повідомлень;
- visible_date — дослівна видима дата або найближчий застосовний розділювач на кшталт «Сьогодні»/«Вчора», без перетворення на іншу дату;
- visible_time — дослівний видимий час або null;
- is_quoted — true лише для цитованого/пересланого старого фрагмента, а не нового повідомлення автора.

Не вигадуй автора, дату чи час. Якщо на скриншоті дата показана один раз для кількох повідомлень, повтори цей raw date context для кожного повідомлення, до якого він явно належить. Для product_image та irrelevant message_timeline має бути порожнім.

Класифікація також є перевіркою корисності саме для контексту події:
- chat_screenshot — видима розмова правдоподібно стосується зустрічі або спільної події: участі людей, уподобань чи обмежень, їжі, напоїв, меню, закупів, бюджету, розподілу «хто що бере», часу, місця або іншої організації. Сторонній чат про роботу, техніку, новини, меми чи іншу тему — irrelevant;
- product_image — їжа, напій, продуктовий товар, меню, етикетка, чек, список покупок, приладдя для події або інша річ, яку правдоподібно можуть обирати чи купувати для цієї події. Навушники, одяг, автомобіль та інший випадковий каталог товарів без звʼязку з подією — irrelevant;
- irrelevant — усе, що не є корисним доказом для планування події за цими правилами.

Не вимагай повної передісторії там, де сам фрагмент уже правдоподібно корисний: короткі повідомлення «Я буду», «О 15:00» чи «Беру лід» можуть бути chat_screenshot. Не домислюй невидимого.

Для irrelevant:
- message_timeline завжди порожній;
- OCR може зберігати видимий текст, якщо він допомагає пояснити рішення, а summary може бути порожнім;
- dismissal_reason обовʼязковий: коротко й конкретно українською назви, що видно та чому це не додає корисного контексту події;
- пиши з легкою самоіронією від «Гуся Шо», без лайки, образ чи приниження людини. Наприклад: «Краєвид чудовий, але ні чату, ні продуктів, ні корисного контексту події тут не видно. Гусь відклав це вбік.»
PROMPT;

        $payload = $this->requestPayload(
            prompt: $prompt,
            schemaName: 'image_extraction',
            schema: $this->imageExtractionSchema(),
            imageDataUrl: 'data:'.$mimeType.';base64,'.base64_encode($imageContents),
        );

        return ImageExtractionData::from($this->decodedPayload($this->send($payload)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceBatches
     */
    public function summarizeEvent(array $sourceBatches): EventContextData
    {
        $prompt = <<<'PROMPT'
Ти складаєш поточний доказовий контекст події для українського застосунку «Хто Шо?». Усі джерела передані разом і згруповані за upload_batch. Не вважай порядок джерел або position історичним порядком: position означає лише порядок передавання файлів людиною і може бути помилковим.

Спочатку віднови змістову хронологію повідомлень. Застосовуй часові сигнали в такому порядку:
1. явна дата й час усередині скриншота;
2. raw labels «Сьогодні»/«Вчора» та видимий час, використовуючи uploaded_at відповідного source як календарний anchor;
3. видимий час усередині однієї пачки або одного дня;
4. sequence зверху вниз усередині одного скриншота;
5. batch_uploaded_at/uploaded_at лише як fallback, коли в контенті немає достатнього часу.

Явний chat timestamp сильніший за час завантаження. Пізніше завантажений скриншот з явно старішою датою лишається старішим. message_timeline=null означає legacy cache: віднови доступні часи з ocr_text. Повідомлення з is_quoted=true є старою цитатою всередині нового повідомлення і саме по собі не змінює поточний стан.

Коли надійно пізніше явне повідомлення змінює ту саму домовленість, кількість, час, присутність або відповідальність, воно замінює старе значення. Поверни лише актуальну правду з source_ids нового підтвердження. Не додавай warning чи unresolved question лише через те, що старе значення відрізнялося.

Фінальний summary також описує лише актуальний стан. Не переказуй у ньому superseded history на кшталт старих 14:00 або «спершу Саша брав вугілля», якщо вже є надійне новіше уточнення.

Приклади:
- «Саша бере вугілля» о 10:20, потім «Вугілля вже не беру — купіть 2 пачки» об 11:24 => актуальна домовленість: купити 2 пачки; це не warning і не unresolved question.
- «5 учасників», потім «8 учасників» => актуальна кількість 8; відсутні імена можуть бути окремим unresolved question, але зміна 5 на 8 не є розбіжністю.
- «Зустріч о 14:00», потім «Переносимо на 15:00» => актуальний час 15:00 без warning про конфлікт.

Warnings залишай лише для справді невизначеної хронології, несумісних одночасно актуальних тверджень, нечіткого авторства/сенсу, помилки обробки або ризику безпеки. Алергію чи жорстке обмеження можна зняти лише чітким пізнішим повідомленням самої відповідної людини; чужа репліка цього не робить.

participants.brings містить лише актуальні позитивні зобовʼязання людини щось принести. «Більше не беру» або «треба купити» не є brings. Автор нагадування про алерген не обовʼязково є людиною з алергією: наприклад, «Тарас: Про арахіс не забудьте» означає лише, що Тарас нагадав про арахіс. Не приписуй алергію Тарасу без явного тексту; якщо людина з обмеженням не названа, збережи загальне безпечне обмеження та невизначеність атрибуції.

Не перенось факти між авторами одного source. «Оля: Я беру хумус і лимонад» додає ці brings лише Олі, ніколи Саші чи іншому учаснику. Якщо актуальний header каже «8 учасників», а надійно названо лише 5 різних людей, додай unresolved question про імена решти 3; саме число 8 при цьому не є warning.

Використовуй лише наведені факти, не перетворюй припущення на домовленості й не вигадуй товари чи план покупок. Кожне твердження повинно мати source_ids. Відповідай українською.

ПАЧКИ ДЖЕРЕЛ:
PROMPT;

        $prompt .= "\n".json_encode($sourceBatches, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $payload = $this->requestPayload(
            prompt: $prompt,
            schemaName: 'event_context',
            schema: $this->eventContextSchema(),
        );

        return EventContextData::from($this->decodedPayload($this->send($payload)));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function requestPayload(
        string $prompt,
        string $schemaName,
        array $schema,
        ?string $imageDataUrl = null,
    ): array {
        if (config('services.ai.provider') === 'openai') {
            $content = [['type' => 'input_text', 'text' => $prompt]];

            if ($imageDataUrl !== null) {
                $content[] = ['type' => 'input_image', 'image_url' => $imageDataUrl];
            }

            return [
                'model' => $this->requestFactory->model(),
                'input' => [['role' => 'user', 'content' => $content]],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => $schemaName,
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
            ];
        }

        $content = [['type' => 'text', 'text' => $prompt."\nПоверни лише один валідний JSON object, що точно відповідає описаній схемі."]];

        if ($imageDataUrl !== null) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $imageDataUrl]];
        }

        return [
            'model' => $this->requestFactory->model(),
            'messages' => [['role' => 'user', 'content' => $content]],
            'response_format' => ['type' => 'json_object'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function send(array $payload): Response
    {
        $endpoint = config('services.ai.provider') === 'openai'
            ? 'responses'
            : 'chat/completions';

        return $this->requestFactory->make()
            ->post($endpoint, $payload)
            ->throw();
    }

    /** @return array<string, mixed> */
    private function decodedPayload(Response $response): array
    {
        $json = $response->json();
        $raw = config('services.ai.provider') === 'openai'
            ? $this->openAiOutputText($json)
            : data_get($json, 'choices.0.message.content');

        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('AI provider did not return structured text.');
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('AI provider returned invalid JSON.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('AI provider returned an invalid structured payload.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function openAiOutputText(array $payload): ?string
    {
        foreach ($payload['output'] ?? [] as $output) {
            foreach ($output['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    return $content['text'];
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function imageExtractionSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['classification', 'ocr_text', 'message_timeline', 'summary', 'dismissal_reason'],
            'properties' => [
                'classification' => ['type' => 'string', 'enum' => ['chat_screenshot', 'product_image', 'irrelevant']],
                'ocr_text' => ['type' => 'string'],
                'message_timeline' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['sequence', 'author', 'text', 'visible_date', 'visible_time', 'is_quoted'],
                        'properties' => [
                            'sequence' => ['type' => 'integer', 'minimum' => 0],
                            'author' => ['type' => ['string', 'null']],
                            'text' => ['type' => 'string'],
                            'visible_date' => ['type' => ['string', 'null']],
                            'visible_time' => ['type' => ['string', 'null']],
                            'is_quoted' => ['type' => 'boolean'],
                        ],
                    ],
                ],
                'summary' => ['type' => 'string'],
                'dismissal_reason' => ['type' => ['string', 'null']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function eventContextSchema(): array
    {
        $sourceIds = ['type' => 'array', 'items' => ['type' => 'integer']];
        $stringList = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'participants', 'restrictions', 'agreements', 'warnings', 'unresolved_questions', 'source_ids'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'participants' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'status', 'preferences', 'restrictions', 'allergies', 'brings', 'source_ids'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'status' => ['type' => 'string', 'enum' => ['confirmed', 'declined', 'uncertain', 'unknown']],
                            'preferences' => $stringList,
                            'restrictions' => $stringList,
                            'allergies' => $stringList,
                            'brings' => $stringList,
                            'source_ids' => $sourceIds,
                        ],
                    ],
                ],
                'restrictions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['participant', 'restriction', 'severity', 'source_ids'],
                        'properties' => [
                            'participant' => ['type' => 'string'],
                            'restriction' => ['type' => 'string'],
                            'severity' => ['type' => 'string', 'enum' => ['allergy', 'hard', 'preference', 'unknown']],
                            'source_ids' => $sourceIds,
                        ],
                    ],
                ],
                'agreements' => $this->provenanceListSchema('summary'),
                'warnings' => $this->provenanceListSchema('message'),
                'unresolved_questions' => $this->provenanceListSchema('question'),
                'source_ids' => $sourceIds,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function provenanceListSchema(string $textKey): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [$textKey, 'source_ids'],
                'properties' => [
                    $textKey => ['type' => 'string'],
                    'source_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ],
            ],
        ];
    }
}
