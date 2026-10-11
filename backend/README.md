# Бэкенд: банк вопросов и тестирование (Laravel 13)

Серверная часть мультиагентной системы генерации заданий и проверки развёрнутых ответов. Все экраны прототипа работают на настоящей базе MySQL, ИИ-агенты работают через LLM (сейчас DeepSeek).

Стек: PHP 8.5, Laravel 13, MySQL 8.4, Apache 2.4 через OSPanel 6. Node.js нужен только для сборки фронта через Vite.

## Запуск в OSPanel 6

Проект лежит в `D:\ProgramsGames\OSPanel6\home\ptp.localhost`, а `D:\Claude\ПТП Диплом\backend` — ссылка (junction) на эту папку. Настройки проекта для панели — `.osp/project.ini`: Apache, PHP-8.5, публичная папка `public`, адрес `127.0.0.1`.

Файл `hosts` панель не трогает (в настройках снята галочка «Использовать файл HOSTS» — его блокирует антивирус). Это не мешает: адреса вида `*.localhost` браузер сам направляет на `127.0.0.1`, поэтому модули закреплены на этом адресе (у MySQL-8.4 в `config\MySQL-8.4\default\settings.ini` указано `ip = 127.0.0.1`).

1. Запустите `D:\ProgramsGames\OSPanel6\bin\ospanel.exe` — в трее появится значок.
2. Включите модули: **MySQL → MySQL-8.4**, **HTTP → Apache**, **PHP → PHP-8.5**. То же из консоли панели: `osp on MySQL-8.4`, `osp on Apache`, `osp on PHP-8.5`; состояние — `osp status`.
3. Откройте http://ptp.localhost и войдите (учётки ниже). Преподаватель попадает на «Обзор», студент — на «Мои тесты».

Первый запуск на чистой базе (консоль проекта: значок → ptp.localhost → консоль):
```
php artisan migrate --seed
```
База `ptp_diploma`, сравнение `utf8mb4_unicode_ci`, пользователь `root` без пароля — как в `.env`.

Фронт собирается в `public/build` (в git не хранится). После правок в `resources/js` или `resources/css`: `npm install` один раз, затем `npm run build`.

Журналы панели и модулей — `D:\ProgramsGames\OSPanel6\logs`, журнал Laravel — `storage/logs/laravel.log`.

## Начальные данные

Демо-данных нет. Сидеры создают только учётки (`AccountsSeeder`) и курс «Базы данных» с темами и карточками агентов (`CourseSeeder`). Вопросы, тесты и результаты появляются только из работы системы.

## Вход и учётные записи

| Роль | Почта | Пароль |
|---|---|---|
| Преподаватель (Бакановская Л. Н.) | `teacher@utmn.local` | `teacher123` |
| Студент (Иванов А., Группа 1) | `student@utmn.local` | `student123` |

Пароли простые, потому что это локальный стенд; на странице входа подсказка с ними видна только при `APP_ENV=local`. Сменить пароль можно в личном кабинете (`/profile`). Перед показом не на своём компьютере смените пароли и поставьте `APP_DEBUG=false`.

Новую учётку можно создать из консоли, пароль команда спросит отдельно:
```
php artisan user:create petrov@utmn.local "Петров П." --role=student --group="Группа 1"
php artisan user:create ivanova@utmn.local "Иванова Е. А." --role=teacher
```

Как устроено:
- обычный вход Laravel по сессии (`app/Http/Controllers/AuthController.php`), не больше 10 попыток входа в минуту;
- роль хранится в `users.role`, проверку делает middleware `role:teacher` / `role:student` (`app/Http/Middleware/EnsureRole.php`): чужие страницы перенаправляют на свою главную, чужие запросы к API получают 403;
- API работает на той же сессии, что и страницы, с CSRF-токеном из `<meta name="csrf-token">`; с Laravel 13 middleware дополнительно проверяет, что запрос пришёл с того же сайта (`Sec-Fetch-Site`);
- студент видит только тесты своей группы (или без группы) и не видит черновики; правила доступа к ответам — в `app/Models/Test.php` (`reviewOpenFor`, `keepsAnswersFrom`, `secretQuestionIdsFor`): разбор и проверка в тренировке не раскрывают ответы, пока их можно использовать в идущем тесте.

## Как устроено

