# Stage 5D.1 — исправление тупика Grid Draft Timeout

Целевой P1 дефект исправлен в существующем continuous Grid auto/timeout. Набор frozen seed12 не запрещён и не заменён; исправлен выбор предыдущих действий, терявших возможность полного распределения. Правила 3 обычные /5 Орда, генерация, ручные действия, другие режимы и транспорт не изменялись.

## Git и границы

Ветка `codex/berserk-stage5d1-grid-timeout`, создана от проверенного Stage5D HEAD `04316e18ab04cb5e0f4494830c55b6ece8910103`. История5A–5D не переписана. Runtime diff: только `src/Core/Prepare/DraftProcessor.php` (+26 строк) и `PrepareProcessor.php` (+18/−3). Полный patch — evidence/stage5d1/runtime.diff. Остальные изменения — tests/docs/evidence. Финальный commit этой ветки выводится в git log и итоговом ответе.

Нет изменений Auth/Lobby/HTTP runtime, Engine, Recorder/journal/replay, BoosterGenerator, DraftCopyRules, Sealed, Solomon или discrete Grid rules. Их общие точки вызова возвращают прежние selections для Solomon/discrete; новая политика фильтрует только continuous Grid. Production SQL, push/merge/deploy не выполнялись, storage/games сохранён.

## Почему прежняя защита не предотвратила тупик

Изучены текущие классы и история `b0c626c` (Enforce draft copy limits), `62bdc21` (shared Solomon infrastructure). Ограничения3/5 не исчезали: BoosterGenerator ограничивает общий пул6/10 копиями, Grid проверяет copy limit непосредственного получателя. Старый continuous isViableSelection проверяет **количество** remaining >= needHost+needPlayer, но не то, кому эти копии ещё допустимы. Точная canCompleteDistribution появилась в общей инфраструктуре и использовалась для Solomon; continuous auto ею не пользовался. Stage5A–5D эти правила не меняли: это прежний пробел защиты, а не её возврат из-за auth/lobby.

Причина относится к **второму** случаю из дополнения Lead: корректный исходный набор становится нераспределимым после предыдущих решений алгоритма. Генератор не исправлялся и допустимые наборы не запрещались.

Доказательства:

1. Frozen stock из fixture имеет60 карт, укладывается в pool caps. Точная проверка успешна и для полного набора с пустыми picked, и для сохранённого состояния picked3/3. Исходный набор и ручной префикс допускают полное завершение.
2. Для пустых picked общий cap каждой карты равен2×deckLimit. Допустимые количества Host для каждой карты образуют целый интервал [max(0,n−L),min(n,L)], содержащий n/2. Их сумма содержит N/2; при штатных5–8 бустерах N=60..96 обе стороны могут получить30..48 карт. Поэтому глобальные6/10 caps и штатный размер достаточны для исходного распределения. Они не защищают будущие необратимые picks.
3. В сохранённом seed12 найден **первый** неправильный префикс:17-й выбор после frozen input, Host берёт card_22/card_37/card_23 → Host30/Player27, остаются card_15/card_27/card_6. У Player уже исчерпаны лимиты части необходимых копий. Count-only проверка пропускала действие, точное полное распределение уже невозможно. Конечные30/29 — поздний симптом.
4. Полная цепочка, первый unsafe выбор и проверки conservation записаны в regression.json. Синтетическая fixture и исторический Stage5D diagnostic не изменены; SHA256 исходного JSON записан в scope-check.json.

## Минимальное исправление

`automaticSelections()` берёт существующие законные rows/columns и для continuous Grid отбрасывает те, после которых canCompleteDistribution=false. `canCompleteGrid(positions)` предсказывает только picked и оставшееся multiset: pool+recycle+незабранный grid. Оно не делает draw/shuffle, не назначает карты, не меняет RNG или state. Используется существующая точная проверка3/5 и минимумов30, без нового алгоритма оценки карт. Weighted DraftAutoPicker ранжирует оставшиеся допустимые варианты прежним способом.

