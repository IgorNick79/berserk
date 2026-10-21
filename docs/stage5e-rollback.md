# Stage 5E — безопасный rollback

План, не выполненная операция. Сначала webserver maintenance/IP allowlist закрывает **все** auth.php, lobby.php, index.php (включая AJAX chat/playmat/commands), battle-journal.php, replay.php, journal-access.php и rewrite aliases. Остановить старые writers; проверить с внешнего адреса503/закрытый доступ. Не полагаться только на скрытую кнопку UI.

Сохранить свежий backup БД, текущие code/config/storage и логи без публикации секретов. Не удалять accounts/lobby_rooms/games/journals/history/storage/games, не менять ID и FK. С момента первых новых account-owned games исходная старая версия **не является безопасным rollback target**.

Безопасный target — отдельно проверенная версия, сохраняющая AuthContext/GameAccess/shared CSRF и room ownership. Если такой версии нет — остаться в maintenance. Stage5B placeholder можно использовать лишь с полностью закрытыми game/chat/history endpoints; это не разрешение публично вернуть prototype index. Время восстановления/выбор версии фиксирует Lead.

Нельзя просто вернуть старый index с ?first/?second, даже если только один новый матч создан. Нельзя отключать HTTPS guard, authorisation или выдавать прежний legacy binding за аккаунт. Нельзя восстанавливать старую БД поверх новых пользователей/матчей автоматически. Data restore — отдельное решение с сопоставлением новых данных и планом без потерь.

После исправления — schema/ownership preflight, HTTPS/session gate и smoke по deployment checklist под maintenance; затем отдельное решение на открытие. Existing production configs и feature flags сохранять, не заменять примерами.
