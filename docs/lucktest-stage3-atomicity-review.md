# LuckTest Stage 3 — targeted atomicity review

Дата: 22 октября 2026. Проверка выполнена относительно завершённого Stage 3. Следующая стадия не начиналась.

## Подтверждённый дефект: общий Recorder

Engine::apply создавал отдельный GameState, но присваивал ему исходный объект historyRecorder. Во время startBattle этот объект меняется: commandStarted обновляет служебные поля; initial добавляет actions/events/capture, увеличивает ordinal и открывает battle_ready. Ошибка applyStartEffects не откатывала эти изменения Recorder, хотя основной GameState оставался в Settings.

Воспроизведение до исправления, PHP 8.1:

1. Один исходный Settings GameState с Recorder, обычная карта с start.get_hp=PHP_INT_MAX.
2. confirm_settings вызывает настоящий startBattle; initial уже создан, затем изменение hpMax выбрасывает TypeError.
3. Исходный GameState остаётся Settings, но его Recorder изменён.
4. В синтетическом каталоге исправляется hp на 2. Та же команда повторяется на том же исходном GameState и Engine, без перезагрузки и без замены Recorder тестом.
5. Повтор тоже отклоняется: прежний battle_ready остаётся открытым, повторный begin вызывает Overlapping supported history scope. Initial/actions/events уже загрязнены.

`test_lucktest_recorder_atomicity.php --diagnose` на старом Engine показал:

```json
{"first_success":false,"recorder_changed_after_failure":true,"retry_success":false,"status":"settings"}
```

Регрессионный тест до исправления падал на `failed attempt must not mutate source Recorder`.

Это подтверждённая утечка изменяемого observer через границу атомарности Engine. Штатный GameRepository при rejected не добавляет batch и в следующем locked исполнении создаёт новый Recorder; поэтому воспроизведение не доказывает существование повреждённых долговечных записей. Но прямой повтор на том же GameState нарушал контракт, блокировал повторную подготовку и мог передать загрязнённый batch коду, который продолжает использовать этот observer.

## Минимальное исправление

В Engine рабочее состояние получает clone Recorder. При Result::error или Throwable копия отбрасывается вместе с кандидатом. При успехе новый Recorder публикуется в state->historyRecorder вместе с остальными полями GameState. Persistence JSON/version по-прежнему не копируются.

В Recorder все изменяемые данные — массивы/скаляры, которые PHP копирует при записи. Единственный вложенный объект ReplayStateBuilder хранит каталог и имеет только читающие операции; он не получает изменяемое состояние команды. Поэтому текущей реализации достаточно обычного clone, без нового checkpoint API, сериализации Recorder или восстановления через Reflection. Тест сравнивает сериализованное полное состояние исходного Recorder до и после неудачи, включая его private bookkeeping.

Recorder, ReplayStateBuilder и TurnProcessor не менялись. Нативный initial по-прежнему создаётся перед стартовыми эффектами, а battle_ready — по прежней границе. Тест проверяет hp_max=8 в initial и hp=hpMax=10 после успешного эффекта +2 у обеих сторон. После повтора остаются один initial и один battle_ready; initial.started/settled, battle_ready.started/settled и turn.started встречаются ровно по одному разу.

Изменены только:

- `src/Core/Engine.php`: изоляция/публикация Recorder.
- `tools/test_lucktest_recorder_atomicity.php`: воспроизведение и проверка исправления без SQL.
- `tools/test_lucktest_recorder_sql.php`: повтор на том же locked GameState и запись результата через настоящий repository/journal/history, затем штатный end_turn.
- Этот отчёт.

## Borrowed transaction: read-only аудит

Production-код Db, MatchCreator, GameRepository, RoomTransaction и LobbyStore не менялся в этой проверке. Их содержимое сверено с исходными файлами.

### Инварианты и методы

`Db::withTransaction` через newInstanceWithoutConstructor устанавливает единственное обязательное типизированное поле connection; transactionActive и borrowedTransaction устанавливаются явно, прочие свойства имеют объявленные defaults. Незаданных обязательных полей в текущем Db нет.

