<?php

/*
|--------------------------------------------------------------------------
| ИИ-агенты
|--------------------------------------------------------------------------
| driver = llm  — настоящие агенты: Аналитик, Составитель, Критик и Оценщик
|                 обращаются к языковой модели, Каталогизатор — только для
|                 спорных пар, Экзаменатор работает как алгоритм.
| driver = fake — заготовки без нейросети (для автотестов и показа без интернета).
|
| Подключение к модели — OpenAI-совместимый API (/chat/completions).
| Сейчас это DeepSeek: LLM_API_URL=https://api.deepseek.com, ключ в LLM_API_KEY.
*/

return [
    'driver' => env('AGENTS_DRIVER', 'fake'),

    'llm' => [
        'url' => env('LLM_API_URL', 'https://api.deepseek.com'),
        'key' => env('LLM_API_KEY'),
        'model' => env('LLM_MODEL', 'deepseek-flash'),
        'timeout' => (int) env('LLM_TIMEOUT', 300),
        // Повторы при 429, 5xx и обрыве соединения (паузы 2, 4, 8, 16 с)
        'retries' => 4,
    ],

    /*
    | Настройки каждого агента: модель, температура, лимит ответа (вместе с
    | рассуждением модели), глубина рассуждения (low|high), версия промпта из
    | resources/prompts. Критик и Оценщик работают на другой, более сильной
    | модели, чем Составитель: так Критик не повторяет ошибок Составителя.
    */
    'agents' => [
        'analyst' => [
            'model' => env('LLM_MODEL_ANALYST', 'deepseek-flash'),
            'temperature' => 0.1,
            'max_tokens' => 16000,
            'effort' => 'low',
            'prompt' => 'analyst.v1',
        ],
        'writer' => [
            'model' => env('LLM_MODEL_WRITER', 'deepseek-flash'),
            'temperature' => 0.7,
            'max_tokens' => 32000,
            'effort' => 'high',
            'prompt' => 'writer.v2',
        ],
        'critic' => [
            'model' => env('LLM_MODEL_CRITIC', 'deepseek-v4-pro'),
            'temperature' => 0,
            'max_tokens' => 8000,
            'effort' => 'low',
            'prompt' => 'critic.v1',
        ],
        'catalog' => [
            'model' => env('LLM_MODEL_CATALOG', 'deepseek-flash'),
            'temperature' => 0,
            'max_tokens' => 2000,
            'effort' => 'low',
            'prompt' => 'catalog.v1',
        ],
        'grader' => [
            'model' => env('LLM_MODEL_GRADER', 'deepseek-v4-pro'),
            'temperature' => 0.3,
            'max_tokens' => 8000,
            'effort' => 'low',
            'prompt' => 'grader.v1',
            'runs' => 3,
        ],
    ],

    // Нарезка материала на фрагменты для Аналитика (примерно 2–3 тыс. токенов)
    'chunk_chars' => 7000,

    // Сколько фрагментов одного материала разбирать за запуск (ограничение расхода)
    'max_chunks' => (int) env('AGENTS_MAX_CHUNKS', 12),

    // Сколько раз Составитель дорабатывает вопрос по замечаниям Критика
    'rewrite_rounds' => 2,

    // Сколько вопросов по умолчанию генерировать за один запуск
    'default_count' => 12,

    // Ниже этой уверенности оценщика ответ подсвечивается преподавателю как сомнительный
    'grader_confidence_threshold' => 70,

    // Порог похожести формулировок, после которого Каталогизатор помечает дубль, %
    'duplicate_threshold' => 80,

    // С какой похожести пара вопросов уходит Каталогизатору на сравнение по смыслу, %
    'duplicate_candidate_threshold' => 45,

    // Экзаменатор пересчитывает сложность вопроса, когда на него накопилось столько ответов
    'calibration_min_answers' => 20,

    // Запуск обработчика очереди из веб-запроса (Windows, OSPanel): путь к php.exe.
    // Пусто — запускайте вручную: php artisan queue:work --timeout=1800
    'queue_autostart_php' => env('QUEUE_AUTOSTART_PHP'),

    // Учебная база SQL выполняет запрос отдельным процессом php.exe и прерывает его по таймауту, с.
    // Под Apache нужен путь к консольному php.exe; по умолчанию берётся тот же, что для очереди.
    'sql_sandbox_php' => env('SQL_SANDBOX_PHP', env('QUEUE_AUTOSTART_PHP')),
    'sql_sandbox_timeout' => (float) env('SQL_SANDBOX_TIMEOUT', 3),
];
