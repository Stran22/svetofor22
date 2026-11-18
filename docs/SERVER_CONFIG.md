# Светофор — конфигурация и пути

Аудит 2026-11-18. Параметры ниже частично подтверждены исходниками, а частично ранее указанной конфигурацией сервера. Нельзя считать их текущими production-значениями без подключения к серверу.

## Публичные адреса и точки входа
- Домен: `https://baza.altaj-otdyh.ru`.
- Android Retrofit `API_BASE_URL=https://baza.altaj-otdyh.ru/api/v1/` (файл `android/app/src/main/java/ru/svet22/app/data/remote/RetrofitClient.kt`).
- Web root должен указывать на `backend/public/`; `backend/public/index.php` загружает `backend/bootstrap/app.php` (Apache rewrite).
- `backend/public/.htaccess` содержит `CGIPassAuth On`, правила rewrite и `SetEnv SVET_CONFIG_FILE /var/www/prorock/data/secure/svet22/config.php`. Последний путь — путь из репозитория; убедиться в его существовании на сервере.

## Backend
- `composer.json`: PHP `^8.5`, platform `8.5.12`; `phpoffice/phpspreadsheet ^2.2`, ext-json/pdo/pdo_mysql/fileinfo/mbstring/zip/xml/xmlreader/xmlwriter/simplexml.
- Исторически подтверждённая среда владельца: ISPmanager, узел `cassiopeia`, Apache 2.4.52, MySQL 8, PHP 8.5.12, Composer. Проверить перед развёртыванием.
- `backend/app/Core/Config.php` обязательно читает **внешний** конфиг по env `SVET_CONFIG_FILE`, настройки в `backend/config/config.example.php`.
- Ключи конфигурации: `app.env`, `app.debug`, `app.timezone=Asia/Barnaul`, `app.base_url`, `database.host/port/name/username/password/charset`, `firebase.project_id/credentials_path`, `security.token_bytes/health_token`; VK ID конфиг см. `docs/vk-id-setup.md`.
- `backend/app/Core/Database.php` использует PDO, prepared statements, фиксирует MySQL session `time_zone='+07:00'`.

## Android
- Открывать каталог `android/` в Android Studio Panda 4; JDK 17.
- `android/app/build.gradle.kts`: `applicationId=ru.svet22.app`, `compileSdk=34`, `minSdk=26`, `targetSdk=34`, Kotlin/Compose, Retrofit, Firebase Messaging, VK ID.
- Gradle Wrapper **8.7**, `android/gradlew.bat` на Windows, `android/gradlew` на Unix; `android/gradle/wrapper/gradle-wrapper.properties`.
- Firebase Android `google-services.json` хранится вне Git и в CI восстанавливается из секрета `GOOGLE_SERVICES_JSON_BASE64`.

## Операции
- PHP lint: `composer lint`. Unit tests: `composer test`.
- SQL migration CLI: `php bin/migrate.php`, но **только после проверки резервной копии** и `schema_migrations`; `007_product_wide_subscriptions.sql` удаляет subscriptions.
- FCM worker: оба файла `bin/process_push_queue.php` и `bin/process_push_queue_v2.php`, режим по умолчанию dry-run, `--send` отправляет реальные уведомления. **Не угадывать, какой стоит в ISPmanager Cron**. Исторически обсуждался запуск по расписанию, но код GitHub не подтверждает текущую cron-команду.
- CI workflows: `.github/workflows/php-checks.yml`, `excel-import-safety.yml`, `android-build.yml`.
- Никогда не коммитить пароль БД, service-account Firebase, токены VK, реальные API ключи, `google-services.json`.

## Безопасная диагностика
```bash
php -v
composer --version
git branch --show-current
git rev-parse HEAD
```
Проверку `schema_migrations` выполнять через read-only запросы из `docs/DATABASE.md`. Deploy, изменение Cron и отправка push только с разрешения владельца.

## Проверено по терминалу ISPmanager 2026-11-18 (без изменений на сервере)
- Текущая папка терминала при проверке: `/var/www/prorock/data` (не корень сайта). Для команд использовать абсолютные пути или явный `cd`.
- Путь сайта: `/var/www/prorock/data/www/baza.altaj-otdyh.ru/`.
- Найден действующий на диске файл `backend/app/Controllers/AdminCategoriesController.php`; также существуют `backend/app/Controllers/ApiController.php`, `backend/app/Controllers/SubscriptionController.php` и другие контроллеры.
- На диске отдельно есть `.deploy/backend/app/Controllers/AdminController.php` и `.deploy/backend/app/Controllers/ApiController.php`. **Не установлено**, какая копия реально подключена Apache: наличие файла не доказывает его использование.
- В серверной папке Android найден `android/app/src/main/java/ru/svet22/app/MainActivity.kt`; grep показал код филиалов/остатков, но не показал строк подписки. Это не подтверждает соответствие серверной Android-копии актуальному GitHub или установленному APK.
- Ранее подтверждено существование внешнего конфига `/var/www/prorock/data/secure/svet22/config.php`; секреты не читать и не публиковать. Для CLI, если требуется, `SVET_CONFIG_FILE` задаётся отдельно.
- На сервере ранее наблюдались лишь файлы миграций `001_initial_schema.sql`, `002_admin_panel.sql`, `006_search_analytics.sql`, хотя в GitHub их больше. Не переносить и не запускать миграции автоматически.
- База `prorock_law`: 28 таблиц, включая `categories`, `stock`, `subscriptions`, `excel_column_mappings`, `reviews`, `push_delivery_log`. По ранее полученному списку полей `categories` **не содержала** `hide_store_stock`; перепроверить `SHOW COLUMNS` непосредственно перед решением о миграции.
- **Вывод:** GitHub и сервер могут находиться на разных версиях. До деплоя нужны read-only сравнение файлов, веб-корня, миграций и резервная копия. Действующий Android UI не заменять упрощённой серверной копией.