Конструктор обычно создаёт соединение и выставляет utf8mb4. В borrowed-пути новое соединение не нужно: LobbyStore уже создаёт mysqli со strict error reporting и set_charset('utf8mb4'). MatchCreator получает его только через RoomTransaction::claimMatch после assertActive, блокировки комнаты и проверки участников. В callback снова вызывается assertActive до работы repository. Адаптер сохраняет именно это соединение и не меняет charset, autocommit или текущие locks.

Проверенные операции:

| Метод | Текущее использование и вывод |
| --- | --- |
| query, fetchOne, fetchAll | Catalog, journal, locks, CAS, history. Работают на той же mysqli; query переводит mysqli_sql_exception в DbQueryException. После callback query/fetch блокируются. |
| escape | Использует charset владельца. Применяется к JSON, идентификаторам команд и каталогу; соединение остаётся utf8mb4. |
| affectedRows | Читается непосредственно после соответствующего UPDATE, обеспечивает CAS и проверки journal. |
| lastInsertId | Инициализированное connection доступно; в данном borrowed-пути не требуется, ID игры получен из исходной mysqli до адаптации. |
| inTransaction/isBorrowedTransaction | Истинны внутри callback; false после выхода. Это локальное утверждение области, не запрос серверного transaction state. |
| isSameConnection | Сравнивает идентичность Db-обёртки, а не native mysqli. Repository и Journal получают один объект адаптера, поэтому проверка проходит корректно; разные обёртки не смешиваются. |
| beginTransaction/commit/rollback | Borrowed-объект запрещает все три метода. GameRepository пропускает begin/commit/rollback при borrowed; HistoryRepository сам не управляет транзакциями. |

GameRepository только после успешной команды сохраняет JSON, добавляет history и отмечает journal committed. При rejected пишет rejected в общей внешней транзакции; MatchCreator затем выбрасывает DomainException, и RoomTransaction откатывает также эти изменения. Ошибки SQL проходят наружу без внутреннего commit или отдельного rollback. На owned-пути прежние собственные begin/commit/rollback и TransactionCommitUnknownException сохранены.

Callback withTransaction инвалидирует адаптер в finally при успехе и исключении, до внешнего commit. Прямой query/fetch после выхода запрещён. escape/lastInsertId/affectedRows не проверяют срок области и могут читать native connection metadata после неё; сами эти операции не выполняют SQL и не могут сохранить игру. Это ограничение lifetime API, а не полный запрет использования любых методов объекта. В текущем MatchCreator ни адаптер, ни repository не выходят наружу.

### Commit и неопределённый исход

Единственный внешний commit делает RoomTransaction после проверки room/game связи. Контекст инвалидируется до commit. При исключении RoomTransaction предпринимает best-effort rollback, инвалидирует контекст и пробрасывает ошибку; автоматического replay нет. WWW Lobby возвращает 503 с указанием обновить состояние. Запрос с прежней версией/заявкой не создаёт повторную игру после уже состоявшегося commit.

У borrowed-ветки нет отдельного TransactionCommitUnknownException: commit принадлежит внешнему raw mysqli owner. Его исключение нельзя трактовать как доказательство rollback. Реально проверена модель потери подтверждения: subclass mysqli выполнил настоящий parent::commit(), затем выбросил синтетическое исключение. Ошибка дошла до вызывающего кода; последующий rollback не отменил уже committed игру, journal и ровно один initial. Callback выполнился один раз. Адаптер и RoomTransaction оказались неактивны. Повтор MatchCreator для уже started комнаты был отклонён до второго INSERT.

Это управляемая fault injection после реального commit, не воспроизведение сетевого обрыва. Фактический результат при сетевой ошибке следует выяснять новым соединением по комнате/игре/журналу. Поведение RoomTransaction существовало до этой проверки; в рамках read-only аудита оно не менялось.

### Reflection и возможная замена

В текущем использовании Reflection не оставляет Db в некорректном состоянии, не создаёт второго соединения и не завершает транзакцию. Подтверждённого runtime-дефекта этой конструкции не найдено.

Явный constructor branch, например array|mysqli, позволил бы создавать адаптер обычным new self($connection) и централизовать будущие обязательные инварианты. Это конкретное преимущество сопровождения, но также расширение публичного конструктора: появилась бы возможность получить borrowed Db вне ограниченного callback. Другой вариант потребовал бы явного capability/initialization API. Ни один вариант сам по себе не доказывает наличие серверной транзакции или правильного charset.

