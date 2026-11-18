# Светофор — история существенных изменений

## 2026-11-18 — Документация технической памяти (аудит)
- Добавлены `AGENTS.md`, `PROJECT_CONTEXT.md` и справочники `docs/DATABASE.md`, `docs/API_ROUTES.md`, `docs/SERVER_CONFIG.md`, `docs/FEATURES.md`.
- Сведения сверены с исходниками и миграциями ветки `feature/excel-column-mapping` на исходном HEAD `f24584ff7bc1a6be9f25c962d55d12556b594021`.
- Выявлены устаревшие описания маршрутов, подписок на отдельный филиал и старой push-системы в исторической документации.
- Выявлен риск: `007_product_wide_subscriptions.sql` содержит удаление подписок.
- **Не изменялись:** PHP/SQL/Kotlin исходники, рабочие маршруты, структура production БД, настройки сервера и дизайн.
- **Проверка:** чтение дерева и содержимого GitHub. Компиляция, PHP lint, интеграционные тесты, Android сборка, production SQL и cron в рамках этой документационной правки **не запускались**.
- **Далее:** проверить CI, `schema_migrations` production и точную команду Cron перед любыми изменениями сервера.

## Правило следующих записей
Дата / задача → изменённые файлы → почему → миграции и API → какие тесты реально выполнены → SHA → известные риски → следующий шаг. Новую запись вносить в том же коммите, что существенное изменение.

## 2026-11-18 — уточнение документации по результатам диагностики ISPmanager
- Сопоставлены текущие файлы GitHub с предоставленными пользователем фрагментами серверных `AdminCategoriesController.php`, `ApiController.php` и выводом поиска Kotlin.
- Зафиксировано расхождение: в GitHub реализованы `can_track` и `hide_store_stock`, но в просмотренном серверном коде их нет. Новый `show_price_stock` **не добавлялся**, чтобы не дублировать настройку.
- В `docs/SERVER_CONFIG.md` добавлены фактические пути, предупреждение о `.deploy`, отличии Android-копии и необходимости проверки активного web-root.
- В `PROJECT_CONTEXT.md` записаны текущая задача, безопасные следующие проверки и потенциальный дефект пагинации старого API.
- Изменения ограничены документацией GitHub; **никаких** изменений PHP, Kotlin, SQL, production, Cron или отправки push. Тесты/сборка не запускались.

## 2026-11-18 — подтверждён web-root и PHP в ISPmanager
- По предоставленным пользователем настройкам ISPmanager: корень домена `/backend/public`, PHP 8.5; CLI PHP 8.5.12.
- Прочитан фактический `backend/public/index.php`: подключает `backend/bootstrap/app.php` через `dirname(__DIR__)`.
- Зафиксировано, что корневой `public/` содержит только `.htaccess` с путём внешнего конфига и не является web-root.
- Изменены только документы GitHub. Сервер, БД, Android и Cron не менялись; HTTP PHP patch/SAPI и маршруты bootstrap ещё предстоит проверить.

## 2026-11-18 — подтверждена загрузка серверных контроллеров
- По grep `backend/bootstrap/app.php` установлены Composer autoload (`vendor/autoload.php`), `Router`, создание `ApiController`, `AdminCategoriesController` и `SubscriptionController`.
- Добавлены сведения в `docs/SERVER_CONFIG.md` и `PROJECT_CONTEXT.md`. Никакие исходники, серверные настройки и БД не изменялись.

## 2026-11-18 — инвентаризация маршрутов работающего backend
- Из вывода `grep` пользователя зафиксированы фактически зарегистрированные API и административные маршруты каталога, отзывов, подписок, акций, конкурсов и рекомендуемых товаров.
- В выборке нет `/admin/categories/visibility`, присутствующего в GitHub; требуется дальнейшая сверка.
- Обновлены `docs/SERVER_CONFIG.md`, `docs/API_ROUTES.md`, `PROJECT_CONTEXT.md`. Исходный код, БД и production не изменены.

## 2026-11-18 — проверка полей production ApiController
- По предоставленному пользователем grep серверного `backend/app/Controllers/ApiController.php`: методы `categories()` (строка 56) и `product()` (строка 188) присутствуют, идентификаторы `can_track` и `hide_store_stock` отсутствуют.
- Обновлены `docs/SERVER_CONFIG.md`, `docs/FEATURES.md`, `PROJECT_CONTEXT.md`. Никаких изменений PHP/БД/Android/production.

