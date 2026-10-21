# Stage 5B — лобби и комната ожидания

Дата: 21 октября 2026. Реализация подготовлена для review Project Lead. Stage 5C не выполнялся; production не изменялся.

## Git и завершение Stage 5A

Работа продолжена в **том же настоящем локальном клоне** `C:/Users/mi/Documents/Berserk/stage5a-auth`, origin — `C:/Users/mi/Documents/Codex/berserk`. Не создавались новая несовместимая история, новый репозиторий или копия без Git. Перед изменениями проверены ветка codex/berserk-stage5a-auth, исходный HEAD `1e0163748c32c452443763252661072f11c66576` и status.

Проведён финальный review всех auth/runtime/SQL/tests/docs Stage 5A, повторены 48 security assertions. Проверены явный список добавления и отсутствие private config/БД/сессий/ключей в коммите. Прежние ignored auth-lab/auth-regression каталоги удалены после проверки абсолютных путей внутри checkout. В .gitignore помимо SQL exceptions нормализовались окончания строк; это не изменение scope.

**Stage 5A зафиксирован отдельным коммитом `822dacc89a32b7121fef4541f959f3d91ac2ee3f`** — `feat(auth): add isolated accounts and secure sessions for Stage 5A`.

От него создана текущая ветка **codex/berserk-stage5b-lobby**. Фактический HEAD остаётся **822dacc89a32b7121fef4541f959f3d91ac2ee3f**: изменения 5B оставлены в рабочем дереве для review, дополнительный коммит 5B не создавался. Merge/push/deploy и production SQL не выполнялись. Исходный checkout в Documents/Codex не редактировался и не переключался.

## Что реализовано

- После login — лобби с создателем, вторым игроком, статусом и действием. Кнопка «Создать игру» создаёт только комнату; строка таблицы доступна мышью/Enter/Space и выполняет POST open с CSRF. Кнопки «Присоединиться» нет.
- Комната отделена от games. До accept отсутствуют game_id/GameState/Engine side, Draft/Sealed, таймеры и automatic progress. Три состояния: open, awaiting_confirmation, started.
- Кандидат видит ожидание; creator — имя заявителя и «Подтвердить»/«Отказать». Reject очищает слот, инкрементирует version, немедленно закрывает серверный доступ прежнему кандидату; polling переводит его в лобби.
- Accept под row lock проверяет creator, точную version и candidate_request_id; создаёт ровно один начальный GameState с creator→host, candidate→player, связывает room и games одним commit. Account ID не подставляется в side 1/2.
- После accept оба видят безопасную заглушку и сохранённый game_id. Нет ссылки/redirect в legacy игру. Завершённость берётся из games.status=game_over, отдельного completed состояния room нет; завершённые исключены из активной таблицы.
- Polling каждые ~1.5 s, один GET в полёте, timeout/backoff, отсечение старых версий и ответов до mutation. После потерянного POST ответа восстановление только GET, автоматического повтора accept нет.
- Отдельная ручная migration lobby_rooms с InnoDB, FK, PK, необходимыми индексами, UNIQUE game_id и CHECK инвариантами. Нет runtime DDL/триггеров/destructive migrations.

Подробные контракты: [stage5b-lobby-api.md](stage5b-lobby-api.md). Ручное применение SQL и воспроизведение: [stage5b-lobby-sql.md](stage5b-lobby-sql.md).

## Примеры состояний

| Room | creator | candidate | accepted | game | state | version |
|---|---:|---:|---:|---:|---|---:|
| новая | 1 | NULL | NULL | NULL | open | 1 |
| после заявки | 1 | 2 | NULL | NULL | awaiting_confirmation | 2 |
| после reject | 1 | NULL | NULL | NULL | open | 3 |
| повторная заявка | 1 | 2 | NULL | NULL | awaiting_confirmation, **новый request id** | 4 |
| после accept | 1 | NULL | 2 | сохранённый новый ID | started | 5 |

Request id генерируется сервером случайно на каждую новую заявку; в публичном списке и ответе кандидату он отсутствует. Creator и accepted после запуска неизменны. Данные выше — схема переходов, не production записи.