Решение: замену Reflection в этой задаче не выполнять. Сейчас callback и владелец проверены, все обязательные поля заданы, а изменение API шире необходимого исправления Recorder. При будущих изменениях обязательных полей/инициализации Db factory должен проверяться вместе с конструктором.

## Выполненные тесты

Все проверки выполнены в отдельной копии без config/db.php и без доступа к рабочей БД. PHP 8.1. Lint Engine и обоих новых тестов — PASS.

| Набор | Результат |
| --- | --- |
| test_lucktest_quick.php: Stage 1 | 186 проверок PASS |
| test_lucktest_quick.php: Stage 2 manual | 1014 проверок PASS |
| test_lucktest_quick.php: Stage 3 quick | 765 проверок PASS |
| test_lucktest_recorder_atomicity.php | 83 проверки PASS; также запускает 186 Stage 1 |
| test_lucktest_quick_sql.php | 64 проверки PASS |
| test_lucktest_recorder_sql.php | 16 новых проверок PASS после 64 SQL-регрессий |
| test_battle_history.php | 23 сценария PASS, включая два процесса и устаревший конкурентный запрос |
| test_battle_recorder.php | 20 сценариев PASS, включая HTTP-fixture, native battle lifecycle и гонку двух процессов |
| borrowed-audit.php, вне репозитория | PASS: charset, срок области, единственный внешний commit, модель потери подтверждения, отсутствие replay/дубликата |

SQL выполнялся на отдельной MariaDB 10.8.4, порт 33987, datadir `C:/Users/mi/Documents/Berserk/lucktest-stage3/sql-data`. Каждый набор получил свежую синтетическую schema. Для BattleHistory/Recorder сохранены тестовые случаи и safety guards; только в изолированных копиях тестов заменены endpoint/датадир и тестовые credentials. Для history-миграций выданы отдельные schema-level права без TRIGGER. Исходные regression tools в репозитории не изменены.

SQL retry-тест внутри одного locked callback вызывает ошибку после initial, затем повтор на том же объекте и возвращает только успешный Result. После commit проверены один durable initial, два captures, отсутствие дублирующих initial/battle_ready/turn событий, committed journal и успешный последующий end_turn без нового initial или recording_gap. Это проверка атомарности observer, а не добавление автоматических повторов в production.

## Артефакты и оставшиеся риски

- Полный diff этой коррекции относительно завершённого Stage 3: `C:/Users/mi/Documents/Berserk/lucktest-stage3-review/full.diff`.
- Изолированные summary: `battle-history-results/summary.json` и `battle-recorder-results/summary.json` в той же папке review.
- `regression-harness.diff` сохраняет только перенастройку тестовых подключений; `review-sql-setup.php` — создание синтетических схем, `borrowed-audit.php` — модель неизвестного commit. Эти вспомогательные файлы не перенесены в приложение.

Ограничения:

- Clone Recorder корректен при текущем read-only ReplayStateBuilder; появление изменяемых вложенных объектов потребует пересмотра clone-контракта.
- Borrowed factory доверяет открытому transaction/charset владельца и не проверяет их на сервере; фактический вызов MatchCreator эти предпосылки выполняет. Это не защитная граница для произвольного кода с доступом к query.
- Escape/connection metadata methods после callback не запрещены, хотя SQL запрещён. Текущие callers не сохраняют адаптер.
- Неопределённый commit требует перечитывания фактического состояния; обещания гарантированного rollback при потерянном подтверждении нет. WWW Lobby не пишет отдельную structured диагностику пойманного Throwable; это существующее ограничение внешнего owner/endpoint и не исправлялось в read-only части.
- SQL проверен на MariaDB 10.8.4; MySQL 5.7 и реальный сетевой обрыв не проверялись. HTTP-fixture Recorder прошёл, но полный production/browser UI не запускался.
- Файловый GameLog остаётся диагностикой запроса, а не транзакционным доказательством commit. BattleHistory сохраняет прежние experimental/partial ограничения для неаттестованных механик.

Рабочая БД, SQL-схема приложения, Db/borrowed API, семантика initial, ветки и коммиты не изменялись. Исправление остановлено для code review.
