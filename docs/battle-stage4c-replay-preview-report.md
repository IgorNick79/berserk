# Stage 4C — экспериментальный replay preview

Дата: 2026-10-20. Ветка: `codex/battle-replay-stage4c`.

Базовый HEAD перед фиксацией Stage 4C и отдельный коммит Stage 4B: `870d3cc38e791ceec29b7eb4db7c1be25ab5fe0c` (`feat(battle): record partial replay steps transactionally`). Stage 4C фиксируется отдельным коммитом в codex/battle-replay-stage4c и публикуется этой веткой по последующему запросу пользователя. Точный commit доступен через git log этой ветки; evidence сохраняет HEAD на момент тестирования. Merge, deploy и production SQL не выполнялись.

## Результат

Добавлен отдельный read-only `/replay.php` с HTML preview и тремя JSON endpoints. Он читает настоящие сохранённые ReplayState, проверяет SHA-256 через HistoryRepository, не восстанавливает GameState и не вызывает Engine, sync или automatic progress. Полные данные доступны только после SQL `game_over`, `has_initial/has_final` и committed final journal, а также проверки серверного allowlist и доверенного участника/администратора. Проверяется непрерывность номеров сохранённых кадров и checksum всех кадров до выдачи ответа.

История остаётся experimental/partial/incomplete. Старый `completedServerHistory()` не ослаблен; preview не меняет completeness или recording_status. API/UI явно показывают gaps и не достраивают отсутствующие состояния. Последовательность кадров — только сохранённые состояния, а не обещание непрерывного покрытия.

Recorder включается только игнорируемым `config/battle_history.local.php`: два независимых выключенных по умолчанию переключателя и явные целочисленные allowlist для записи/preview. GET/POST не активируют запись. Общая factory применяется к основному GameRepository и automatic/recovery paths index.php.

Команды стадии `place` разрешённых матчей выполняются через существующий `executeLockedCommand` с CAS, журналом и общей транзакцией. Ссылки расстановки содержат session CSRF и ожидаемую версию. Детерминированный command ID обеспечивает идемпотентность повтора ссылки. Final confirm_place сохраняет initial и battle_ready атомарно с игровой командой. Дополнительного неатомарного writer нет. Более ранние mode/deck/draft/deal остаются прежними и не заявляются Replay Steps; allowlist должен быть подготовлен до расстановки.

## Существенное ограничение аутентификации

Существующий прототип `?first/?second` сам устанавливает `$_SESSION['user_id']`; это не доказательство личности. Preview доверяет только отдельному целочисленному `$_SESSION['berserk_authenticated_user_id']`, который игровые routes не создают. Разрешённые для записи тестовые матчи также требуют этот principal и совпадение с выбранной ролью. Admin ID берётся исключительно из server config и не разрешает активные полные реплеи.

Настоящий login/SSO bridge в этом репозитории отсутствует. До ограниченной production активации Project Lead должен подключить доверенное установление principal после реальной проверки пользователя. Нельзя копировать legacy user_id/GET role в trusted key. Тестовый login fixture существует только в изолированном стенде/evidence и не входит в production www. При отсутствии trusted principal preview возвращает 401. Обычные неразрешённые матчи продолжают использовать прежний прототип без изменения его правил.

## UI и правило Гидры

Использованы существующие battle_cell.tpl, global.css, Coords и извлечённая без изменения HTML чистая разметка BattleCardMarkup из BattleScreen. ReplayScreen получает только сохранённые массивы DTO; не считает эффекты, HP или характеристики. Навигация first/prev/next/last выполняет только GET сохранённого кадра. Нет battle-sync.js, форм и игровых кнопок. Показаны название карты, владелец, координаты, HP, закрытие, сохранённые статусы, движение, удар, броня/фишки и остальные сохранённые зоны. Исторически скрытая карта помечается; раскрытие её определения возможно только в завершённом авторизованном preview.

Поддерживаемые типы: `initial`, `battle_ready`, `move`, `combat`, `series_end`, `turn_end`, `match_terminal`.

Гидра подтверждена реальными HTTP/UI:

1. Завершённый combat: `closed=false`.
2. Добровольный attack_series_end: самостоятельный series_end, `closed=true`.
3. End turn: отдельный turn_end.
4. Возврат к первому combat снова показывает `closed=false`; старые байты snapshot не изменяются.
5. При естественном завершении второй combat уже содержит `closed=true`; отдельного series_end нет.

Coin, Degasca, сложные инстанты, Вальхалла, неизвестные prop/modifiers/markers и прочие неподдержанные действия остаются источниками partial/gap. Их механика не менялась. Даже resign может дать консервативный `uncovered_command:resign` gap перед реальным terminal snapshot — UI его не скрывает.

## Изменённые файлы Stage 4C

Изменены:

- `src/Core/BattleHistory/HistoryRepository.php` — отдельный проверяемый SELECT-only finished partial reader.
- `src/View/Screen/BattleScreen.php` — делегирование прежней HTML разметки чистому общему компоненту.
- `src/View/Screen/PlaceScreen.php` — необязательный контекст CSRF/version ссылок расстановки.
- `www/index.php` — server factory и journal-подключение разрешённой расстановки.

Добавлены:

