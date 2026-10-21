# Stage 5E.2 — review и последующая подготовка

Текущий пакет разрешён только для review. Production SQL/push/merge/deploy не выполнялись и не разрешены этой задачей.

1. Проверить runtime commit, manifest и SHA-256 из `docs/evidence/stage5e2/bundle-check.json`. ZIP извлекать в отдельный staging-каталог, не поверх production.
2. Изучить `stage5e2-mysql57-fix.md`: единая транзакция, invalidation контекста, rollback и unknown COMMIT. P1 `@@in_transaction` устранён.
3. Сохранить выводы `stage5e1-mysql57-audit.md`: MySQL 5.7 игнорирует CHECK; нужны серверные инварианты и read-only postchecks. Существующие DDL не менялись. В ZIP нет CREATE/ALTER SQL.
4. До отдельного решения о deployment выполнить production inventory таблиц/типов/индексов/InnoDB/привилегий/данных, проверить backup и rollback. Ничего не пересоздавать поверх существующих таблиц, не связывать legacy ID 1/2 с новыми аккаунтами.
5. Подтверждённая платформа: Web/CLI PHP 8.4.5, MySQL 5.7.30-log, document root www, доверенное HTTPS=1 при SERVER_PORT=80 и REQUEST_SCHEME=http. Guard использует только HTTPS=on/1, не пользовательские forwarded-заголовки.
6. Перед production нужен staging smoke на точных версиях: register/login, два браузера, create/claim/accept/reject, old URL denial, existing game, journal/invitation, session/cookie/logout. Локальные результаты относятся к PHP 8.1.9/MySQL 5.7.39/MariaDB 10.8.4.
7. Сохранить существующие private configs, sessions, storage, artwork, DB и ключи. `.htaccess` в ZIP только reference: hosting handler/HTTPS directives требуют отдельного осмысленного merge.
8. Не менять политику bcrypt12 и существующие legacy hashes автоматически. Не выполнять автоматический повтор accept после ошибки COMMIT: сначала прочитать фактическое состояние комнаты.

Остановиться для review Project Lead. Следующее разрешение на deployment должно быть отдельным.