Политика применяется и к непосредственному timeout pick, и к вызванным им auto turns; continuous auto turns вне timeout также не выбирают заведомо нераспределимый префикс. Обычные ручные validSelections/pickRow/pickCol/pass/finish не менялись. Тест отдельно подтверждает: старый законный, но опасный ручной выбор перед17-м шагом по-прежнему принимается, тогда как авто его исключает. Это фильтр стратегии AI, а не новая запрещающая норма для игрока.

Если состояние **уже** не имеет полного распределения (например, сохранённый старый failed prefix, недостаточный total stock или cap обоих получателей), timeout возвращает явный Result::error до автоматического цикла. Он не отдаёт четвёртую/шестую копию, не переставляет ранее взятые карты, не теряет остаток и не считает guard500 успешным завершением.

Для continuous timeout переиспользован существующий snapshot/rollback helper atomicSolomon: имя оставлено ради минимального diff. При Result error и исключении восстанавливаются исходные поля GameState, players, version, persistence metadata. Ошибка после нескольких picks и при finalization тоже откатывается. Существующий HTTP уже сохраняет только успешный batch, поэтому транспорт не менялся.

Сохранение возможности полного распределения доказуемо по индукции: исходная проверка true; каждый авто-кандидат сохраняет её; fill/pass/recycle только переставляют remaining multiset. Таким образом, класс тупиков от расходования вместимости нужного получателя исключён для авто-последовательности, начинающейся с допустимого состояния. Если текущая геометрия не даёт безопасного ряда, сохранены законные pass/recycle/shuffle; отдельная fixture с допустимым распределением, но без безопасной текущей линии, завершается штатно. guard500 оставлен аварийным ограничителем для непредвиденных неисправностей, не способом завершения и не частью нормального успешного пути. Абсолютная стабильность всех будущих каталогов и произвольного неисправного RNG одной выборкой не утверждается.

## Инварианты

- Все существующие picked остаются у своих сторон. Результат соблюдает3/5; четвёртая обычная и шестая Орда не назначаются ради progress.
- Multiset всех исходных picked+grid+pool+recycle равен сумме конечных deckCards и материализованным экземплярам; уникальность instance IDs проверена отдельно.
- Draft завершается в view с минимум30 у каждой стороны. Уже существующий последующий view/sideboard требует колоду30..50 перед продолжением; новый лимит50 на число **draft picks** не вводился. Все карты штатного пула сохраняются до этого существующего trimming flow. Ручной finish раньше исчерпания пула не используется как auto обход.
- Фиксированные input/time/RNG дают одинаковый результат; seed12 повторён трижды с одинаковым конечным hash. После view повторный timeout не меняет state.
- Уже неразрешимые старые или вручную созданные префиксы явно отклоняются без частичного изменения. Автоматическое перераспределение старых picks потребовало бы изменения правил и в эту работу не входит.

## Сравнение до/после и регрессии

Оригинальный runner **выполнен до runtime edits** на04316e1: evidence/before-grid-runs.json. Из168 сценариев152 успешны,16 возвращают guard error (13/150 exhausted и3/3 seed12). Это новый замер; native RNG отличается от исторических чисел Stage5D.

После изменения те же168 сценариев успешны:150/150 exhausted достигают view;3/3 seed12 достигают view;15 boundary/terminal случаев соблюдают исходные ожидания. До deadline/missing timer не должны преждевременно завершать draft. Сравнение — before-summary.json /after-summary.json, полные начальные/промежуточные/конечные states сохранены. Default fixture runner теперь ожидает исправленное поведение; --expect-defect оставлен для исторической проверки на исходном коде.

Новый regression runner:1000 seeds frozen input, проверка каждого предоставленного AI-кандидата на законность и сохранение completion;100 новых generator pools5–8 boosters с обычными и Horde карточками. Все завершаются view с conservation/caps/instances. Отдельно18 сохранённых failed prefixes из Stage5D получают явную атомарную ошибку, а не guard. Проверены29/30 и30/29, карта только одному получателю, оба получателя capped, shortage, geometry recycle, error/exception rollback и ручная законность. Итог343354 assertion checks в regression.json (число проверок не подменяет число сценариев).