- `src/Core/BattleHistory/HistoryRuntime.php`
- `src/Core/BattleHistory/ReplayPreview.php`
- `src/View/Screen/ReplayScreen.php`
- `src/View/Ui/BattleCardMarkup.php`
- `www/replay.php`
- `www/assets/css/replay.css`
- `config/battle_history.example.php`
- этот отчёт, `docs/battle-replay-preview-api.md`, `docs/battle-stage4c-rollout.md`
- `docs/evidence/stage4c/README.md`, `summary.json`, `stage4c-evidence.zip` (реальные HTTP/UI тесты и снимки).

## Проверки

Изоляция: PHP 8.1.9; source-only копия без .git/production config/storage; MariaDB 10.8.4/InnoDB на 127.0.0.1:33389, проверенный datadir `C:/Users/mi/Documents/Berserk/stage4a-verification/data`. Основная схема `berserk_stage4b_run10`, synthetic IDs от 9000001. Пользователь без TRIGGER; preview дополнительно проверен пользователем с правами только SELECT. Production не подключался.

| Проверка | Результат |
|---|---|
| Финальный review Stage 4B и повтор интеграций перед коммитом | 20 PASS |
| Реальные HTTP проверки Stage 4C | 20 PASS: 16 основных, 3 дополнительных, 1 retry/CAS |
| UI Edge headless / Playwright | PASS; first/prev/next/last, Гидра, natural close, gaps, отсутствие JS ошибок |
| Инфраструктура хранения, checksum, rollback, конкуренция | 23 PASS, свежая `berserk_stage4a_run10` |
| Адресные R1–R3 | 33 PASS |
| Остальные regression suites | 20 PASS, без skip; вместе с R1–R3 — 21 suite |
| PHP lint изменённых/новых PHP | 10 файлов PASS |
| git diff --check | PASS |

HTTP включает настоящие index.php place_card/confirm_place, move, combat/выбор защитника/ACK, end_turn, resign и летальный terminal; automatic ACK и automatic end_turn через настоящий battle_sync с той же server factory. Проверены 401 legacy-only session, 403 outsider, 409 active match, доступ участника/admin, invalid step, tampered SHA-256, default-off и игнорирование HTTP opt-in.

Read-only подтверждён сравнением всех строк games/journal/пяти history tables до/после просмотра (включая JSON, версии, таймеры, pending), успешным API/UI под SELECT-only account и throw-on-load traps Engine/GameRepository/AutomaticBattleProgress/HttpBattleProgress исключительно в тестовой копии с восстановлением байтов. В рабочие исходники инструменты диагностики не попадали.

Начальные ошибки тестового стенда (недоступная клетка, дистанция удара, отсутствующие docs/cards и каталог вывода concurrency) исправлены в фикстурах/стенде, затем выполнены успешные повторные проверки. Старые результаты и данные сохранены; ошибки стенда не выдаются за PASS. Встроенный CUA не запустился из-за kernel-assets path; UI проверен локальным Edge/Playwright. Визуальная проверка обнаружила контраст CSS; исправлен только stylesheet реплея, скриншоты проверены повторно.

SHA-256 протестированных и доставленных файлов, неизменность Engine/резолверов/GameRepository/Db/CommandJournal/GameLog/миграции относительно Stage 4B и точные SQL доказательства находятся в evidence. Архив не содержит конфигураций подключения, БД, datadir, sessions или production файлов. Лабораторные серверы после проверки остановлены с сохранением данных.

## Риски и границы

- Production activation требует отдельного разрешения Lead и доверенного auth bridge. По умолчанию всё выключено.
- Кадры полностью раскрывают сохранённые скрытые зоны завершённого матча авторизованным участникам/admin; проверка доступа обязательна на каждом запросе, ответы private/no-store.
- Каталог для whitelisted тестов должен быть предварительно заморожен и соответствовать definitions/revision; универсальный production catalog loader здесь не реализован.
- Отключение записи разрешает обычную игру, но пропущенные команды не восстанавливаются. Не возобновлять запись этого матча после периода OFF: использовать новый заранее разрешённый тестовый матч. Завершение при OFF не создаст final, preview такого матча останется закрытым.
- Прямые внежурнальные saves battle/game_over whitelisted матча по-прежнему отклоняются Stage 4B guard; в частности смена playmat с новым ID не поддержана при включённой записи. Для обычных матчей путь прежний. Полный перевод всех вспомогательных writers не заявляется.
- Неопределённый исход COMMIT/processing подготовки не повторяется автоматически; 409 требует сверки журнала оператором, без повторного Engine исполнения.
- Минимальный reader проверяет все кадры за запрос; pagination/ограничение больших историй — следующий отдельный этап. SHA-256 защищает сохранённый envelope, не является подписью против администратора БД.
- Правила боя, Гидры, pending и порядок автоматических действий не менялись. Новый Recorder/анимация/публичные ссылки/импорт GameLog не разрабатывались.

API: [battle-replay-preview-api.md](battle-replay-preview-api.md). Порядок ограниченной выкладки: [battle-stage4c-rollout.md](battle-stage4c-rollout.md). Доказательства и воспроизведение: [evidence/stage4c/README.md](evidence/stage4c/README.md).
