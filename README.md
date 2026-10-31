# Svetofor22
Каталог продуктов для Бийска и Алтайского края. Корзина, оформление заказа и онлайн-оплата не предусмотрены.

## Стек
PHP 8.1+, MySQL 8, PDO, Composer 2.2+, PhpSpreadsheet 2.x. Android-клиент Kotlin/Jetpack Compose разрабатывается отдельным этапом.

## Структура
- `app/Core` — ядро HTTP/API, конфигурация и БД.
- `app/Controllers` — API-контроллеры.
- `app/Services` — бизнес-логика.
- `database/migrations` — SQL-схема.
- `public_html` — единственная публичная директория.
- `storage` — непубличные файлы импорта и журналы.

## Развёртывание ISPmanager
1. DocumentRoot домена должен указывать на `public_html`.
2. В корне проекта выполнить `composer install --no-dev --optimize-autoloader`.
3. Скопировать `.env.example` в `.env` и заполнить доступы к MySQL.
4. Создать БД MySQL с кодировкой utf8mb4.
5. Применить `database/migrations/001_initial_schema.sql` через phpMyAdmin.
6. Проверить `/api/v1/health`.

## API v1
- GET `/api/v1/health` — состояние API и БД.
- GET `/api/v1/branches` — магазины.
- GET `/api/v1/categories` — категории.
- GET `/api/v1/products?page=1&per_page=24&branch_id=1&category_id=2&q=молоко` — каталог.
- GET `/api/v1/products/detail?id=123` — карточка и наличие/цены по филиалам.
- POST `/api/v1/auth/guest` с JSON `{"device_uuid":"xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx"}` — регистрация гостевого устройства и выдача Bearer-токена.
- GET `/api/v1/auth/me` с заголовком `Authorization: Bearer <token>` — текущий пользователь.

Гостевой токен генерируется криптографически стойким генератором; в БД сохраняется только SHA-256-хеш. Секреты не коммитить. В production APP_DEBUG=false. Все SQL-параметры передаются через prepared statements.
