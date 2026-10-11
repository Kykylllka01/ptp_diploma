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
        // Повторы при 429, 5xx и сбое соединения до отправки (паузы 2, 4 с)
        'retries' => 2,
    ],

    /*
    | Цены DeepSeek, долларов за 1 млн токенов в часы пик (api-docs.deepseek.com, октябрь 2026):
    | вход из кэша, вход без кэша, выход (вместе с рассуждением). Вне пика (будни 10:00–01:00 и
    | 04:00–06:00 UTC, выходные) — вдвое дешевле. По ним в llm_calls считается cost_usd.
    */
    'prices' => [
        'deepseek-flash' => ['hit' => 0.006, 'miss' => 0.30, 'out' => 1.20],
        'deepseek-v4-pro' => ['hit' => 0.044, 'miss' => 1.32, 'out' => 3.96],
    ],
    'peak_hours_utc' => [[1, 4], [6, 10]],

    /*
    | Настройки каждого агента: модель, температура, лимит ответа (вместе с
    | рассуждением модели), глубина рассуждения (low|high), версия промпта из
    | resources/prompts. Критик и Оценщик работают на другой, более сильной
    | модели, чем Составитель: так Критик не повторяет ошибок Составителя.
    */
    'agents' => [
        // Аналитик выписывает понятия и цитаты — рассуждение не нужно: без него на лекции «Норм»
        // 36 понятий вместо 39 при выходе 6,9 тыс. токенов вместо 21 тыс. (agents:bench, замеры A и B)
        'analyst' => [
            'model' => env('LLM_MODEL_ANALYST', 'deepseek-flash'),
            'temperature' => 0.1,
            'max_tokens' => 16000,
            'thinking' => false,
            'prompt' => 'analyst.v1',
        ],
        'writer' => [
            'model' => env('LLM_MODEL_WRITER', 'deepseek-flash'),
            'temperature' => 0.7,
            'max_tokens' => 32000,
            // low вместо high: вопросы того же качества (Критик 9–10 из 10), рассуждения на треть меньше
            'effort' => 'low',
            'prompt' => 'writer.v3',
        ],
        // Критик на Flash: на контрольном наборе (agents:critic-eval, 12 исправных + 12 испорченных
        // вопросов) Flash поймал 12 из 12 ошибок против 11 у Pro при втрое меньшей цене. Рассуждение
        // включается только для типов из thinking_types — без него проверка в 15 раз дешевле Pro.
        'critic' => [
            'model' => env('LLM_MODEL_CRITIC', 'deepseek-flash'),
            'temperature' => 0,
            'max_tokens' => 8000,
            'effort' => 'low',
            'thinking_types' => ['open', 'sql'],
            'prompt' => 'critic.v2',
        ],
        'catalog' => [
            'model' => env('LLM_MODEL_CATALOG', 'deepseek-flash'),
            'temperature' => 0,
            'max_tokens' => 2000,
            'thinking' => false,
            'prompt' => 'catalog.v1',
        ],
        // Оценщик на Flash: на наборе из 14 ответов (agents:grader-eval) средняя ошибка 0,29 балла
        // и каппа 0,96 против 0,21 и 0,97 у Pro при цене в 4,7 раза ниже. Без рассуждения дешевле
        // ещё втрое, но появляются расхождения на 2 балла — для оценки студентов это неприемлемо.
        'grader' => [
            'model' => env('LLM_MODEL_GRADER', 'deepseek-flash'),
            'temperature' => 0.3,
            'max_tokens' => 8000,
            'effort' => 'low',
            'prompt' => 'grader.v1',
            // Прогонов не больше runs; третий делается, только если первые min_runs разошлись
            'runs' => 3,
            'min_runs' => 2,
        ],
    ],

    // Нарезка материала на фрагменты для Аналитика (примерно 2–3 тыс. токенов)
    'chunk_chars' => 7000,

    // Сколько дней хранить разбор материала Аналитиком: повторная генерация по той же лекции его не повторяет
    'analysis_cache_days' => (int) env('AGENTS_ANALYSIS_CACHE_DAYS', 30),

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
