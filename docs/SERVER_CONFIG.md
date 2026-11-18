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