## 2026-11-18 — аудит фактического метода product()
- По серверному коду подтверждено: ответ карточки товара содержит `branches` и `reviews`, но не содержит `can_track`/`hide_store_stock`; выборка остатков не фильтрует `branches.is_active`.
- Документирован риск отображения остатков неактивных филиалов и необходимость сохранения существующего JSON-контракта.
- Обновлены только документационные файлы; сервер, PHP-код, MySQL, Android не менялись.

## 2026-11-18 — проверка следов настройки видимости категорий
- Пользователь сообщил пустой результат grep по `hide_store_stock`, `show_price_stock`, `ALTER TABLE categories` в серверных миграциях/backend.
- Зафиксировано ограничение проверки: наличие столбца в реальной БД этим не устанавливается; следующий шаг — read-only проверка `categories` через phpMyAdmin.
- Только документация; production и БД не изменены.

## 2026-11-18 — обнаружен существующий флаг в production MySQL
- По `SHOW COLUMNS FROM categories` в `prorock_law` подтверждено `show_price_stock TINYINT(1) NOT NULL DEFAULT 1`; в предоставленной схеме нет `hide_store_stock`.
- Выявлен конфликт именования и инвертированной логики между production БД и GitHub. Принято не создавать дублирующий столбец до полного аудита.
- Обновлена только документация, MySQL и код не менялись.

## 2026-11-18 — проверены текущие значения настройки категорий
- В phpMyAdmin выполнен `SELECT show_price_stock, COUNT(*) ... GROUP BY show_price_stock`; результат: `1 | 102` и других строк нет.
- Подтверждено, что все 102 категории в production показывают цены/остатки, скрытых категорий пока нет.
- Обновлена документация, production и БД не изменены.

## 2026-11-18 — аудит production контроллера категорий
- По grep подтверждены методы `index`, `edit`, `create`, `update` в `AdminCategoriesController.php`; совпадений `show_price_stock`, `hide_store_stock`, `checkbox` нет.
- Требуется просмотр методов и шаблонов перед минимальным патчем. Только документация, production не менялся.

## 2026-11-18 — изучены методы редактирования и сохранения категорий на сервере
- Из фрагмента production `AdminCategoriesController.php` подтверждено: форма `edit()` не содержит чекбокс `show_price_stock`; `create()` использует DEFAULT 1; `update()` не обновляет этот столбец.
- Зафиксировано требование сохранить CSRF, проверку роли, аудит, запрет циклов категорий и защиту удаления.
- Обновлена только документация; PHP и БД пока не изменены.

## 2026-11-18 — изучена форма создания и список категорий production
- Подтверждены дерево категорий, сортировка, ограничения по ролям и CSRF в `AdminCategoriesController::index()`.
- Форма создания и таблица не используют `show_price_stock`. Запланирован минимальный патч существующего столбца, без новой миграции.
- Изменена только документация.

## 2026-11-18 — локализованы методы загрузки и обработки категорий
- В production `AdminCategoriesController.php`: `all()` строка 220, `one()` 225, `fields()` 268. Тела методов ещё предстоит проверить.
- Только документация; сервер, MySQL и Android не изменялись.

## 2026-11-18 — завершена проверка helper-методов админки категорий
- Production `all()` не выбирает `show_price_stock`, `one()` выбирает все поля, `fields()` не обрабатывает настройку видимости и сохраняет прежние проверки данных.
- Для чекбокса требуется явная обработка отсутствующего POST-поля как 0; новую колонку MySQL создавать не нужно.
- Изменена только документация GitHub; серверный код и данные не менялись.

## 2026-11-18 — GitHub: патч отображения цен/остатков в админке категорий
- Коммит `959e9ee`: обновлён `backend/app/Controllers/AdminCategoriesController.php`, формы и SQL переведены на production `categories.show_price_stock`, сохранена инверсия группового выбора скрытых категорий.
- Не выполнены php -l, автоматические тесты и развёртывание; изменения пока только в репозитории.

## 2026-11-18 — исправление обработки чекбокса при редактировании категории
- В коммите `783e0b8` убрано повторное присваивание `$showPriceStock` в `create()` и добавлено отсутствующее в `update()`.
- Требуется PHP lint и проверка совместимости маршрута группового сохранения. Production не изменялся.

## 2026-11-18 — PHP 8.5 lint контроллера категорий пройден
- Проверен скачанный во временную папку `/tmp/svet22_AdminCategoriesController_check.php` файл из коммита `783e0b8`.
- Вывод пользователя: `No syntax errors detected in /tmp/svet22_AdminCategoriesController_check.php`.
- Синтаксис OK; функциональные проверки и развёртывание не выполнены. Следующий этап — проверка маршрута групповой настройки категорий.

