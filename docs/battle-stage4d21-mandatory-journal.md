# Stage 4D.2.1 — обязательный Live Battle Journal

Основа: `bc787d9ed474b0ba63b789da40fbd573dc1f6455` (Stage 4D.2). Решение Project Lead от 23.10.2026 заменяет прежний выборочный запуск. Старые отчёты и доказательства остаются историческими документами; их инструкции по включению больше не применимы.

## Архитектурное решение

Журнал — обязательная часть каждой новой партии. Нет серверных/клиентских флагов отключения, room/game allowlists, зависимости от LuckTest или аварийного обхода. `control_room_ids`, `recording_enabled`, `record_game_ids`, `journal_enabled`, `journal_game_ids` удалены из runtime и примера конфигурации; оставшиеся старые значения не влияют на поведение. Для журнала `battle_history.local.php` не требуется. Настройки Replay Preview остались отдельными и не включались.

Оба производственных пути создания (`MatchCreator::create`, `GameRepository::create`) устанавливают происхождение `journal_schema=1` и создают `battle_history_matches` в той же транзакции, что и игру. Это версия контракта сохранения, не переключатель игрока. Она позволяет отличить старую игру без истории от повреждения новой игры. Если у новой игры пропала metadata, действие отклоняется; автоматического перехода в режим без журнала нет. Неизвестная версия контракта также отклоняется.

Все команды новых партий выполняются под блокировкой через существующий Command Journal. Порядок: requireProcessing → game FOR UPDATE → Recorder → Engine/существующий processor → CAS состояния → append history → markCommitted → COMMIT. Ошибка обязательной записи откатывает состояние и историю. Replay `has_initial`/сертификация snapshot больше не являются условием допуска к записи текста. Алгоритм построения snapshots и Replay Preview не переписывались.

Подготовка до battle проходит через тот же commit pipeline, но не добавляет текстовые карточные события или ложные gaps: факт подтверждённой команды фиксируется batch. Расстановка по-прежнему отсутствует в тексте. `draft_sync` использует тот же locked pipeline; polling без прогресса не подтверждает игровое изменение и не создаёт history batch. Повторная проверка в ту же секунду идемпотентна, следующая может обработать наступивший timeout. Операционные rejected/no-progress строки Command Journal не являются пользовательскими событиями.

Внутренние косметические сохранения (коврик и звук подготовки) могут менять только соответствующее свойство участника под game lock. Они не вызывают Engine и не образуют боевого события. Обычный публичный `save()` не позволяет сохранять новую игровую позицию мимо Recorder/append, даже если ему вручную присоединить observer. Разрешение save для команды и создания принадлежит только приватному коду repository.

Чтение остаётся по сохранённым host/player projections и прежнему cursor. Подмена side запрещена, raw payload/скрытые поля не выдаются. Идентичность и membership проверяет действующая AuthSession/GameAccess. Прототипные journal invitations больше не являются альтернативой авторизации: старый web entry point возвращает 410, CLI выдачи приглашений завершает работу с объяснением. Существующие Account Auth, игровые роли и членство не заменялись.

После game_over оба участника продолжают читать всю свою сохранённую историю с начала. Старые скрытые проекции не переписываются и секреты из других проекций не добавляются.

## Старые партии

Игра без `journal_schema` и без history metadata остаётся исторической игрой без журнала. Нет фонового backfill, импорта JSONL, синтетического initial или переигрывания команд. Уже сохранённые строки старых историй не переписываются. Игра с существующей history metadata продолжает использовать обязательный transactional writer; вручную испорченный/непригодный lifecycle приводит к ошибке, а не к сохранению без истории.

## Изменённые файлы

