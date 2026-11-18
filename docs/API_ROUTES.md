# Светофор — фактические HTTP-маршруты

Источник: `backend/bootstrap/app.php` текущей рабочей ветки, 2026-11-18. Это **реальный реестр**, в отличие от архивного `docs/api-contract.md`, который содержит первоначальные планы.

## REST API (префикс /api/v1)
| Метод | Путь | Контроллер / назначение |
|---|---|---|
| GET | `/health` | liveness без БД |
| GET | `/health/db` | ApiController::healthDb, `X-Health-Token` |
| GET | `/branches` | ApiController::branches |
| GET | `/categories` | ApiController::categories |
| GET | `/products` | ApiController::products (q, branch_id, category_id, in_stock, sort, page, per_page) |
| GET | `/products/{id}` | ApiController::product (branches, can_track, hide_store_stock, reviews) |
| GET | `/products/{id}/reviews` | ReviewController::index |
| POST | `/products/{id}/reviews` | ReviewController::create |
| GET | `/featured-products` | FeaturedProductsController::api |
| GET | `/home-promotions` | ContestController::home |
| GET | `/contests` | ContestController::index |
| GET | `/contests/{id}` | ContestController::show |
| POST | `/contests/{id}/join` | ContestController::join |
| POST | `/analytics/search` | SearchAnalyticsController::record |
| POST | `/auth/device` | ApiController::registerDevice |
| POST | `/auth/vk` | VkAuthController::login |
| POST | `/push/register` | PushController::register |
| POST | `/push/unregister` | PushController::unregister |
| GET | `/subscriptions` | SubscriptionController::index |
| POST | `/subscriptions` | SubscriptionController::create, JSON `product_id` |
| POST | `/subscriptions/cancel` | SubscriptionController::cancel, JSON `subscription_id` |
| GET | `/me` | ApiController::me |
| GET | `/me/reviews` | ReviewController::mine |
| POST | `/auth/logout` | ApiController::logout |

**Примечания:** `/products` серверный default per_page=20, максимум 100, Android запрашивает 50. `can_track` true только при полном наборе нулевых stock всех активных филиалов. Подписка на товар, не филиал, требует Bearer не-гостевого пользователя. `hide_store_stock` применяется к категории и потомкам.

## Админ-панель (/admin)
| Метод | Путь | Контроллер / действие |
|---|---|---|
| GET/POST | `/login` | AdminController::loginPage/login |
| POST | `/logout` | AdminController::logout |
| GET | `/` | AdminInsightsController::index |
| GET | `/import` | AdminController::importPage |
| POST | `/import/preview` | previewImport |
| POST | `/import/confirm` | import |
| GET | `/import/mapping` | mappingPage |
| POST | `/import/mapping/preview` | previewMapping |
| POST | `/import/mapping/save` | saveMapping |
| GET | `/imports` | imports |
| GET | `/imports/{id}` | importDetail |
| GET | `/reviews` | AdminReviewsController::index |
| POST | `/reviews/{id}/moderate` | moderate |
| GET | `/contests` | AdminContestsController::index |
| GET | `/contests/new` | edit |
| GET | `/contests/{id}/edit` | edit |
| POST | `/contests/save` | save |
| POST | `/contests/{id}/image/banner` | AdminContestImageController::uploadBanner |
| POST | `/contests/{id}/image/detail` | uploadDetail |
| GET | `/contests/{id}/participants` | participants |
| GET | `/featured` | FeaturedProductsController::index |
| POST | `/featured/{id}` | save |
| GET | `/products` | AdminController::products |
| GET/POST | `/products/{id}` | productEdit/updateProduct |
| POST | `/products/{id}/photo` | AdminProductImageController::upload |
| GET/POST | `/categories` | AdminCategoriesController::index/create |
| POST | `/categories/visibility` | saveVisibility |
| POST | `/categories/{id}/toggle` | toggle |
| POST | `/categories/{id}/delete` | delete |
| GET/POST | `/categories/{id}/edit` | edit/update |
| GET/POST | `/branches` | AdminController::branches/createBranch |
| POST | `/branches/{id}/toggle` | toggleBranch |
| GET | `/push` | AdminPushController::index |
| GET | `/push/{id}` | detail |
| GET | `/subscribers` | AdminSubscriptionsController::index |
| GET/POST | `/users` | AdminController::users/createUser |
| POST | `/users/{id}/toggle` | toggleUser |

