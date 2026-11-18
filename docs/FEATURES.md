# Светофор — состояние функций по коду

Аудит: `feature/excel-column-mapping`, 2026-11-18. **Реализовано в исходниках** не означает, что новая сборка/production были протестированы в данном аудите.

| Функция | Код / статус на момент аудита |
|---|---|
| Android-каталог, филиалы, товары, поиск | Реализовано в Kotlin Compose, Retrofit и PHP API |
| Категории / дерево категорий | Реализовано: API и админка |
| Отдельная карточка товара, цены и остатки филиалов | Реализовано: `ProductDetailScreen.kt`, `ApiController::product` |
| «Следить за товаром» | Реализовано; только при 0 **во всех активных филиалах** и наличии всех строк stock; user+product |
| Firebase FCM и подписки | Реализованы регистрация токенов, очередь и worker-файлы; фактический Cron проверяется отдельно |
| VK ID | Реализованы Android и backend код; действующую авторизацию проверить на устройстве при изменениях |
| Отзывы, рейтинг, модерация | Код реализован, до 500 символов |
| Админка, пользователи, филиалы, товары | Код реализован |
| Импорт XLSX с preview и mapping на филиал | Код реализован, до 5 МБ, `excel_column_mappings`, fingerprint |
| Скрывать блок цен и остатков по категории | Реализовано: `hide_store_stock`, в том числе наследование для потомков |
| Поисковая аналитика | Код API/репозиторий/миграция присутствует |
| «Супер выгода» | Реализованы API/админка/миграция |
| Баннеры, конкурсы и участие | Реализованы API/админка/миграция |
| Корзина / оплата | Не предусмотрены |

## Ключевые файлы
- `backend/app/Controllers/ApiController.php`: доступность `can_track`, `hide_store_stock`.
- `backend/app/Controllers/SubscriptionController.php`: повторная проверка остатков + авторизации.
- `backend/app/Controllers/AdminCategoriesController.php`: выбор категорий с `hide_store_stock`.
- `backend/app/Services/CategoryVisibilityService.php`: наследование.
- `android/app/src/main/java/ru/svet22/app/ui/catalog/ProductDetailScreen.kt`: кнопка подписки и отображение блока.
- `backend/app/Services/XlsxImportService.php`, `ImportRowValidator.php`: Excel.
- `backend/app/Services/RestockNotificationService.php`, `bin/process_push_queue_v2.php`: очередь FCM.

## Проверить отдельно
Реально применённые миграции production, используемый worker Cron, работа кнопки после установки актуального APK, зелёные GitHub Actions. Не объявлять эту проверку завершённой без результатов.

### Серверная проверка функций (18.11.2026)
В работающем `backend/app/Controllers/ApiController.php` поиск не нашёл `can_track` и `hide_store_stock`, хотя методы `categories()` и `product()` существуют (строки 56 и 188). Поэтому поддержку этих полей в **production API** считать **неподтверждённой**, несмотря на наличие в GitHub. Не считать функции готовыми для пользователей до проверки API, БД и Android. Существующие маршруты подписок есть, но новое правило отсутствия во всех активных филиалах не подтверждено.

### Детализация server drift: карточка товара (18.11.2026)
Полный метод `ApiController::product()` с production подтверждает: `can_track` и `hide_store_stock` **не вычисляются**, массив `branches` строится из всех строк `stock` независимо от `branches.is_active`; рейтинг опубликованных отзывов возвращается и должен сохраняться. Целевое поведение: `can_track=true` только при нулевых остатках по **всем активным филиалам**; скрытие цен/остатков управляется категорией и наследованием. Перед правкой проверить реальную схему БД и Android-контракт.

### Production MySQL: флаг видимости уже существует (18.11.2026)
`SHOW COLUMNS FROM categories` подтвердил `show_price_stock TINYINT(1) NOT NULL DEFAULT 1`. Это обратная по смыслу настройка к `hide_store_stock`, используемой в GitHub. Серверный `ApiController::product()` пока её не читает. План: не создавать второй флаг, согласовать единый контракт БД/API/админки/Android с учётом существующих значений и наследования. Готовность функции в production не подтверждена.

### Текущие настройки всех категорий (18.11.2026)
В реальной MySQL `prorock_law` все **102 категории** имеют `show_price_stock = 1`, категорий со скрытием (`0`) нет. Это проверено `GROUP BY` в phpMyAdmin. Настройка пока не используется в просмотренном production `ApiController::product()`. При разработке переключателя обеспечить сохранение значения 0 и правильное наследование, без изменения существующего отображения по умолчанию.

### Проверка production AdminCategoriesController (18.11.2026)
Вывод `grep -nE 'function (index|edit|update|create)|show_price_stock|hide_store_stock|checkbox' backend/app/Controllers/AdminCategoriesController.php`: `index()` строка 21, `edit()` 109, `create()` 130, `update()` 143; **нет совпадений** для `show_price_stock`, `hide_store_stock`, `checkbox` в этом контроллере. Сам по себе grep не проверяет шаблоны и другие файлы; до реализации проверить код методов и форму редактирования. БД содержит `show_price_stock` для всех 102 категорий (все = 1).

