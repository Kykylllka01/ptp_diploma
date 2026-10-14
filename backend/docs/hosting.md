# Установка на виртуальный хостинг (REG.RU)

Рабочий сайт пилота — https://utmn.malanych.ru, виртуальный хостинг REG.RU (Ispmanager, Apache + nginx, PHP 8.5, MySQL 8.0).

## Чем хостинг отличается от OSPanel

Хостинг завершает фоновые процессы, которые запустил сайт или планировщик: обработчик очереди, запущенный
так посреди генерации, исчезал через минуту без ошибки, задача зависала. Поэтому на хостинге обработчики
запускает сам cron — каждый своей строкой, процесс работает «на переднем плане» задания cron, и его не трогают.

## Расположение

| Что | Где |
|---|---|
| Проект | `~/ptp` |
| Папка сайта | `~/www/utmn.malanych.ru` → ссылка на `~/ptp/public` |
| PHP для консоли | `/opt/php/8.5/bin/php` |
| Composer | `~/bin/composer.phar` |
| Копии базы и файлов | `~/ptp-backup` (скрипт `backup.sh`, 14 дней) |

## .env

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://utmn.malanych.ru
SESSION_SECURE_COOKIE=true
LOG_STACK=daily
QUEUE_CONNECTION=database
QUEUE_AUTOSTART_PHP=
SQL_SANDBOX_PHP=/opt/php/8.5/bin/php
QUEUE_CRON_WORKERS=3
```

После правки `.env`: `php artisan optimize`.

## crontab

```
* * * * * cd /var/www/<логин>/data/ptp && /opt/php/8.5/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /var/www/<логин>/data/ptp && flock -n storage/framework/worker-1.lock /opt/php/8.5/bin/php artisan queue:work --stop-when-empty --tries=1 --timeout=1800 >> /dev/null 2>&1
* * * * * cd /var/www/<логин>/data/ptp && flock -n storage/framework/worker-2.lock /opt/php/8.5/bin/php artisan queue:work --stop-when-empty --tries=1 --timeout=1800 >> /dev/null 2>&1
* * * * * cd /var/www/<логин>/data/ptp && flock -n storage/framework/worker-3.lock /opt/php/8.5/bin/php artisan queue:work --stop-when-empty --tries=1 --timeout=1800 >> /dev/null 2>&1
30 3 * * * /var/www/<логин>/data/ptp-backup/backup.sh >> /var/www/<логин>/data/ptp-backup/backup.log 2>&1
```

`flock` не даёт запустить второй обработчик в том же «слоте», пока первый занят долгой генерацией.
Задача из очереди начинается в течение минуты после постановки.

## Обновление

Только выпущенными версиями: выпуск и выкладка — [releasing.md](releasing.md), одной командой
`scripts/deploy.sh vX.Y.Z` из корня репозитория. Адрес сервера и ключ скрипт берёт из `scripts/deploy.env`,
которого нет в git (образец — `scripts/deploy.env.example`).

Проверка: `php artisan pilot:check` — первой строкой номер версии на сервере.
