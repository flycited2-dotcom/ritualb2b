# Спек: статусы заказов через Telegram (ritual B2B)

**Дата:** 2026-05-31
**Источник:** перенос проверенной схемы splithub.ru (см. `C:\Users\user\Documents\TZ-telegram-order-statuses.md`).
**Контекст:** ritual B2B — форк splithub. Стек идентичен ТЗ: PHP + SQLite (PDO), российский хостинг (Springhost).

## Цель
Оператор меняет статус заказа inline-кнопками прямо из Telegram. `orders.status` — единый источник правды; его уже читают админка и личный кабинет клиента.

## Что уже есть (НЕ трогаем)
- `send.php` создаёт заказ со `status="new"`, шлёт уведомление в Telegram и дублирует на e-mail; есть `$orderId`; гости — в `guest_orders`.
- `orders.status` существует (default `new`). Таблица `app_settings` (key/value) существует.
- Админка меняет статус вручную (`api/admin.php`: `order_status`, `bulk_status`), 6 статусов.
- ЛК клиента (`index.html` + `api/auth.php?action=history`) показывает статус цветным бейджем; номер заказа в формате `SH-00042`.

## Дельта (что добавляем)
Только канал Telegram→сервер: нажатие кнопки → смена `orders.status`.

### Решение по статусам
Кнопки покрывают 4 статуса (как в splithub): `new` (старт) → `confirmed` → `completed`, плюс `cancelled`. Промежуточные `in_progress`/`shipped` остаются только в админке (их `$allowed` в admin.php не меняем).

### Подход: polling, не webhook
РФ-хостинг режет входящие от Telegram → webhook не работает. Сервер раз в минуту по cron вызывает `getUpdates`. Реакция на нажатие — до 1 минуты.

## Файлы

1. **`config.php`** (новый, в `.gitignore`) — `BOT_TOKEN`, `CHAT_ID`, `EMAIL_TO`, `WEBHOOK_SECRET` (≥32 симв.). Единый дом секретов.
2. **`send.php`** (правка) — в начале подключить `config.php` (если есть) и обернуть текущие `define()` в `!defined` (фолбэк, чтобы live-сайт не сломался без config.php). После создания заказа залогиненного клиента (`$orderId` есть) и при `$tgOk` — прикрепить к сообщению кнопки `[✅ Подтвердить][❌ Отменить]` через `editMessageReplyMarkup`. Гостевые заказы кнопок не получают.
3. **`api/tg_poll.php`** (новый) — poll-обработчик по ТЗ Приложение A:
   - `?secret=` иначе 403;
   - `tg_offset` из `app_settings`;
   - `getUpdates` (`allowed_updates=["callback_query"]`, `timeout=0`);
   - на каждое нажатие: сверка `chat.id==CHAT_ID`; разбор `st:<status>:<id>`; валидация статуса; смена `orders.status`; идемпотентность; перерисовка клавиатуры (после `confirmed` → `[📦 Выполнен][❌ Отменить]`; после `completed`/`cancelled` → пусто); ответ `SH-00042 → <Статус>`;
   - **offset сохраняется всегда**; try/catch на каждый апдейт; свой лог `api/tg_poll.log`.
4. **Cron + разовый `deleteWebhook`** (на сервере при деплое) — снять старый webhook (иначе `409`), затем `* * * * * curl -s "https://ritualb2b.ru/api/tg_poll.php?secret=..."`.

## Надёжность/безопасность
offset всегда · идемпотентность · таймауты curl (10–25с) · `SSL_VERIFYPEER=false` · секрет на эндпоинте · сверка `chat_id` · дубль заявки на e-mail.

## Формат номера
`SH-` + id, дополненный нулями до 5 знаков (как в `index.html` и поиске админки).

## Верификация
- Локально PHP нет → `php -l` невозможен; код сверяется с проверенным референсом ТЗ + ревью.
- E2E на проде (ТЗ §8) — только после явного «деплой» владельца.

## Чего НЕ делаем
Не трогаем админку/ЛК/e-mail/бонусы/гостей. Не webhook. Не деплоим без слова «деплой».
