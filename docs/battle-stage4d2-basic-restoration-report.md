# Stage4D.2 — восстановление базового Live Battle Journal

Основа 094acde5d152bac6d85986e792b1115422378c8c, codex/lucktest-stage3-review. Реализация ограничена базовым журналом и серверным opt-in. Production, реальные аккаунты/данные и старая история не затрагивались. Stage4D.3 не выполнялся.

## Исправления

- HistoryRuntime: новый server-owned control_room_ids, пустой по умолчанию. MatchCreator регистрирует только новые выбранные матчи до первого structured event, используя существующую metadata table и транзакцию комнаты. Никакого угадывания AUTO_INCREMENT и client-owned enable. Добавление комнаты после старта без enrollment не включает Recorder.
- Account AuthContext/GameAccess, отдельные cookie/principal, membership и запрет side spoof сохранены. UI, explicit game allowlists, automatic commands и command recovery используют разрешённый runtime. Включение чтения после завершения допускается независимо от recording switch.
- JournalCardNames: данные name из существующих cards берутся при записи. Recorder замораживает безопасное name/owner в прежнем _journal envelope. Пустой внешний replay catalog больше не делает видимую карточную проекцию null. Отсутствующая/неоднозначная/пустая/невалидная display definition даёт «Карта», а не ukid или raw prop. Историческая видимость проверяется прежним known(); для скрытого противнику объекта projection=null.
- Snapshot builder/certification/границы, replay preview и игровой алгоритм не менялись. Наличие diagnostic certification gaps не удаляет текстовые события. API/cursor формат, выбор side columns и форматирование только сохранённой проекции прежние.
- Успешные place_card/unplace_card только в place и только live journal исключены из текстового покрытия: нет лишних rows или uncovered gaps. Остальные команды и replay-only режим не получили blanket exemption.
- Commit pipeline прежний: game CAS + history batch + Command Journal в общей транзакции; reader выдаёт committed prefix. Старый JSONL не используется и не импортируется. Его прежняя best-effort диагностика до COMMIT не объявляется подтверждением пользовательского действия.

## Изменённые файлы

Runtime: config/battle_history.example.php; src/Core/BattleHistory/HistoryRuntime.php, Recorder.php, новый JournalCardNames.php; src/Core/GameRepository.php; src/Lobby/MatchCreator.php; www/index.php; www/battle-journal.php.

Тесты: обновлены tools/test_live_battle_journal.php и test_journal_gap_causes.php под новые правила видимых missing definitions и preparation scope. Добавлены test_live_journal_restoration.php, test_live_journal_restoration_http.py, test_live_journal_restoration_browser.cjs, test_live_journal_restoration_live_browser.cjs. Старые отчёты и evidence сохранены.

## Изолированные проверки

Новая source-only копия без .git/production config/storage. PHP8.1.9, MariaDB10.8.4 --no-defaults, loopback33671, проверенный собственный datadir app/tmp/live-journal-restore-lab/data. HTTP33672–33675. Создавались отдельные синтетические схемы и fixture accounts; не использовались существующие данные пользователей. Внешний proxy отключён для localhost. Все процессы после проверки остановлены, private configs тестовый harness удаляет.

- **74 real HTTP/SQL проверки — PASS.** Реальная регистрация/login четырёх тестовых аккаунтов, лобби, нормальная подготовка/начало боя и quick-game. Оба участника читают; outsider и legacy principal/binding отказаны; чужая сторона и query spoof запрещены.
- Внешний catalog={} и preview_enabled=false весь прогон. Настоящие move/strike/dice/result/end-turn/terminal имеют содержательные строки с DB name. Отдельный lethal fixture проверяет фактические раны/смерть/естественный terminal через Engine; начальная позиция задаётся только внутри лабораторной схемы.
- Скрытое движение даёт разные исторические projections. После раскрытия и изменения cards.name прежняя строка не переписана. API выдаёт только entries(seq/kind/text), cursor, has_more, recording; raw payload/definitions не добавлены.
- Пагинация limit2, финальный пустой poll, тот же command ID, stale CAS, rejected команда, отказы доступа — без лишних confirmed events. Искусственная ошибка обязательного INSERT history откатывает фактический move, JSON/version и события. Ошибка связи комнаты откатывает также game/enrollment. Поздний opt-in для старого неинициализированного матча ничего не включает.
- Чтение не меняет game JSON/version и history metadata. Missing row справочника отдельно проверен реальным SELECT через JournalCardNames; generic visible fallback и hidden=null проверены контрактно.
- **Реальные Edge/Playwright, два одновременно авторизованных контекста, без подставного API.** После двух реальных end_turn обе панели выросли с1 до5 строк и совпали с полной выдачей API; reloads0, duplicates0, DOM panel сохранён. Отдельная проверка завершённого боя: reload восстанавливает ту же историю, offline/online reconnect без дубликатов. Скриншоты сохранены.
- **13 regression suites PASS:** R1–R3, Hydra, Ragnar, combat instant phases, WoundTransfer, nested combat ownership, pending wait timer, turn instant stack, turn timer, LuckTest Recorder atomicity, live journal contracts, gap causes, новый restoration contract.
- Парный настоящий Engine.apply с observer и без него: move/strike/ACK/end_turn дают полностью одинаковые Result.events и persisted DTO на каждом шаге, включая pending/continuation. Engine/StrikeResolver/InstantProcessor/TurnProcessor/все Choice и snapshot builder/reader исходники не менялись.

## Пример реальных проекций

Ниже сопоставлены entries двух реальных API-ответов для одной записи синтетического HTTP-боя, не production. host_entries/player_entries — подписи сравнения, не новые поля API. В этом прогоне активным был player. Владелец видит перемещение, host не получает эту строку; это не два разных события и не UI-фильтрация общего server payload:

```json
{
  "host_entries": [],
  "player_entries": [
    {
      "seq": 11,
      "kind": "event",
      "text": "Fixture 1 перемещается: 4:3 → 4:2."
    }
  ]
}
```

Полные две истории — docs/evidence/stage4d2-basic-restoration/player-projections.json. Там же HTTP, browser live/reload и unit результаты. Архив содержит скриншоты и regression outputs, без cookies, sessions, config/db.php/auth.local.php/battle_history.local.php, limiter secret, datadir и БД.

## Ограничения и ручная приёмка

Не добавлялись специальные атаки, общий semantic trace способностей, расстановка в текст или импорт JSONL. Сохраняются прежние partial/gap флаги сертификации реплея и ограничения специальных действий; нейтральное «Карта» означает отсутствующие безопасные display metadata, не восстановленную definition. Старые null-проекции и бой493 не достраиваются. Функции по умолчанию OFF, нужно выбрать новую комнату до подтверждения второго участника (см. setup).

После согласованной ручной установки проверить реальные card definitions/колоды, реальную скрытую информацию и инстанты, мобильный экран, hosting TLS/OPcache/SQL rights, повторный login и завершённый журнал. Прямые cosmetic saves записываемого battle по-прежнему ограничены старым guard (включая коврик); этот этап его не ослабляет. Отключённый посреди игры Recorder не следует возобновлять для старого матча после пропущенных команд.

Код не разворачивался и не публиковался автоматически. Готовность подтверждена для базового покрытия на изолированном стенде; массовое включение и Stage4D.3 не выполняются.
