# Светофор — оперативный контекст

Дата аудита GitHub: **2026-11-18**. Репозиторий: https://github.com/Stran22/svetofor22 . Ветка: `feature/excel-column-mapping`.
**Исходный проверенный HEAD аудита:** `f24584ff7bc1a6be9f25c962d55d12556b594021` (2026-11-17). Это именно база аудита; для нового чата всегда считывать текущий HEAD.

## Где что находится
- Реестр маршрутов: `backend/bootstrap/app.php`.
- Backend: `backend/app/Controllers/`, `backend/app/Services/`, `backend/app/Core/`.
- Точка входа: `backend/public/index.php`; Apache: `backend/public/.htaccess`.
- Схема: `database/migrations/*.sql`; мигратор: `bin/migrate.php`.
- Cron-скрипты FCM: `bin/process_push_queue.php` и `bin/process_push_queue_v2.php`; выбранный серверный cron следует проверить.
- Android: `android/app/src/main/java/ru/svet22/app/`; API `data/remote/SvetApi.kt`; Retrofit `data/remote/RetrofitClient.kt`; карточка товара `ui/catalog/ProductDetailScreen.kt`.
- CI: `.github/workflows/php-checks.yml`, `excel-import-safety.yml`, `android-build.yml`.

## Реализовано в проверенном коде
- PHP/MySQL каталог с филиалами, категориями, товарами, остатками и ценами; Android Jetpack Compose, Retrofit.
- XLSX import с ручным сопоставлением колонок на филиал, проверкой файла и fingerprint сопоставления, логом/ошибками.
- VK ID, отзывы/модерация, FCM и подписки, поисковая аналитика, «Супер выгода», баннеры и конкурсы.
- Кнопка «Следить за товаром» показывается по `can_track` только при известном **нулевом** остатке во **всех активных филиалах**, а создание подписки перепроверяет условие. Одна подписка на user + product; `branch_id=NULL`.
- Админское управление `categories.hide_store_stock` и наследование на дочерние категории, скрытие блока цен/остатков на Android-карточке.

## Текущая задача и следующий шаг
**Текущий этап — постоянная техдокументация.** Файлы `AGENTS.md`, `PROJECT_CONTEXT.md`, `CHANGELOG.md`, `docs/DATABASE.md`, `docs/API_ROUTES.md`, `docs/SERVER_CONFIG.md`, `docs/FEATURES.md` создаются по коду. После этого: проверить реально применённые миграции и cron на хостинге, проверить статусы GitHub Actions, затем получать конкретное ТЗ на изменения.

## Аудиторские замечания
1. В `database/migrations` несколько файлов с одинаковыми номерными префиксами `006_` и `007_`: порядок определяется **полным именем** (`bin/migrate.php`), журнал — `schema_migrations.migration`.
2. `007_product_wide_subscriptions.sql` **удаляет все подписки** перед перестройкой ограничения. Не запускать повторно/на живой БД без анализа.
3. Исторические `docs/architecture.md` и `docs/api-contract.md` описывают старую модель подписки по филиалу и планировавшиеся несуществующие маршруты; `android/README.md` также содержит устаревший список «следующих шагов».
4. Доступ к коду на GitHub **не доказывает**, что migration/cron уже работают в production.
5. Подтверждения новой сборки Android и тестов PHP **в ходе этого документационного аудита нет** — их не считать пройденными.

## Документация
[БД](docs/DATABASE.md) · [API](docs/API_ROUTES.md) · [Окружение](docs/SERVER_CONFIG.md) · [Функции](docs/FEATURES.md) · [Правила](AGENTS.md) · [Изменения](CHANGELOG.md).