Старый timer/history test содержал фиктивные30 копий одного normal ukid у стороны и ожидал продолжения. Это уже нарушало3-copy rule и теперь правильно отклонялось. Исправлены **только данные тестов** на30 разных synthetic ukid; все assertions времени, history, событий, turn и discrete поведения сохранены. Исходное падение записано в legacy-timer-before-fixture-update.txt, затем test выполнен полностью с реальной lab DB, без skips.

## Повторная интеграция

Локально PHP8.1.9/mysqli/mysqlnd, MariaDB10.8.4/InnoDB, независимые HTTP workers и Edge/Playwright contexts. Точные datadir/port сверены до setup; runtime DB users без DDL/TRIGGER. Данные только synthetic lab.

| Проверка | Фактический результат |
|---|---|
| Stage5C HTTP integration |71/71 PASS (первый обычный прогон68/68 также PASS; окончательный расширен3 проверками Grid) |
| Browser E2E |7/7 PASS, System и общий auth/lobby/prep/place/battle flow |
| Auth security |48/48 PASS, включая локальный TLS cookie ingress |
| Lobby concurrency |44/44 PASS, candidate/accept/reject races и rollback |
| Существующие integration regressions |11/11 PASS, outputs приложены |
| Дополнительные Grid suites |4/4 PASS: draft_temporary_rules, draft_timer_history, booster, view_sideboard; DB sections не пропущены |
| Syntax/whitespace |PHP5 lint, Python2 AST, git diff check PASS |

HTTP Grid делает два реальных manual решения, затем подставляет сохранённый impossible prefix только лабораторным SQL: оба участника получают200/ok=false с явной ошибкой, JSON и games.version не меняются. Затем тот же матч загружается с canonical frozen draft и истёкшими total clocks обоих участников: auto completion, точное conservation materialized cards, view/turn/deal/place/battle и terminal проходят через существующий index. Solomon и Sealed проходят прежний полный integration lifecycle. No runtime HTTP edits.

Регрессионные suites11: repository optimistic locking, command journal repository (realDB), turn_timer, pending_wait_timer, informational_wait, turn_timeout, match_pause, settings_flow (с realDB), sealed_flow, solomon_draft, live_battle_journal. Никакие результаты не объявлены production проверкой.

## Воспроизведение и ограничения

1. php -n tools/test_grid_timeout_fixture.php (168 сценариев).
2. php -n tools/test_grid_timeout_regression.php (read-only in-memory catalog, controlled RNG/time, без DB/config).
3. python tools/test_integration_http.py (создаёт isolated lab,71 HTTP+browser+11 suites).
4. python tools/test_grid_additional.py (после шага3, запускает только ту же lab DB и4 suites в source copy; использует её private lab config, не production).
5. Auth runner: новый tmp/auth-lab/data и sessions, python tools/test_auth_http.py --openssl <local openssl>.
6. python tools/test_lobby_release.py (создаёт отдельную lab,44 HTTP; obsolete5B placeholder browser исключён, актуальный browser выполнен на шаге3).

Private root configs должны отсутствовать до runner; тесты их не перезаписывают и удаляют свои configs/processes в finally. Лаборатории и sessions не входят в Git; storage/games не удаляется. File list/source hashes/scope checks приложены.

Реальные production FPM/proxy/HTTPS/schema/grants/session storage по-прежнему не проверены. Synthetic catalog и SQL clock/HP fixtures — не естественная долгая production партия. История/replay/журнал493 не исправлялись. Проверка load/soak/неисправного RNG не проводилась. До выкладки остаются hosting gates и отдельное решение Lead по Stage5D deployment plan; закрытие Grid blocker не является разрешением deployment.

Работа Stage5D.1 завершена для review Project Lead. Новых игровых правил, SQL или автоматической выкладки не требуется для этого исправления.
