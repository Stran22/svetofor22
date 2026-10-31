# Svetofor22
Каталог продуктов для Бийска и Алтайского края. Корзина, оформление заказа и онлайн-оплата не предусмотрены.

## Стек
PHP 8.1+, MySQL 8, PDO, Composer 2.2+, PhpSpreadsheet 2.x, cURL. Android-клиент Kotlin/Jetpack Compose разрабатывается отдельным этапом.

## Структура
- `app/Core` — ядро HTTP/API, конфигурация и БД.
- `app/Controllers` — API-контроллеры.
- `app/Services` — бизнес-логика, токены и OAuth.
- `app/Security` — сессии и проверки доступа.
- `database/migrations` — SQL-схема и изменения структуры.
- `public_html` — единственная публичная директория.
- `storage` — непубличные файлы импорта и журналы.

## Развёртывание ISPmanager
1. DocumentRoot домена должен указывать на `public_html`.
2. В корне проекта выполнить `composer install --no-dev --optimize-autoloader`.
3. Скопировать `.env.example` в `.env`, задать доступы к MySQL и OAuth credentials.
4. Создать БД MySQL с кодировкой utf8mb4.
5. Применить миграции по порядку: `001_initial_schema.sql`, `002_admin_oauth_security.sql`, `003_oauth_attempt_context.sql`.
6. Проверить `/api/v1/health`.
7. В кабинетах VK ID и Яндекс ID зарегистрировать точный Redirect URI: `https://baza.altaj-otdyh.ru/api/v1/auth/oauth/callback`. Этот же адрес должен совпадать с `APP_URL` в `.env`.

## API v1

### Каталог и гостевой пользователь
- GET `/api/v1/health` — состояние API и БД.
- GET `/api/v1/branches` — магазины.
- GET `/api/v1/categories` — категории.
- GET `/api/v1/products?page=1&per_page=24&branch_id=1&category_id=2&q=молоко` — каталог.
- GET `/api/v1/products/detail?id=123` — карточка и наличие/цены по филиалам.
- POST `/api/v1/auth/guest` с JSON `{"device_uuid":"xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx"}` — регистрация гостевого устройства и выдача Bearer-токена.
- GET `/api/v1/auth/me` с заголовком `Authorization: Bearer <token>` — текущий пользователь.

### Социальная авторизация
1. Клиент отправляет POST `/api/v1/auth/oauth/start` с JSON `{"provider":"vk"}`, `{"provider":"ok"}` или `{"provider":"yandex"}`. Если нужно привязать социальную учётную запись к текущему гостю, передаёт его Bearer-токен в заголовке `Authorization`.
2. API возвращает `authorization_url`. Клиент открывает её во внешнем браузере/Custom Tab.
3. После успешной проверки провайдер возвращает пользователя на серверный callback. Сервер проверяет одноразовый `state`, обменивает код, получает стабильный ID пользователя и создаёт/находит социальную учётную запись.
4. Сервер перенаправляет приложение на `APP_AUTH_RETURN_URI` с короткоживущим `handoff_code`.
5. Android-клиент отправляет POST `/api/v1/auth/oauth/exchange` с JSON `{"handoff_code":"..."}`. Код действует 2 минуты и может быть использован только один раз. В ответе выдаётся обычный API Bearer-токен на 365 дней.

Провайдеры:
- VK — VK ID OAuth 2.1 + PKCE S256.
- ОК — внешний провайдер ОК через VK ID (идентификатор провайдера `ok_ru`), а не устаревший самостоятельный OAuth ОК.
- Яндекс — OAuth authorization code + PKCE S256; профиль запрашивается сервером через API Яндекс ID.

Для VK и ОК используется одно приложение VK ID: `VK_ID_CLIENT_ID`. Для Яндекса — `YANDEX_CLIENT_ID`. Секреты и service token хранятся только в серверном `.env`, не в Android и не в Git. Запросы OAuth выполняются серверным cURL с проверкой TLS, таймаутами и запретом автоматического перехода по HTTP redirect.

`APP_AUTH_RETURN_URI` по умолчанию указан как `svetofor2://oauth/callback`. Android-приложение должно зарегистрировать соответствующий intent-filter и принимать только этот callback. Схема возврата фиксирована на сервере и не передаётся клиентом в параметрах, чтобы исключить open redirect.

Если пользователь входит в уже привязанный социальный аккаунт, используется существующая запись. Если он пытается привязать к гостю социальный аккаунт, уже принадлежащий другой учётной записи, API отклоняет операцию — автоматического объединения аккаунтов нет.

### Администратор
- GET `/api/v1/admin/csrf` — создать сессию и получить CSRF-токен.
- POST `/api/v1/admin/login` — вход по JSON с логином и паролем, заголовок `X-CSRF-Token`.
- GET `/api/v1/admin/me` — текущая администраторская сессия и CSRF-токен.
- POST `/api/v1/admin/logout` — завершить сессию; обязателен `X-CSRF-Token`.

Администраторские сессии используют HttpOnly cookie, SameSite=Lax, строгий режим PHP-сессий и регенерацию ID после входа. Production должен работать по HTTPS. После 10 неудачных попыток входа для сочетания логина и IP за 15 минут применяется временное ограничение. Роли: `owner`, `manager`, `moderator`; для закрытых операций используется `AdminGuard::requireRole()`.

Гостевой и социальный API-токены генерируются криптографически стойким генератором; в БД сохраняется только SHA-256-хеш. OAuth state, PKCE verifier и handoff-коды также не сохраняются в открытом виде, кроме PKCE verifier, который временно необходим для завершения обмена и удаляется по сроку хранения записей. В production APP_DEBUG=false. Все SQL-параметры передаются через prepared statements.
