# Journal invitation HTTPS guard

В journal-access.php проверка доверенного server variable теперь точно соответствует AuthSession: HTTPS === 'on' либо HTTPS === '1'. Произвольные HTTP_HTTPS, X-Forwarded-Proto, Forwarded и X-Forwarded-Ssl не используются. HTTPS guard не отключён; existing explicit loopback lab opt-in не менялся и должен быть false в production. Invitation cookie остаётся Secure/HttpOnly/SameSite=Strict при обоих принятых HTTPS значениях.

Runtime diff — одна строка journal-access.php. Auth, invitation ledger, ownership, redirect и игровые классы не менялись. Production не подключался и не изменялся; реальные trusted proxy/FPM настройки этим исправлением не подтверждены.

Команда: python tools/test_journal_access_https.py. Итог38/38 проверок, включая исходный test_journal_invitations.php: HTTPS=on/1, отказ для пустого/off/0/true/ON даже с поддельными headers; insecure POST не расходует приглашение и не создаёт session. Проверены secure cookie attributes, CSRF без расходования invite, one-time consume/redirect, SID rotation, binding без account principal, запрет повторного/отозванного/истёкшего приглашения, account-owned и disabled game denial, query parameter denial. Исходный suite также проверяет scope/revocation/hashed credentials/default off/no replay principal.

Среда — настоящий loopback PHP HTTP endpoint, session storage и JournalInvitations ledger в новой disposable source copy. Trusted server HTTPS задаёт test router из process environment, а не клиентский header. Ownership SELECT использует read-only fake Db, без SQL/socket к БД. Cookie передаётся harness вручную по loopback для симуляции доверенного server HTTPS; это не реальный TLS/browser или production PHP-FPM test. Private ledger/session/temp configs удаляются после теста; evidence содержит только имена checks и PASS, без invite/binding/session/CSRF tokens. PHP lint и Python AST PASS.

Старый Stage5E ZIP остаётся неизменённым историческим review snapshot d59fc66 и не включает эту новую строку. Данный patch следует включить в следующий согласованный release manifest; готовность всего релиза этим изменением не заявляется.
