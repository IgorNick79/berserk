# Stage 5E.1 — PHP8.4/MySQL5.7 compatibility

**NO-GO для production release.** Bcrypt и HTTPS compatibility закрыты в исходниках, SQL audit и новый review ZIP готовы. Обнаружен и воспроизведён P1: accept на MySQL5.7 не работает из-за MariaDB-specific @@in_transaction в MatchCreator. Production не изменялся.

## Git и подтверждённое окружение

Ветка codex/berserk-stage5e1-compatibility. Проверенный runtime commit **cfbc809c36849ab84dfa99f9beb587c56d788b81**, основан на16e3d65 (HTTPS guard) и предшествующих5A–5E/5D.1 commits. Следующий docs/package commit не меняет runtime; его hash виден в Git/итоговом ответе. Ни main, ни production не считаются обновлёнными.

Lead подтвердил: web и CLI PHP8.4.5; MySQL5.7.30-log; server HTTPS='1', SERVER_PORT80, REQUEST_SCHEME=http; www document root. Эти сведения приняты без повторного запроса. Локально тестировались PHP8.1.9, MariaDB10.8.4 и отдельный MySQL5.7.39; точные8.4.5/5.7.30 в этой среде не запускались. Результаты не выданы за production execution.

## Bcrypt

Новая PasswordPolicy явно использует PASSWORD_BCRYPT cost12 при регистрации; неизвестный логин проверяется по фиксированному public synthetic dummy cost12. Это не зависит от смены PASSWORD_DEFAULT между PHP8.1 и8.4. [PHP manual](https://www.php.net/manual/en/function.password-hash.php) подтверждает default cost12 с8.4.

Старые bcrypt cost10 остаются рабочими, без массовой перезаписи hash и дополнительных UPDATE accounts grants. Для любого bcrypt cost4..11 исходный verify дополняется fixed dummy verifications с costs c..11: 2^c + сумма(2^c..2^11)=2^12. Поэтому основной bcrypt work budget равен регистрации/неизвестному логину. Padding создаётся не password_hash на каждый запрос, а password_verify готовых public hashes. Dummy success никогда не авторизует отсутствующий account. Corrupt/unknown hash получает dummy12 и отказ. Input bounds/NUL/CSRF/rate limiter/error text/session rotation не менялись.

Higher-cost existing hashes и другие распознаваемые algorithms сохраняют native verification, не понижаются. Для них одинаковый budget не заявляется: перед установкой проверить только агрегаты prefix/cost, отдельный review при нестандартных policies. Это не доказательство строгой wall-clock constant-time (остаётся DB/функциональная overhead), а устранение значительного version-dependent bcrypt cost10/12 расхождения. Успешный login не делает автоматический rehash или SQL migration.

28 policy assertions проверяют правильный/неправильный пароль для costs4/10/11/12/13, сумму работы, explicit registration12, unknown/corrupt и запрет авторизации по совпавшему public dummy plaintext. Auth HTTP suite расширен проверкой сохранённого cost12, входа по старому cost10, неизменности старого hash и общего сообщения wrong/unknown:52/52 PASS.

## HTTPS

Guard исправлен в16e3d65 и включён в этот runtime snapshot: journal-access принимает **только** доверенное server HTTPS='on' или '1', как AuthSession. Не читает пользовательские scheme headers и не отключает guard. При HTTPS1 cookie Secure остаётся true несмотря на backend port80/REQUEST_SCHEME=http. Existing lab loopback opt-in не менялся, production flags false.

38 invitation checks PASS, включая spoofed headers, insecure POST без расходования invite/session, one-time/replay/revoke/expiry/CSRF, binding без account principal и запрет account-owned scopes. Реальные HTTP/session/ledger в disposable copy; trusted server flag задаёт test router, ownership Db подменён read-only fixture. Это не настоящий TLS/FPM production test. Production guard или config агент не редактировал.

## SQL и оставшийся P1

Подробно — stage5e1-mysql57-audit.md. Все исходные CREATE5A–5C плюс chat/history принимаются локальным5.7, native FK/UNIQUE и history generated UNIQUE исполняются. CHECK синтаксически принимаются, но игнорируются. Минимальная SQL правка: убрать запрос к отсутствующей CHECK_CONSTRAINTS из release preflight и добавить read-only mysql57 data/metadata postchecks. Установочные DDL не менялись; бессмысленных duplicate variants, triggers и destructive SQL нет.

P1 доказан не только SELECT: реальный LobbyStore create/claim проходит, accept вызывает1193, rollback сохраняет awaiting_confirmation и не создаёт game. Отдельный SQL transaction test доказывает, что backend способен атомарно commit/rollback room+game. Проблема в runtime introspection, а не в отсутствии InnoDB атомарности. Исправление Lobby/MatchCreator не входит в запрошенный read-only SQL audit и не выполнялось. SQL не может создать безопасную @@variable replacement; нужен portable runtime guard review и MySQL concurrency повтор, без silent bypass.

## Что реально повторено

- 71/71 HTTP +7/7 Edge browser: auth/lobby/post-accept, System/Grid/Solomon/Sealed, place/battle/terminal, pending/timer/chat/journal/replay/relogin/outsider. Это MariaDB10.8 lab, не MySQL5.7 end-to-end.
- 52/52 Auth security и44/44 Lobby concurrency на MariaDB,11/11 существующих regression suites.
- 38/38 invitation guard/regression,28/28 password policy assertions.
- 22 MySQL5.7.39 compatibility assertions: DDL/metadata/FK/UNIQUE/rollback/commit плюс **ожидаемые** ignored CHECK и failing accept1193. Они подтверждают blocker, не означают release PASS.
- PHP lint6, Python AST; pinned runtime/hash and ZIP roundtrip checks. Engine/Prepare/Grid/Sealed/Recorder не менялись, storage/games сохранён.

Evidence — docs/evidence/stage5e1: results, traces без credentials, mysql57 preflight/baseline, policy/lint. Synthetic DB/session/ledger/configs не идут в ZIP. Long battle clocks/HP faults остаются lab fixtures, не длительной production игрой. Реальные runtime grants/data/schema, backups, shared sessions/cookie smoke ещё требуют Lead; известные PHP/MySQL/HTTPS/docroot сведения повторно не запрашиваются.

## Manifest/ZIP и решение

Новый stage5e1-release-manifest.json фиксирует runtime cfbc809, новую PasswordPolicy и guard16e3d65, все source hashes, target facts, SQL references и NO-GO причины. Новый ZIP evidence/stage5e1/stage5e1-runtime-cfbc809.zip:361 deployable runtime file, .htaccess только operator reference; config templates и read-only SQL отдельно. Нет CREATE installer, production configs, storage, sessions, БД, ключей или тестовых runners. Предыдущий5E ZIP неизменён, этот snapshot также **не устанавливать** из-за P1. Bundle SHA/entry counts — bundle-check.json.

Следующий необходимый шаг: отдельное решение Lead на targeted MatchCreator transaction-guard fix, потом полный accept/races/rollback на MySQL5.7 и целевом PHP. После этого новый manifest/ZIP и ранее согласованный manual deployment checklist. Игровые правила, Engine/Grid/Recorder, SQL production, push/merge/deploy этой задачей не менялись/не выполнялись.