- `src/Core/BattleHistory/HistoryRuntime.php`: удалены journal gates, доступность определяется существованием истории/происхождением игры; конфигурация относится только к preview.
- `src/Core/BattleHistory/ExperimentalHistory.php`: удалён прежний allowlist adapter.
- `src/Core/BattleHistory/HistoryRepository.php`: initialization больше не создаёт disabled histories; SQL/schema не менялись.
- `src/Core/BattleHistory/Recorder.php`: подготовка исключена из текстового покрытия; остальные боевые проверки сохранены.
- `src/Core/GameState.php`: сериализуемая версия происхождения журнала, отсутствует в старых DTO.
- `src/Core/GameRepository.php`: обязательный writer, атомарное создание, отсутствие late initialization, закрытие публичного save bypass и узкие внутренние cosmetic saves.
- `src/Lobby/MatchCreator.php`: безусловная initialization до первого записываемого события во всех режимах; quick-game использует тот же writer.
- `src/View/Screen/Battle/InfoPanel.php`, `www/index.php`, `www/battle-journal.php`: доступность, подготовка и обычная авторизация.
- `www/journal-access.php`, `tools/journal_access.php`: выведен из использования прототипный вход по приглашению.
- `config/battle_history.example.php`: только отдельные параметры Replay Preview.
- Тесты: `tools/test_live_journal_restoration_http.py`, `test_live_journal_restoration.php`, `test_live_battle_journal.php`, `test_lucktest.php`, `test_battle_recorder.php`, `test_lucktest_quick_sql.php`.
- Этот отчёт, инструкция проверки установки и новая директория evidence. Предыдущие отчёты/evidence сохранены.

## Проверки

Изолированная source-only копия без `.git`, реальных конфигураций и storage; собственный MariaDB 10.8.4 datadir, `--no-defaults`, loopback 33671 с проверкой identity до DDL; PHP 8.1.9 на 33672–33675. Четыре синтетических аккаунта, отдельные схемы. Все SQL создания fixtures выполнялись только там. Production не вызывался.

**88 HTTP/SQL проверок — PASS.** Обычное лобби без history config, создание для любых двух аккаунтов, все старые флаги false и пустые allowlists не выключают журнал, quick-game и non-quick LuckTest, grid/Solomon timeout, сохранение косметических настроек. Подготовка и расстановка не создают ложных text gaps.

Проверены реальные move/strike/ACK/dice/wounds/death/turn/terminal, outsider/side spoof/legacy token denial, разные скрытые проекции, неизменность старой строки после раскрытия/переименования карты. Пагинация, дубликат команды, stale CAS, отклонение, финальный пустой poll и восстановление полной истории дают ожидаемый результат. Искусственная ошибка INSERT history откатывает успешный ход и JSON/version; ошибка initialization откатывает новую игру/принятие комнаты; отсутствие обязательной metadata запрещает команду. Независимое `GameRepository::create` и запрет публичного `save` проверены настоящим SQL.

**Два реальных Edge/Playwright контекста — PASS.** После двух настоящих end_turn открытые панели обоих участников получают строки без reload и дубликатов. Завершённая история восстанавливается после reload/offline-online. API не подставлялся.

**13 PHP regression suites — PASS:** Stage3.1 R1–R3, Hydra, Ragnar, combat instant phases, WoundTransfer, nested ownership, pending wait timer, turn instant stack, turn timer, LuckTest Recorder atomicity, live journal contracts, gap causes, restoration. Парные Engine прогоны с observer и без него дают одинаковые Result.events и DTO. PHP lint всех 17 изменённых PHP файлов — PASS; Python AST — PASS. Старые два SQL harness с жёстко заданными собственными портами адаптированы к новому API и проверены lint; отдельно на их старых datadir/портах не запускались. Атомарность настоящей БД проверена новым HTTP/SQL harness.

Исходники Engine, StrikeResolver, InstantProcessor, TurnProcessor, Choice и ReplayStateBuilder не менялись. SQL/migrations не менялись. Процессы лаборатории остановлены; private configs удалены harness. Evidence содержит результаты, проекции, контрольные суммы runtime, regression stdout и screenshots, без БД, sessions, cookies и private configs.

## Ограничения

Полнота способностей остаётся задачей Stage4D.3; обязательность не делает непокрытые механики полностью описанными. Сохраняются diagnostic gaps/partial semantics и нейтральное «Карта» при отсутствии безопасного имени. Старые партии/бой493 не достраивались. Текущая реализация продолжает сохранять существующие технические snapshots; отсутствие сертификации не отменяет текст.

Для ручной приёмки на целевом хостинге остаются реальные колоды/скрытые способности, мобильный UI, повторный login, TLS/OPcache и права обоих DB principals. Нужны существующие пять history tables и Command Journal; без них новая партия/действие должны завершаться ошибкой. Рост history и операционного Command Journal требует обычного мониторинга и резервирования; обхода записи не предусмотрено.

Stage4D.3, production SQL/deploy, merge и push этого этапа не выполнялись.