## 2026-11-18 — выявлено различие маршрутов production и GitHub
- Production `backend/bootstrap/app.php` не содержит POST `/admin/categories/visibility`, подтверждено grep пользователя.
- GitHub `backend/bootstrap/app.php` уже содержит этот маршрут на `saveVisibility()`; дополнительный GitHub-патч не требуется.
- Для работоспособности групповой формы при развёртывании понадобится согласованное изменение production bootstrap и контроллера. Production не менялся.

## 2026-11-18 — аудит API категорий
- Production `GET /api/v1/categories` не отдаёт `show_price_stock`.
- В GitHub обнаружен `CategoryVisibilityService` с SQL на несовместимый столбец `hide_store_stock`; исправление сервиса необходимо до развёртывания.
- Production не менялся.

## 2026-11-18 — аудит выдачи списка товаров
- Production `GET /api/v1/products` отдаёт `s.price`, `s.quantity` без проверки категории. Есть фильтрация по наличию, сортировка по цене, поиск и пагинация.
- Установлена необходимость серверной маскировки цен и остатков; Android-only скрытие недостаточно. Никаких production изменений.

## 2026-11-18 — исправлен GitHub CategoryVisibilityService
- На production `CategoryVisibilityService.php` отсутствует (подтверждено ls).
- Коммит `c3f1b4b`: GitHub сервис читает `categories.show_price_stock`, рассчитывает `hide_store_stock = !show_price_stock || parent.hide_store_stock`.
- Не развёрнуто, PHP lint и функциональные тесты ещё не выполнены.

## 2026-11-18 — PHP lint CategoryVisibilityService пройден
- Временный файл `/tmp/svet22_CategoryVisibilityService_check.php` (GitHub коммит `c3f1b4b`) проверен PHP 8.5.
- Вывод пользователя: `No syntax errors detected in /tmp/svet22_CategoryVisibilityService_check.php`.
- На production не развёрнуто; функциональные проверки не выполнены.

## 2026-11-18 — подтверждена схема stock на production
- Через phpMyAdmin проверены поля `id`, `product_id`, `branch_id`, `price`, `quantity`, `imported_on`, `updated_at`.
- `price` и `quantity` NOT NULL; скрытие будет реализовано только в API-ответе, без модификации данных MySQL.
- SQL-запрос был только читающим, production данные не изменены.

## 2026-11-18 — подтверждена production схема branches
- Через phpMyAdmin проверены `id`, `code`, `name`, `address`, `is_active`, `created_at`, `updated_at`.
- `is_active` существует и по умолчанию равен 1. Для `can_track` должны учитываться только активные филиалы и известные нулевые остатки по каждому.
- Только аудит, изменений production нет.

## 2026-11-18 — проверено количество остатков по филиалам
- В production 3 активных филиала: Волочаевская (ID 1), Иркутская (ID 2), Байкальский (ID 3).
- В каждом филиале 4 записи stock; всего 12.
- Проверка совпадения товаров и полноты остатков ещё требуется; production не изменялся.

## 2026-11-18 — проверена полнота остатков и условие can_track
- В production 4 активных товара (ID 5–8), все имеют stock в каждом из 3 активных филиалов.
- Молоко, кефир, сахар: положительный остаток во всех трёх; хлеб: в двух положительный, в одном нулевой.
- По правилу «нет ни в одном активном филиале» `can_track=false` для всех четырёх. Production не менялся.

## 2026-11-18 — проверка дублей остатков
- SQL-запрос на повторяющиеся пары `product_id,branch_id` вернул пустой результат.
- Дубли в текущих данных не обнаружены; production не изменялся.

## 2026-11-18 — подтверждён UNIQUE индекс stock
- `SHOW INDEX FROM stock` подтвердил `uq_stock_product_branch(product_id,branch_id)` с `Non_unique=0`.
- Дополнительная миграция или индекс не требуются. Production не изменялся.

## 2026-11-18 — GitHub API: начата маскировка цен и остатков
- Коммит `767aa0d`: маскировка `price`, `quantity`, `imported_on` в списке и `price`, `quantity`, `imported_on`, `updated_at` в карточке для скрытых категорий.
- Возвращается `hide_store_stock`, учитывается наследование через сервис.
- Открытый вопрос: косвенное раскрытие через сортировку/фильтры, lint/тесты и Android; production не изменён.

