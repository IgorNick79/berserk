# Stage 5E.2 — переносимый транзакционный контекст MatchCreator

Основа: `1667e8f`, ветка `codex/berserk-stage5e1-compatibility`. Production: PHP 8.4.5 / MySQL 5.7.30-log, по подтверждению Project Lead. Лаборатории: PHP 8.1.9, реальные изолированные MySQL 5.7.39 и MariaDB 10.8.4/InnoDB. Точные production-версии локально не запускались.

## Исправление и граница доверия

`MatchCreator` больше не обращается к `@@in_transaction`. Вместо произвольного mysqli он получает `RoomTransaction` и ID комнаты. Это управляемая capability внутри доверенного PHP-кода, а не пользовательский токен и не проверка произвольных внешних транзакций.

`LobbyStore` по-прежнему владеет единой операцией: начало транзакции → блокировка комнаты → проверка инициатора, версии и request ID → создание GameState → привязка комнаты → commit. Begin/commit/rollback вынесены в `RoomTransaction::run`, вызываемый только `LobbyStore`. Соединение LobbyStore закрытое, создаётся самим store и не смешивается с внешними владельцами begin/commit. Контекст не предназначен для оборачивания уже начатых вручную транзакций; проверка SQL-состояния сервера не требуется и не выполняется.

Контекст создаётся закрытым конструктором после резервирования владельца connection; вложенный управляемый run запрещён. Он действителен только во время синхронного callback. Перед COMMIT контекст инвалидируется; при исключении, rollback и выходе он также инвалидируется, а реестр владельца очищается. Клонирование, сериализация и восстановление контекста запрещены.

Перед созданием матча контекст повторно проверяет строку `FOR UPDATE`: ожидающий статус, creator/candidate, различные положительные ID, отсутствие accepted/game и корректный request ID. Контекст допускает один MatchCreator. После записи JSON фиксируется ID созданного матча; перед commit проверяется, что именно этот game связан с той же started-комнатой и SQL-участниками. Непривязанный созданный матч вызывает rollback. Сам MatchCreator не начинает транзакций и не выполняет COMMIT.

Проверка существующей модели комнат в `LobbyStore::row` выполняется перед переходами независимо от CHECK: shape open/pending/started, положительные ID/version, отсутствие самопринятия и корректный pending request ID. Это особенно важно для MySQL 5.7. Не добавлены SQL-триггеры, новые таблицы или миграции.

## Неопределённый результат COMMIT

Автоматического повторения accept нет. Если COMMIT выбрасывает исключение, старый контекст больше нельзя использовать; best-effort ROLLBACK не трактуется как доказательство отсутствия commit. Следующий запрос читает комнату через новое соединение: started означает успех; прежний pending позволяет явный повтор с актуальными version/request. Блокировка строки, CAS-проверки и одноразовый request не допускают второго матча.

Тест внедряет подкласс mysqli только в лаборатории через Reflection: он либо выполняет настоящий COMMIT и затем выбрасывает исключение вместо подтверждения, либо выполняет rollback и выбрасывает исключение. Это fault injection на границе COMMIT с реальными InnoDB-записями, а не физическое отключение сети. Дополнительно сохранён тест потери HTTP-ответа после успешного commit.

## Проверки

Для каждой СУБД выполнен прежний многопроцессный HTTP Lobby suite с runtime-пользователем без DDL/TRIGGER и дополнительные context/fault проверки. Покрыты создание, claim, accept, reject, два кандидата, двойной accept, accept/reject, устаревший request, запрет самопринятия, ошибка UPDATE GameState после INSERT, ошибка UPDATE lobby_rooms, unknown COMMIT в обоих исходах, отсутствие orphan и согласованность SQL/JSON/комнаты.

Проверки без разрешённого контекста: bare mysqli, завершённый и откатившийся контекст, повторное создание, вложенный run, неверные участники, комната без pending-заявки, попытка commit без привязки. На MySQL дополнительно внесены синтетические нарушения игнорируемого CHECK и подтверждён отказ accept/reject без мутации.

Отдельный существующий GameRepository regression намеренно создаёт legacy-матч с account IDs 990001/990002 вне лобби. Проверка orphan учитывает только матчи лобби и исключает эту явно выделенную фикстуру и исходную legacy-игру 1. Производственные данные не используются.

| Набор | MySQL 5.7.39 | MariaDB 10.8.4 |
|---|---:|---:|
| Lobby HTTP/races/rollback | 46/46 | 46/46 |
| Context и fault injection | 19/19 | 16/16 |
| Полный Stage 5C/5E HTTP | 71/71 | 71/71 |
| Edge/Playwright, два браузерных контекста | 7/7 | 7/7 |
| Gameplay/storage regression suites | 11/11 | 11/11 |

Среди 11 suites: repository CAS, command journal, turn/pending/informational timers, timeout, pause, settings, Sealed, Solomon и live battle journal. HTTP включает Grid timeout, Sealed, бой и replay. Отдельный исторический прогон 1000 Grid seeds в 5E.2 не повторялся: игровой код не менялся.

Итоговые машинные результаты находятся в `docs/evidence/stage5e2/`. Команды воспроизведения:

```text
python tools/test_stage5e2_lobby.py mysql57
python tools/test_stage5e2_lobby.py mariadb108
python tools/test_stage5e2_integration.py mysql57
python tools/test_stage5e2_integration.py mariadb108
python tools/test_stage5e2_auth.py
```

## Diff review и ограничения релиза

Runtime diff ограничен `src/Lobby/LobbyStore.php`, `src/Lobby/MatchCreator.php` и новым `src/Lobby/RoomTransaction.php`. Engine/Core, Grid, Recorder, Draft/Sealed и игровые правила не изменены. SQL и результаты аудита 5E.1 сохранены без изменений. В runtime нет `@@in_transaction` и нет пробных SQL-команд, меняющих транзакционное состояние ради проверки.

P1 несовместимости MatchCreator закрывается успешным accept и конкурентными/rollback проверками на MySQL 5.7. Release остаётся пакетом для review Project Lead: фактический production inventory схемы/привилегий/данных, backup, session/cookie и smoke на точном PHP 8.4.5/MySQL 5.7.30 ещё требуют отдельного deployment-процесса. Подтверждённые настройки HTTPS=1/port80/schemehttp/docrootwww сохранены в manifest; предыдущие bcrypt12 и HTTPS on/1 исправления включены.

Production SQL, push, merge и deploy не выполнялись. Лабораторные datadir/config/session удаляются только из собственных проверенных tmp-каталогов; storage/games сохраняется.

## Итог и пакет

Runtime-коммит: `1c723134d692b52772c1ceffe31290f361d1e81b`. Auth security: **52/52** на MariaDB, включая реальный TLS, Secure cookie, CSRF, rate limiting, bcrypt12 и вход со старым bcrypt10 без изменения хеша. PHP lint: 4/4; Python AST: 3/3 тестовых runner. Diff review не выявил незакрытых замечаний в рамках исправления.

Manifest: `docs/stage5e2-release-manifest.json`. ZIP: `docs/evidence/stage5e2/stage5e2-runtime-1c72313.zip`. SHA-256, размер, состав и byte-for-byte roundtrip записаны в `docs/evidence/stage5e2/bundle-check.json`; список содержимого — `file-list.txt`. Private configs, sessions, storage, базы и ключи не включаются. Старые 5E/5E.1 артефакты оставлены историческими снимками.

Статус: **Stage 5E.2 готов к review Project Lead; P1 исправлен, deploy не выполнялся.** Остаются production inventory и smoke на точных версиях, перечисленные выше и в checklist.