## Доказательства атомарности и конкурентности

LobbyStore начинает одну mysqli transaction, выполняет SELECT room FOR UPDATE и проверки внутри lock. MatchCreator получает **то же соединение**, требует активную transaction и не начинает вложенную. Он использует существующие GameState/PlayerState/GameSettings/PlaymatCatalog, но не GameRepository constructor с потенциальным DDL. Начальный games.status=mode, JSON согласован по game_id/участникам, games.version=1 соответствует результату существующего create. После JSON записывается room linkage, затем единственный commit.

При любой ошибке откатывается весь accept. Commit с неопределённым ответом не повторяется вслепую: новое чтение room определяет результат, прежняя версия не может создать второй матч. AUTO_INCREMENT при rollback может иметь пропуски — это не orphan row.

Фактические коды/количества последнего прогона сохранены в [concurrency.json](evidence/stage5b/concurrency.json):

- Два разных кандидата одновременно: один 200, другой 403; до accept в БД только старая fixture game, ни одного нового матча.
- Две независимые сессии одного creator одновременно accept: один 200, другой 409; комната связана ровно с одной games.
- Accept/reject одновременно: один переход 200, второй 409; состояние соответствует победившей операции.
- Отнято UPDATE games: INSERT проходит, сохранение JSON падает; HTTP 503, games count до/после равен, version комнаты сохранена.
- Отнято UPDATE lobby_rooms: GameState создан внутри незакоммиченной transaction, linkage падает; HTTP 503, games count до/после равен, комната остаётся awaiting_confirmation.
- После восстановления прав тот же актуальный accept проходит. UNIQUE game_id и проверка связей подтверждают отсутствие двойных матчей.

Ни fault injection, ни GRANT/REVOKE не относятся к production/runtime: это действия setup пользователя только внутри точной изолированной test schema.

## Результаты проверок

Лаборатория: **PHP 8.1.9 / MariaDB 10.8.4 / InnoDB**, отдельный datadir `tmp/lobby-lab/data`, только 127.0.0.1:33561. До SQL сверены точные @@port/@@datadir. Runtime пользователи не имеют TRIGGER/DDL, auth user не имеет доступа к games. Четыре независимых PHP HTTP процесса; до room mutations session lock закрыт. Production config/db.php не использовался.

**45/45 HTTP assertions Stage 5B PASS** ([results.json](evidence/stage5b/results.json)), включая итог browser suite assertion:

- Create/creator open/claim/reject/accept, неизменность запроса при повторном открытии кандидатом, запрет self-accept и outsider access.
- CSRF, guest 401/HTML redirect, stale version/request, новая заявка того же account, запрет старого URL/POST и будущего матча отвергнутому после принятия другого account.
- Все перечисленные гонки, rollback двух точек создания, потерянный ответ/retry/read recovery и уникальность game linkage.
- SQL/JSON identity consistency, account IDs 1/2 без резервирования, начальный mode без draft/battle/сторон, read-only GET/poll snapshots и отсутствие скрытых данных в API.
- game_over исчезает из таблицы; logout/login восстанавливает доступ по room; invitation binding и legacy user_id не авторизуют room; старая games fixture не изменилась.

**13/13 browser UI assertions PASS** ([browser-results.json](evidence/stage5b/browser-results.json)), headless Microsoft Edge через Playwright, **три изолированных storage context**:

- Таблица обновляется без reload; клик по строке занимает место, creator видит запрос через polling; candidate не имеет кнопки подтверждения.
- Reject возвращает candidate в lobby без reload; старый room URL запрещён.
- Оба участника видят started placeholder; outsider row disabled; legacy game links отсутствуют.
- Реальный POST accept выполнен сервером, но ответ принудительно оборван browser transport: UI восстановился GET, число accept POST=1.
- Принудительно подан старый polling JSON с меньшей версией: started не откатился в candidate.
- Нет JS page errors; мобильная таблица 390px без горизонтального переполнения. Скриншоты просмотрены визуально: [заявка](evidence/stage5b/room-request.png), [созданная игра](evidence/stage5b/room-started.png), [мобильное лобби](evidence/stage5b/lobby-mobile.png).