## 2026-11-18 — PHP lint ApiController пройден
- Вывод пользователя: `No syntax errors detected in /tmp/svet22_ApiController_check.php` (коммит `767aa0d`).
- Функциональные тесты и развёртывание не выполнены.

## 2026-11-18 — API: защищены сортировка и фильтр наличия в GitHub
- Коммит `3a14c33`: цена скрытых категорий не участвует в сортировке по реальному значению (SQL CASE NULL); фильтр `in_stock=true` исключает скрытые категории.
- Сохраняются обычные поиск, сортировка, пагинация, прежняя структура ответа; требуется PHP lint и функциональные тесты.
- Production не изменён.

## 2026-11-18 — PHP lint API с защитой сортировки пройден
- Проверена версия `ApiController.php` из коммита `3a14c33`.
- Вывод пользователя: `No syntax errors detected in /tmp/svet22_ApiController_check.php`.
- Production не изменён; SQL и API функционально ещё не тестировались.

## 2026-11-18 — зафиксирован baseline production API
- `GET /api/v1/products?per_page=2`: HTTP/2 200, два ряда товара ID 6 в филиалах 1 и 2, `total=4,total_pages=2`.
- Выявлена несогласованность: total по DISTINCT товарам, пагинация по строкам JOIN stock.
- Ответ пока содержит реальные цены и остатки, флага скрытия нет. Production не изменялся.

## 2026-11-18 — baseline карточки товара
- Production GET `/api/v1/products/6` вернул HTTP/2 200, три филиала с ценами и остатками и блок reviews.
- Поля `hide_store_stock`/`can_track` отсутствуют; ожидаемый `can_track=false`.
- Только read-only проверка; production не изменялся.

## 2026-11-18 — baseline API категорий подтверждён
- Production GET `/api/v1/categories`: HTTP/2 200, иерархия parent_id сохранена (52→55/54), в текущем ответе нет `hide_store_stock`.
- Проверены read-only baseline трёх API endpoints: categories, products, products/6. Production не изменён.

## 2026-11-18 — production bootstrap: нет маршрута групповой видимости
- Read-only grep подтвердил ApiController (стр. 6,29) и AdminCategoriesController (стр. 31).
- Строка `categories/visibility` не найдена; перед внедрением проверить блок маршрутов и вставить точечно, без замены bootstrap.
- Production не изменён.

## 2026-11-18 — проверка блока production маршрутов
- Пользователь показал блок `bootstrap/app.php` с существующими шестью маршрутами категорий и остальными маршрутами админки.
- Подтверждено отсутствие `POST /admin/categories/visibility`; запланирована точечная вставка после резервной копии и PHP lint, без перезаписи bootstrap.
- Production пока не изменён.

## 2026-11-18 — production: точечно добавлен маршрут видимости категорий
- Пользователь добавил `POST /admin/categories/visibility` → `saveVisibility()` в `backend/bootstrap/app.php`.
- PHP lint успешен (`No syntax errors detected`); backup `bootstrap/app.php.bak-20261118` подтверждён.
- Пока контроллер на production не обновлён, новый маршрут нельзя использовать. Остальные файлы не изменялись.

## 2026-11-18 — production backup перед обновлением контроллеров
- Созданы `.bak-20261118` для `AdminCategoriesController.php` и `ApiController.php`.
- `CategoryVisibilityService.php` отсутствует в production, при установке будет новым файлом.
- Рабочие контроллеры ещё не заменены.

## 2026-11-18 — pre-deploy PHP lint трёх файлов пройден
- `/tmp/svet22-deploy-20261118/AdminCategoriesController.php` — PHP 8.5 lint OK.
- `/tmp/svet22-deploy-20261118/ApiController.php` — PHP 8.5 lint OK.
- `/tmp/svet22-deploy-20261118/CategoryVisibilityService.php` — PHP 8.5 lint OK.
- Production не заменён; следующий шаг — проверка связей и методов.

## 2026-11-18 — подтверждены связи подготовленных PHP-файлов
- grep временных файлов подтвердил `saveVisibility`, `show_price_stock`, `hide_store_stock`, `CategoryVisibilityService::all/forCategory` и использование сервиса в API.
- Новые файлы ещё не установлены на production; следующий шаг — сравнение публичных методов старых и новых контроллеров.

## 2026-11-18 — сравнение методов контроллеров перед деплоем
- Все публичные методы production `AdminCategoriesController` и `ApiController` присутствуют в staging версиях.
- Дополнительно появился `AdminCategoriesController::saveVisibility`.
- Новые файлы ещё не установлены. Следующий шаг — установка нового сервиса с проверкой.