### Детальный аудит production формы категорий (18.11.2026)
Из предоставленного пользователем фрагмента `AdminCategoriesController.php` (методы `edit`, `create`, `update`, начало `toggle` и `delete`): форма редактирования содержит `name`, `parent_id`, `sort_order`, `image_url`, CSRF и submit, **не содержит `show_price_stock`**. `create()` вставляет только `(name, parent_id, slug, sort_order, image_url)`; значение `show_price_stock` берётся из DEFAULT 1. `update()` обновляет только `name, parent_id, sort_order, image_url`, поэтому существующее значение `show_price_stock` не меняет. Есть проверка CSRF, роли admin, запрет циклов категорий и аудит; эти механизмы сохранить. Форму создания в `index()` ещё не смотрели. Целевой патч — чекбокс в форме, запись `show_price_stock` 0/1, выдача корректного флага в API с наследованием; не создавать новый столбец.

### Production index() категорий — подтверждено фрагментом 1–108 (18.11.2026)
`AdminCategoriesController::index()` проверяет `requireUser()`, сортирует категории по ID/имени внутри иерархии, строит дерево `parent_id`, ограничивает создание/действия ролью `admin`. Форма создания содержит CSRF, `name`, `parent_id`, `sort_order`, `image_url`, но **не** `show_price_stock`. Таблица содержит ID, Название, Родитель ID, Порядок, Статус, Действия; не показывает флаг видимости. Для безопасной доработки добавить checkbox в создание и редактирование, столбец состояния в таблицу, запись 0/1 в `show_price_stock`, сохранив дерево, сортировку, права, CSRF, аудит.

### Завершён аудит helper-методов админки (2026-11-18)
`all()` не выбирает `show_price_stock`; `one()` выбирает `*`; `fields()` возвращает `[name, parent, sort, image]`, проверяет существование родителя и HTTPS URL изображения. При добавлении чекбокса учитывать, что **unchecked HTML checkbox не передаёт поле**, поэтому сохранять явное 0/1. Не ломать текущие проверки, CSRF, роли, аудит, дерево категорий. Следующий этап — сверка полного GitHub контроллера и проектирование минимального патча с сохранением production различий.

### Реализация админки видимости в GitHub (2026-11-18)
Коммит `959e9ee2454441e9c393fe962e615e458eebaf19`: в `backend/app/Controllers/AdminCategoriesController.php` добавлены checkbox `show_price_stock` в создание/редактирование, колонка состояния в таблицу, сохранение 0/1 через INSERT/UPDATE, `all()` выбирает `show_price_stock`. Существующая групповая форма «Скрыть цены и остатки» сохранена с инверсией значения в существующее поле БД; имя POST-массива `hide_store_stock[]` сохранено для формы, но столбец БД больше не используется. **Только GitHub; не развёрнуто и не протестировано**. Следующий этап: синтаксическая проверка PHP, аудит API/Android и безопасное развёртывание.

### 2026-11-18 — CategoryVisibilityService адаптирован к production
Пользователь подтвердил через `ls -l`, что в production отсутствует `backend/app/Services/CategoryVisibilityService.php`. В GitHub сервис существует. Коммит `c3f1b4b644b53cdedf850cb9f8ce31f262bd4414` меняет SQL сервиса с отсутствующего `hide_store_stock` на существующее `show_price_stock` и рассчитывает прежний API-флаг `hide_store_stock` как отрицание `show_price_stock` с наследованием от родителя. **Только GitHub, не развёрнуто/не протестировано.**

### 2026-11-18 — маскировка price/quantity в GitHub API (не завершено)
Коммит `767aa0d6f14618408f274444f95e375125f0efb6` добавляет в `ApiController::products()` и `product()` маскировку `price`, `quantity`, временных полей и `hide_store_stock` для скрытых категорий через `CategoryVisibilityService` (с наследованием). **Не проверено lint, не развёрнуто.** Требуется защита от косвенного раскрытия через `sort=price_asc/price_desc`, `in_stock=true`, пагинацию/порядок и тесты. Android ещё не обновлён.

### 2026-11-18 — защита сортировки/фильтра наличия (GitHub)
Коммит `3a14c33589e8419b92560aefb91bc7d21f77425d`: `ApiController::products()` получает скрытые категории через `CategoryVisibilityService` с наследованием. Для `sort=price_asc/price_desc` использует SQL `CASE WHEN p.category_id IN (hidden_ids) THEN NULL ELSE s.price END`, чтобы не сортировать скрытые товары по реальной цене. Для `in_stock=true` исключает скрытые категории, чтобы не раскрывать наличие через фильтрацию. Прямые поля `price/quantity` маскируются ранее. **Не развёрнуто, новый коммит ещё не проверен lint и тестами**; нужно отдельно проверить поведение поиска/филиалов/пагинации и Android.