Полные пути админки получаются с префиксом `/admin`; например `POST /admin/categories/visibility`. Админские POST требуют сессию/CSRF в соответствующих контроллерах. Перед изменением поведения проверять также конкретную функцию обработчика.

## НЕ путать с историческим контрактом
В `docs/api-contract.md` упомянуты `GET /api/v1/search`, `DELETE /api/v1/subscriptions/{id}`, `GET /api/v1/products/{id}/price-history`, `POST /api/v1/auth/{provider}/start`. На проверенном `backend/bootstrap/app.php` они **не зарегистрированы**.

### Проверка реального production bootstrap (18.11.2026)
По выводу `grep -nE 'categories|products|subscriptions|featured|promotions|contests' backend/bootstrap/app.php` подтверждены действующие регистрации `/api/v1/categories`, `/api/v1/products`, `/api/v1/products/{id}`, `/api/v1/featured-products`, `/api/v1/home-promotions`, `/api/v1/contests`, `/api/v1/contests/{id}`, POST `/api/v1/contests/{id}/join`, GET/POST `/api/v1/products/{id}/reviews`, GET/POST `/api/v1/subscriptions`, POST `/api/v1/subscriptions/cancel`, админ-маршруты товаров, категорий, конкурсов и рекомендуемых товаров. В этом выводе **отсутствует** `/admin/categories/visibility`, предусмотренный кодом GitHub. Наличие маршрута подписок само по себе не подтверждает продуктовую логику `can_track` или доставку FCM. Полный реестр маршрутов без фильтра ещё не просмотрен.

### Текущий production-ответ GET `/api/v1/products/{id}` (18.11.2026)
Фактический серверный метод `ApiController::product()` возвращает поля товара `id`, `barcode`, `article`, `name`, `category_id`, `manufacturer`, `unit`, `image_url`, `description`; массив `branches` с `branch_id`, `price`, `quantity`, `imported_on`, `updated_at` из `stock` **без фильтра активных филиалов**; объект `reviews` с `reviews_count`, `rating` по опубликованным отзывам. В предоставленном коде нет `can_track`, `hide_store_stock`. 404: `PRODUCT_NOT_FOUND`. Это описание **production на момент проверки**, не заменяет контракт GitHub. При синхронизации сохранить поля, добавить проверку активных филиалов и новые флаги без поломки Android.

### Production админка категорий — запись поля (18.11.2026)
Серверный `AdminCategoriesController::update()` делает `UPDATE categories SET name=?, parent_id=?, sort_order=?, image_url=? WHERE id=?`; `show_price_stock` не обновляется. `create()` делает `INSERT INTO categories (name, parent_id, slug, sort_order, image_url) VALUES (...)`, полагаясь на DEFAULT 1 для `show_price_stock`. В HTML формы `edit()` чекбокса нет. Планируем использовать существующий маршрут POST `/admin/categories/{id}/edit` и существующий столбец `show_price_stock`, без добавления второго маршрута, если нет необходимости.

### 2026-11-18 — различие production и GitHub по групповой видимости категорий
Пользователь проверил production `backend/bootstrap/app.php`: маршруты `/admin/categories` GET/POST, `/{id}/toggle` POST, `/{id}/delete` POST, `/{id}/edit` GET/POST присутствуют (строки 92–97); **POST `/admin/categories/visibility` отсутствует**. В GitHub ветке `feature/excel-column-mapping` `backend/bootstrap/app.php` этот POST маршрут уже зарегистрирован строкой 94 и ведёт на `AdminCategoriesController::saveVisibility()`. При развёртывании групповой формы необходимо согласованно перенести маршрут и контроллер; не добавлять маршрут повторно в GitHub. Production пока не менялся.

