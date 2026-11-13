# Этап 4 — административная панель

## Что входит
- Веб-панель на маршрутах `/admin`, без дополнительного фреймворка.
- Авторизация по таблице `admins` через `password_verify()`; пароли создаются CLI-утилитой через `password_hash()`.
- PHP-сессия с отдельным именем cookie, HttpOnly, SameSite=Strict, Secure при HTTPS, строгим режимом ID, сменой ID после входа, 30-минутным простоем и абсолютным сроком 8 часов.
- CSRF-токен для POST-форм.
- Ограничение перебора пароля: 5 неудачных попыток на пару логин/IP, блокировка на 15 минут; счётчик увеличивается атомарным SQL UPSERT, чтобы параллельные запросы не теряли попытки.
- Роли `admin` и `manager`: менеджер может загружать XLSX, просматривать историю загрузок и каталог; редактирование товаров, цены/остатков, категорий, филиалов и учётных записей доступно только администратору. Нельзя отключить собственную или последнюю активную учётную запись администратора.
- Импорт XLSX с выбором филиала, проверкой upload status, is_uploaded_file, размера и CSRF.
- История загрузок и просмотр ошибок строк.
- Обзорные счётчики, поиск по товарам, список категорий и филиалов.
- Аудит входа/выхода, импорта и создания справочников.

## Новые файлы
- `bin/migrate.php` — CLI-мигратор: сортирует SQL-файлы по имени, применяет только отсутствующие в `schema_migrations`, защищает параллельный запуск блокировкой MySQL и допускает безопасный повтор после частичного DDL.
- `database/migrations/002_admin_panel.sql` — таблицы блокировок входа и аудита.
- `backend/app/Services/AdminAuthService.php` — авторизация, сессии, CSRF, блокировка перебора, аудит.
- `backend/app/Controllers/AdminController.php` — HTTP-обработчики панели.
- `backend/app/Views/AdminView.php` — HTML-шаблоны панели.
- `bin/create-admin.php` — CLI-создание первой учётной записи администратора.

## Маршруты
- `GET /admin/login`, `POST /admin/login`
- `POST /admin/logout`
- `GET /admin`
- `GET /admin/import`, `POST /admin/import`
- `GET /admin/imports`, `GET /admin/imports/{id}`
- `GET /admin/products`, `GET /admin/products/{id}`, `POST /admin/products/{id}`
- `GET /admin/categories`, `POST /admin/categories`, `POST /admin/categories/{id}/toggle`
- `GET /admin/branches`, `POST /admin/branches`, `POST /admin/branches/{id}/toggle`
- `GET /admin/users`, `POST /admin/users`, `POST /admin/users/{id}/toggle`

## Первичная настройка
1. Настроить HTTPS и внешний конфигурационный файл `SVET_CONFIG_FILE`.
2. Из корня проекта выполнить `/opt/php85/bin/php bin/migrate.php`. Команда применит новые миграции и пропустит уже выполненные.
3. Создать первую учётную запись: `/opt/php85/bin/php bin/create-admin.php admin`; пароль вводится интерактивно и не хранится в репозитории.
4. Открыть `https://baza.altaj-otdyh.ru/admin/login`.
5. Создать реальные филиалы с их фактическими названиями и адресами. Не использовать тестовые имена из CI.

## Безопасность
- Не добавлять пароль администратора в SQL, git, URL или публичную конфигурацию.
- Публичная директория — только `backend/public`; `bin`, `database`, `backend/config` и `backend/storage` не должны быть доступны напрямую из веба.
- Для production обязательно HTTPS. Cookie Secure включается автоматически при HTTPS.
- Убедиться, что PHP `upload_max_filesize` и `post_max_size` не ниже 6M, но серверная проверка 5 MiB остаётся обязательной.
- Ограничить права на внешний конфиг и каталог сессий.

## Проверки
CI применяет обе миграции к временной MySQL 8.0 и проверяет авторизацию, сессию, CSRF, а также прежние интеграционные тесты XLSX. Production-база не подключается.
