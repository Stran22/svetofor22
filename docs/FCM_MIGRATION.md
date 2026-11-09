# Svet22 — переход с OneSignal на Firebase Cloud Messaging (FCM)

Firebase Project ID: `svet22-cdc8d`. Пользователь подтвердил скачивание `google-services.json` на локальный компьютер.

Статус: план реализации после аудита ветки work/backend-core. Этот документ не означает, что FCM уже включён на production.

## Проверенные существующие компоненты
- `app/Core/Application.php`: GET/POST /api/v1/subscriptions, POST /api/v1/subscriptions/cancel.
- `app/Controllers/SubscriptionController.php`: Bearer-аутентификация, проверка товара и филиала, запрет подписки при положительном остатке, уникальная подписка.
- `app/Services/PushNotificationService.php`: транзакционный outbox `push_delivery_log`, защита от повторной обработки, retry, переход статуса подписки в `triggered` при успешном ответе провайдера. Сейчас отправляет в OneSignal.
- `bin/retry-push.php`: CLI-обработчик очереди.
- `app/Core/Config.php`, `.env.example`: сейчас содержат только настройки OneSignal.
- `app/Services/TokenService.php`: гостевой Bearer-токен существует, но для подписок по продуктовым требованиям необходима проверка социальной авторизации.
- Android `SvetApi.kt`: методов подписок и регистрации FCM-токена пока нет.

## Порядок внедрения
1. Создать проект Firebase и зарегистрировать Android package по фактическому `applicationId`. Сохранить `google-services.json` локально в `app/`, НЕ добавлять сервисный приватный JSON в Git.
2. Добавить в Android Google Services Gradle plugin, Firebase Messaging SDK, `FirebaseMessagingService`, обработку обновления FCM-токена и запрос POST_NOTIFICATIONS для Android 13+.
3. Добавить миграцию MySQL `user_push_tokens`: id, user_id, device_uuid, token (или защищённое хранилище), provider='fcm', is_active, last_seen_at, created_at, updated_at; уникальность токена и устройства согласовать с существующей схемой. Токены считать секретными, не выводить в логи.
4. Добавить авторизованные API `POST /api/v1/push/register` (device_uuid, fcm_token), `POST /api/v1/push/unregister`; регистрация только текущему Bearer user_id. На logout удалить/отключить привязку устройства; после входа в другой аккаунт перепривязать.
5. Для `POST /api/v1/subscriptions` и отмены ввести проверку социальной авторизации, если сохраняется правило «подписка только для авторизованных», поскольку сейчас гостевой Bearer также принимается.
6. Создать `FcmSender` для HTTP v1 `POST https://fcm.googleapis.com/v1/projects/{project_id}/messages:send`, OAuth2 access token через service-account JSON вне web-root, TLS verification, timeouts, обработку 401/403/404/429/5xx и очистку недействительных токенов.
7. Сохранить существующие `subscriptions` и `push_delivery_log`. Изменить `PushNotificationService`, чтобы он отправлял через FCM нескольким устройствам пользователя. Успех одного устройства не равен доставке на все устройства: учитывать результат каждого токена и определить политику статуса подписки.
8. Важное отличие: FCM HTTP v1 не поддерживает OneSignal `idempotency_key`. Для предотвращения дублей требуется серверная дедупликация по (subscription_id,event_key,device/token), атомарное резервирование и чёткая политика обработки неопределённого сетевого результата. Гарантировать exactly-once доставку нельзя.
9. Проверить импорт Excel и ручную правку остатков: событие создаётся только при переходе 0 -> >0, отправка после commit, cron повторяет сбои. Не отправлять устаревшие события.
10. Android: добавить методы API подписок, отображение активного статуса и отмену, выбор филиала, экран «Отслеживаемое». Кнопка не должна сообщать об успехе до ответа сервера.
11. Тесты: без токена/гость/социальный пользователь; наличие >0; два филиала; повторная подписка; два устройства; замена FCM-токена; logout; повторный импорт; ошибки Google API; отсутствие разрешения уведомлений.
12. Переключение production: миграция -> регистрация токенов Android -> тестовый push -> смена провайдера -> мониторинг. Старые OneSignal-токены не превращаются автоматически в FCM-токены.

## Конфигурация
- `FIREBASE_PROJECT_ID` — публичный ID проекта.
- `FIREBASE_SERVICE_ACCOUNT_FILE` — абсолютный путь к закрытому JSON за пределами `public_html`.
- Никаких приватных ключей в Android, GitHub, логах или сообщениях.
- Проверить, что хостинг может выполнять исходящие HTTPS-запросы к `oauth2.googleapis.com` и `fcm.googleapis.com`.

## Предварительные ограничения
- До проверки фактических SQL-миграций и текущей production БД не запускать DDL на сервере.
- До получения `google-services.json` и service account (только локально/на сервере) нельзя проверить реальную доставку FCM.
- Автообновлятор `update-svet22.ps1` обновляет только `app/src/main`, поэтому Gradle-файлы и `google-services.json` потребуют отдельного процесса.
