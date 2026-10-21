# Stage 5E.1 — gate перед следующей выкладкой

**Текущий ZIP только review: NO-GO из-за MatchCreator @@in_transaction на MySQL5.7.** Ничего из него сейчас не устанавливать. Базовый22-шаговый план5E и безопасный rollback остаются, но прежние требования enforced CHECK и HTTPS=on-only заменяются уточнениями ниже.

Подтверждено Lead, повторно не запрашивать: web/CLI PHP8.4.5; MySQL5.7.30-log; server HTTPS='1', SERVER_PORT80, REQUEST_SCHEME=http; document root www. HTTPS='1' принимается AuthSession и journal-access, Secure cookies выводятся по доверенному server flag. Port80/scheme http на backend сами по себе не основание выключать guard или менять proxy. Приложение не доверяет X-Forwarded-Proto/Forwarded/HTTP_HTTPS. Цепочку trusted ingress не подменять HTTP headers.

| Действие после нового согласования | Ожидание | STOP |
|---|---|---|
|Сначала portable MatchCreator transaction-guard fix в отдельном review, MySQL5.7 concurrency/rollback tests.|Accept атомарно создаёт room/game без MariaDB-only variable и без потери locks.|Ошибка1193, ослабление guard или duplicate/orphan game.|
|Read-only actual schema/grants/data inventory по новым pre/postchecks; использовать /usr/bin/php8.4, не старый default php.|InnoDB, compatible signed IDs/indexes/FK; invalid counts0; hash-policy metadata известна, без публикации hashes.|Partial/mismatched schema, invalid ownership/data, неопределённые write paths.|
|Принять платформенное ограничение ignored CHECK и компенсирующую application validation модель либо отдельно изменить платформу/схему.|Нет ложного заявления DB enforcement; raw SQL writers ограничены.|Требуется enforced CHECK, но сервер остаётся5.7 без согласованной альтернативы.|
|Backup DB/code/private config/storage + restore rehearsal вне production; maintenance всех endpoints и финальный snapshot.|Существующие данные сохранены и доступ закрыт.|Нет проверенного recovery/остались старые writers.|
|Только approved missing-only SQL по audit; затем новый pinned runtime ZIP, не этот blocked snapshot.|Нет CREATE поверх существующих таблиц; configs и .htaccess сохранены, assets доступны.|DDL partial failure, data loss, guessed ALTER.|
|В приватной staging установке на PHP8.4.5/MySQL5.7.30 повторить Auth, invite38-case guard matrix, lobby races и GameState lifecycle.|bcrypt registration12/unknown12, legacy10 login без изменения hash; HTTPS1 Secure/HttpOnly/SameSite; accept и правила прежние.|Unsupported hash policy без review, login/session loss, insecure cookie, 1193.|
|Smoke двух аккаунтов, rejected URL, outsider/side spoof, System/Grid/Solomon/Sealed, pending/timers/chat/journal/replay при прежних flags, reload/relogin.|Прежний lifecycle и доступ по ownership, никакого first/second authority.|Любой guard/ownership/command journal сбой; flags не включать глобально ради теста.|
|Отдельное решение Lead на открытие сервиса.|Все gates реально выполнены.|Unknown/fail оставляет maintenance.|

Нельзя возвращать публичный prototype index после появления account-owned games. Нет DROP/DELETE или автоматического восстановления backup поверх новых пользовательских данных. SQL/деплой/push этой задачей не разрешены. Существующие feature flags/allowlists и private configs не менять. Нужные шаблоны в operator/config-templates содержат placeholders и не являются готовыми production configs.
