# Svet22 — предварительный REST API-контракт

Базовый префикс: `/api/v1`. Все ответы — UTF-8 JSON. Для приватных Android-маршрутов: `Authorization: Bearer <token>`.

Успех:
```json
{"success":true,"data":{},"meta":{}}
```

Ошибка:
```json
{"success":false,"error":{"code":"VALIDATION_ERROR","message":"Проверьте параметры","details":[]}}
```

Списки возвращают `meta.pagination` с `page`, `per_page`, `total`, `total_pages`. Максимальный `per_page` — 100.

## Системные и справочные маршруты

| Метод | Маршрут | Назначение |
|---|---|---|
| GET | `/health` | Проверка процесса приложения |
| GET | `/health/db` | Проверка соединения с БД (только внутренний/admin доступ в production) |
| GET | `/branches` | Список активных филиалов |
| GET | `/branches/{id}` | Информация о филиале |
| GET | `/categories` | Дерево категорий |

## Каталог

| Метод | Маршрут | Параметры |
|---|---|---|
| GET | `/products` | `branch_id`, `category_id`, `q`, `in_stock`, `sort=name|price_asc|price_desc|rating`, `page`, `per_page` |
| GET | `/products/{id}` | Детальная информация, наличие/цена по обоим филиалам, diff, рейтинг |
| GET | `/products/{id}/price-history` | `branch_id`, `from`, `to`, `page`, `per_page` |
| GET | `/search` | `q`, `branch_id`, `page`, `per_page` |
| GET | `/products/{id}/reviews` | `page`, `per_page`, только опубликованные отзывы |

## Пользователь и авторизация

| Метод | Маршрут | Назначение |
|---|---|---|
| POST | `/auth/device` | Регистрация гостевого устройства, выдача Bearer-токена и user_id |
| GET | `/me` | Текущий пользователь |
| POST | `/auth/logout` | Отзыв текущего токена |
| POST | `/auth/{provider}/start` | Начало OAuth: provider = vk, ok, yandex |
| GET | `/auth/{provider}/callback` | Серверный callback OAuth |
| POST | `/auth/handoff/exchange` | Одноразовый обмен handoff-кода на пользовательскую сессию/token |
| POST | `/auth/guest/upgrade` | Привязка социальной учётной записи к текущему гостевому пользователю только после явного подтверждения |

OAuth-провайдеры: VK ID, Одноклассники, Yandex ID. Секреты остаются на сервере; state и handoff одноразовые, с TTL и защитой от повторного использования. PKCE применяется для VK ID и Yandex ID.

## Подписки

| Метод | Маршрут | Назначение |
|---|---|---|
| GET | `/subscriptions` | Список подписок текущего пользователя |
| POST | `/subscriptions` | Создать/повторно включить подписку; JSON: `product_id`, `branch_id` |
| DELETE | `/subscriptions/{id}` | Отменить свою подписку |

Подписка всегда относится к конкретному филиалу. Уведомление отправляется только при активной подписке, `notified_at IS NULL` и остатке больше нуля.

## Отзывы

| Метод | Маршрут | Назначение |
|---|---|---|
| POST | `/products/{id}/reviews` | Создать или изменить собственный отзыв; только социальный пользователь |
| PATCH | `/products/{id}/reviews/me` | Изменить свой отзыв |
| DELETE | `/products/{id}/reviews/me` | Удалить свой отзыв |

Поля отзыва: `rating` от 1 до 5, `text` до 500 символов. Один отзыв на пользователя и товар. Публично выдаются только опубликованные отзывы. Гость может читать, но не писать.

## Административная панель

Админка использует сессию PHP, CSRF и отдельные права администратора.

| Метод | Маршрут | Назначение |
|---|---|---|
| GET | `/admin/login` | Форма входа |
| POST | `/admin/login` | Вход |
| POST | `/admin/logout` | Выход |
| GET | `/admin/import` | Форма выбора филиала и файла |
| POST | `/admin/import` | Загрузка и ручной запуск импорта XLSX |
| GET | `/admin/uploads` | История импортов |
| GET | `/admin/uploads/{id}` | Сводка и ошибки конкретного импорта |
| CRUD | `/admin/products` | Управление товарами |
| CRUD | `/admin/categories` | Управление категориями |
| CRUD | `/admin/branches` | Управление филиалами |
| GET | `/admin/reviews` | Очередь модерации |
| PATCH | `/admin/reviews/{id}` | Модерация отзыва |

Точный JSON каждого маршрута будет закреплён вместе с реализацией соответствующего контроллера и тестами; этот документ фиксирует первоначальную поверхность API, а не обещает уже работающие endpoints.