**Повтор Stage 5A: 48/48 HTTP/security PASS** на отдельной auth-лаборатории после изменения login redirect ([auth-security-results.json](evidence/stage5b/auth-security-results.json)): registration/login/logout, hashing/bounds/unique race, rate limits между сессиями, fixation/rotation/CSRF, TLS/cookies/headers, missing schema fail-closed и изоляция unchanged replay от новой cookie.

**GameRepository regression — PASS на реальной изолированной MariaDB**: existing `test_repository_optimistic_locking.php` проверил create/load/CAS/stale save/sequential saves ([repository-regression.txt](evidence/stage5b/repository-regression.txt)). Отдельная проверка загрузила созданный accept GameState через неизменённый GameRepository и подтвердила account mapping/status. Это не skipped DB test; config существовал только в disposable regression source copy.

PHP lint **5/5 PASS**, JS syntax **2/2 PASS**, whitespace/diff check PASS. Protected gameplay paths сравнивались с commit Stage 5A: **diff exit=0** ([protected-paths.txt](evidence/stage5b/protected-paths.txt)). Старые игровые suites целиком не повторялись: их код не менялся, выполнены необходимые repository и auth регрессии. Не заявляем полную battle/Recorder regression на новом auth transport — этого transport ещё нет.

## Список файлов и итоговый diff review

Полный список — [file-list.txt](evidence/stage5b/file-list.txt).

Runtime: `src/Lobby/LobbyStore.php`, `src/Lobby/MatchCreator.php`, `www/lobby.php`, `www/assets/js/lobby.js`, `www/assets/css/lobby.css`. Небольшая адаптация `www/auth.php`: redirect после login и ссылка с account page. `config/auth.example.php` документирует отдельный lobby_db; `.gitignore` разрешает только три новых migration SQL.

Артефакты: три SQL migration/preflight/postchecks, два воспроизводимых HTTP/UI test runners, API/SQL/report docs и санитизированное evidence. В evidence нет local configs, БД, session IDs, CSRF/candidate tokens, invitation ledgers, TLS private keys или cookies. Полные temp traces/datadirs в release не включаются.

Проверены все новые/изменённые файлы: подготовленные SQL statements; неизменяемый server actor; права/версия/request под row lock; нет nested transactions, DDL или Engine calls; candidate не записывается в games; read projection permission и данные из одного SELECT; JS использует textContent и безопасно кодированный JSON; stale/unknown mutation outcomes не повторяются автоматически. Сохранены HTTPS/CSRF/session правила Stage 5A. Existing gameplay files **не менялись**: index.php, GameRepository/GameState, CommandJournalRepository, Engine, PrepareProcessor, Draft/Sealed, pending, timer, Chat, Recorder, LiveJournal, ReplayPreview.

## Ограничения и граница Stage 5C

Игровой процесс после accept **намеренно недоступен из нового UI** до Stage 5C: только placeholder и persisted game_id. Legacy prototype routes остаются прежним известным риском, в том числе при ручном подборе URL; новая auth-cookie не даёт им дополнительной личности, но Stage 5B не является их security migration. Нельзя выкладывать как завершённую систему игровой авторизации или включать обычные боевые переходы без отдельного review/gate Stage 5C.

SQL типы/FK/engine/permissions, HTTPS/FPM/session backend production не проверялись; preflight Lead обязателен. UI требует JavaScript. Нет архива, пагинации, закрытия/истечения пустых комнат, matchmaking или рейтинга. Для больших списков нужна отдельная пагинация/обслуживание, сохраняющая видимость собственных игр. Ограничения rate limiter/proxy Stage 5A сохраняются.

Не выполнялись Stage 5C, изменение боевых правил, Recorder, исправление журнала №493, runtime migrations, production SQL, merge/push/deploy. Работа после отчёта остановлена для рассмотрения Project Lead.