| Часть | Где |
|---|---|
| Таблицы | `database/migrations`: users, courses, materials, questions, tests, question_test, attempts, answers, reviews, generation_runs, agent_logs, agents, llm_calls, jobs |
| Модели | `app/Models` |
| Экраны (Blade) | `resources/views/teacher/*`, `student/*`, шапки в `partials/`, общий `layouts/app.blade.php` |
| Скрипты экранов | `resources/js/pages/*.js`, общий `resources/js/app.js` |
| Данные для экранов | `app/Services/FrontendData.php` отдаёт их в шаблон как `window.APP_DATA` |
| Контроллеры страниц | `app/Http/Controllers/TeacherController.php`, `StudentController.php`, маршруты в `routes/web.php` |
| API | `app/Http/Controllers/Api/*`, маршруты в `routes/api.php` |
| Оценка ответов | `app/Services/GradingService.php` |
| Учебная база для SQL | `app/Services/SqlSandbox.php` и `resources/sandbox/sql-runner.php` |
| Попытки | `app/Services/AttemptService.php` |
| Аналитика | `app/Services/AnalyticsService.php` |
| Конвейер агентов | `app/Services/GenerationPipeline.php`, задача очереди `app/Jobs/RunGenerationPipeline.php` |

### Учебная база SQL

Запрос студента выполняется в SQLite в памяти (таблицы `groups` и `students`), только один SELECT. Каждый запрос идёт отдельным процессом `php.exe` с таймаутом 3 с (`SQL_SANDBOX_TIMEOUT`) и пределом памяти SQLite 64 МБ, так что «бесконечный» запрос не повесит сайт. Путь к консольному PHP берётся из `SQL_SANDBOX_PHP` или `QUEUE_AUTOSTART_PHP`. Если сама учебная база не запустилась, сдача теста откатывается с ошибкой 503, а не ставит 0.

### ИИ-агенты

Интерфейсы — `app/Agents/Contracts`, реализации на LLM — `app/Agents/Llm`, промпты — `resources/prompts/*.vN.md`, модели и пороги — `config/agents.php`. Драйвер выбирается `AGENTS_DRIVER` в `.env`: `llm` — настоящая модель (ключ в `LLM_API_KEY`, клиент OpenAI-совместимый), `fake` — заготовки без нейросети (их используют автотесты). Каждый вызов модели пишется в таблицу `llm_calls`.

Очередь — `QUEUE_CONNECTION=database`; обработчик очереди сайт запускает сам через `QUEUE_AUTOSTART_PHP`, вручную — `php artisan queue:work --timeout=1800`.

## API

Все адреса требуют входа. Работа с банком, материалами, агентами, тестами и проверкой — только для преподавателя; попытки и тренировка — только для студента; учебная база SQL — для обоих.

| Метод | Адрес | Что делает |
|---|---|---|
| GET | `/api/questions?status=&type[]=&difficulty[]=&topic[]=&material=&q=` | список вопросов с фильтрами |
| GET / PATCH | `/api/questions/{код}` | вопрос, правка и смена статуса |
| POST | `/api/questions/bulk` | `{ids, action: approve\|reject}` |
| POST | `/api/questions/{код}/regenerate` | Составитель переписывает, Критик проверяет заново |
| POST | `/api/questions/{код}/check` | проверка ответа в тренировке: `{answer, test}` или `{answer, attempt}`, не чаще 60 раз в минуту |
| GET / POST | `/api/materials` | список, загрузка (`file`, `url` или `text`) |
| DELETE | `/api/materials/{id}` | удалить, если по материалу нет вопросов |
| POST | `/api/generation-runs` | запустить агентов |
| GET | `/api/generation-runs/{id}` | состояние конвейера и журнал |
| GET | `/api/bank-stats` | сколько одобренных вопросов по темам |
| POST | `/api/tests` | собрать и опубликовать тест |
| POST | `/api/tests/{код}/questions` | добавить вопросы из банка в тест |
| PATCH | `/api/reviews/{id}` | подтвердить оценку открытого ответа |
| PUT | `/api/attempts/{id}` | автосохранение попытки (ответ строкой — до 10 000 символов) |
| POST | `/api/attempts/{id}/submit` | сдать попытку |
| POST | `/api/sql/run` | выполнить запрос в учебной базе, не чаще 30 раз в минуту |

## Тесты

```
php artisan test
```

29 тестов в `tests/Feature`: `FlowTest` — сквозные сценарии (вход, роли, экраны, агенты-заготовки, попытка, SQL, сборка теста), `SecurityTest` — проблемы из проверки безопасности. Гоняются на SQLite в памяти, MySQL и нейросеть для них не нужны: каждый тест сам создаёт нужные записи (помощники в `tests/Concerns/MakesCourseData.php`).

## Обновление зависимостей

`repo.packagist.org` при включённом VPN может не открываться. Тогда выключите VPN на время `composer update` или разрешите версии через зеркало и верните в `composer.lock` ссылки на GitHub (так сделано при переходе на Laravel 13).

## Что пока не сделано

- Регистрация и восстановление пароля (учётки создаёт сидер или команда `user:create`).
- Уровневое тестирование по схеме научного руководителя, типы вопросов «пропуски» и «последовательность».
- Низкоприоритетные пункты проверки безопасности: автооценка без преподавателя, загрузка по ссылке на внутренние адреса, максимум баллов по каждому критерию.