### 2026-11-18 — production GET /api/v1/categories
Из вывода production `ApiController::categories()`: `SELECT id, parent_id, name, slug, image_url, sort_order FROM categories WHERE is_active=1 ORDER BY sort_order,name`; `show_price_stock` в JSON не включён. GitHub `ApiController.php` уже содержит `CategoryVisibilityService` и возвращает `hide_store_stock`, но GitHub `CategoryVisibilityService.php` читает несуществующее в production `categories.hide_store_stock`. Нужно согласовать сервис с существующим `show_price_stock` и наследованием, затем проверить API продуктов и Android. Production пока не менялся.

### 2026-11-18 — production GET /api/v1/products
Из кода production `ApiController::products()` подтверждено: SELECT возвращает `s.price`, `s.quantity`, `s.imported_on` напрямую через LEFT JOIN stock, без `show_price_stock`; поиск по имени, фильтры branch/category/in_stock, сортировка name/price_asc/price_desc, пагинация. Скрытие только на Android не защищает API. Требуется серверная маскировка цены/остатка с наследованием от родителей и анализ косвенного раскрытия через фильтр/сортировку.

### 2026-11-18 — baseline production GET /api/v1/products?per_page=2
Пользователь выполнил `curl -sS -i https://baza.altaj-otdyh.ru/api/v1/products?per_page=2`. Ответ HTTP/2 200, `success:true`, `data` — две строки **одного товара** ID 6 «Кефир 1%» (category_id=55): филиал 1 `price:"74.00"`, `quantity:"5.000"`; филиал 2 `price:"75.12"`, `quantity:"12.000"`; `imported_on:"2026-11-15"`. `meta.pagination={page:1,per_page:2,total:4,total_pages:2}`. Пагинация LIMIT/OFFSET применяется к строкам join stock, тогда как total — COUNT(DISTINCT p.id): **несогласованная семантика**, возможны повторения товара на страницах. Baseline до деплоя; production не менялся.

### 2026-11-18 — baseline production GET /api/v1/products/6
Пользователь выполнил `curl -sS -i https://baza.altaj-otdyh.ru/api/v1/products/6`: HTTP/2 200, `success:true`, `data.id=6`, `name="Кефир 1%"`, `category_id=55`, `branches` содержит филиал 1 price 74.00 quantity 5.000, филиал 2 price 75.12 quantity 12.000, филиал 3 price 78.00 quantity 3.000; `imported_on`/`updated_at` присутствуют, `reviews={reviews_count:0,rating:null}`, `meta:[]`. `hide_store_stock` и `can_track` в baseline отсутствуют. Сохранить `branches` и `reviews` при обновлении.

### 2026-11-18 — baseline production GET /api/v1/categories
Пользователь выполнил `curl -sS -i https://baza.altaj-otdyh.ru/api/v1/categories`; HTTP/2 200, `success:true`, `data` — массив активных категорий с полями `id,parent_id,name,slug,image_url,sort_order`, `meta:[]`. Примеры иерархии: ID 52 «Молочные продукты» (`parent_id=null`), ID 55 «Молоко и сливки» (`parent_id=52`), ID 54 «Кисломолочные продукты» (`parent_id=52`). `hide_store_stock` отсутствует в production baseline. Полный вывод пользователь приложил 2026-11-18. Никаких production изменений.

### 2026-11-18 — маршрут группового сохранения (план production)
В production есть шесть маршрутов `/admin/categories` (GET index, POST create, POST `/{id}/toggle`, POST `/{id}/delete`, GET `/{id}/edit`, POST `/{id}/edit`), но нет `POST /admin/categories/visibility`. Для нового `AdminCategoriesController::saveVisibility()` требуется вставить маршрут после `POST /admin/categories` и перед параметризованными. **Пока не установлен на production.**

### 2026-11-18 — production маршрут групповой видимости установлен
`POST /admin/categories/visibility` → `AdminCategoriesController::saveVisibility()` точечно добавлен пользователем в production `bootstrap/app.php` после POST `/admin/categories`. PHP 8.5 lint прошёл. **Обработчик ещё не развёрнут; маршрут не готов к использованию до обновления AdminCategoriesController.** Резервная копия `bootstrap/app.php.bak-20261118`.
