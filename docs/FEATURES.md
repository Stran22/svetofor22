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