## 2026-11-18 — production: установлен CategoryVisibilityService
- Новый файл `backend/app/Services/CategoryVisibilityService.php` установлен с правами 664.
- PHP 8.5 lint успешен, размер 1,6K.
- Контроллеры ещё не заменены, API пока прежний.

## 2026-11-18 — production: установлены оба контроллера
- `AdminCategoriesController.php` и `ApiController.php` установлены из staging, права 664.
- PHP 8.5 lint обоих файлов успешен.
- Весь комплект из сервиса, контроллеров и маршрута установлен; функциональная проверка API после деплоя ещё не проведена.

## 2026-11-18 — production API smoke test: карточка товара
- `GET /api/v1/products/6`: HTTP/2 200, success=true.
- 3 филиала с прежними ценами 74.00/75.12/78.00 и остатками 5/12/3.
- `hide_store_stock=false`, `can_track=false`, `reviews` сохранены.
- Проверка категорий и списка товаров ещё впереди.

## 2026-11-18 — production API smoke test: категории
- `GET /api/v1/categories`: `success=true`, 102 категории.
- ID 52, 54, 55: `hide_store_stock=false`.
- Список товаров и запись настроек через админку ещё не проверены.

## 2026-11-18 — production API smoke test: список товаров
- `GET /api/v1/products?per_page=2`: `success=true`, 2 записи.
- Кефир 1%, филиалы 1 и 2: цена 74.00/75.12, остаток 5/12, `hide_store_stock=false`.
- GET categories/products/product проверены; админское сохранение и наследование скрытия пока не проверены.

## 2026-11-18 — отдельное меню «Видимость цен и остатков» (GitHub)
- Добавлена страница `GET /admin/categories/visibility`, пункт меню для администратора, туда перенесена групповая форма.
- `POST /admin/categories/visibility` сохраняет настройки и возвращает на эту страницу.
- Создание и редактирование категории отделены от изменения видимости; новые категории используют default=1.
- Production не обновлён; lint и функциональные тесты ещё не выполнены.

## 2026-11-18 — исправление синтаксиса меню (перед деплоем)
- При PHP lint staging `AdminView.php` обнаружен Parse error на строке 25; `AdminCategoriesController.php` прошёл lint, `app.php` не проверялся.
- Исправлена пропущенная кавычка в PHP-конкатенации ссылки меню «Категории» перед проверкой роли `admin` (`0eb83fdc`).
- Production не изменён. Нужна повторная проверка lint.

## 2026-11-18 — подтверждение исправления AdminView.php
- Staging `AdminView.php` скачан по SHA коммита `0eb83fdc`; строки 24–26 содержат исправленную конкатенацию пункта меню.
- PHP 8.5 lint `AdminView.php`: `No syntax errors detected` (подтверждено пользователем).
- `app.php` ещё не проверен; production не изменён.

## 2026-11-18 — staging routes lint confirmed
- `app.php`: PHP 8.5 lint OK; GET `/admin/categories/visibility` (строка 93), POST `/admin/categories/visibility` (строка 95).
- Все три staging PHP файла теперь прошли lint; установка на production ещё не выполнена.

## 2026-11-18 — резервные копии перед установкой меню
- Подтверждены три backup-файла в `~/svet22-backup-menu-20261118`: `AdminCategoriesController.php` (21K), `AdminView.php` (7.3K), `app.php` (6.8K).
- Production ещё не обновлён; следующий этап — установка подготовленных файлов.

## 2026-11-18 — отдельный раздел видимости установлен на production
- Пользователь установил `AdminCategoriesController.php`, `AdminView.php`, `bootstrap/app.php` через `install -m 664`.
- Все три файла после установки прошли PHP 8.5 lint без ошибок.
- Backup: `~/svet22-backup-menu-20261118`.
- БД не изменялась. Проверка страницы в браузере и сохранения настроек ожидается.

## 2026-11-18 — браузерная проверка нового раздела
- Пользователь подтвердил корректное отображение `/admin/categories/visibility` после установки.
- Проверка сохранения и влияния на API пока не выполнена; настройки категорий не менялись.

## 2026-11-18 — исходное состояние API перед тестом скрытия
- GET `/api/v1/products/6`: товар 6, категория 55, `hide_store_stock=false`.
- Исходный JSON сохранён на сервере `/tmp/svet22-product6-before.json`.
- Сохранение настройки категории 55 ещё не подтверждено; тест согласован с пользователем.
