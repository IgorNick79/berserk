# AJAX multi-target command stall — исправление

## Причины и изменения

- `www/assets/js/battle-sync.js`: FormData переносится через append, а прежние
  значения полей URL удаляются один раз. Удаляются и отсутствующие в FormData
  unchecked checkbox-поля, скалярные/индексированные варианты старого массива.
  Все выбранные ID сохраняются; hidden-поля и submitter остаются совместимыми.
- `www/index.php`: parser разрешает только плоский список `target_ids` для
  choose_multi_discharge, choose_multi_heal, choose_row_spell_targets,
  choose_anubisar_chain, choose_incarnation_wound, choose_intrigue_start.
  Элементы — положительные целочисленные ID в диапазоне PHP int, без дробей,
  знаков, пробелов, вложенных/ассоциативных массивов. Ограничения транспорта:
  64 элемента списка, 64 POST-поля, 16 KiB URL-encoded POST, 1024 байта на скаляр.
  Проверки игровых max_targets, candidates и уникальности остаются в Engine.
- `src/Core/Choice/MultiDischargeChoice.php`: cancel_pending объявлен поддерживаемой
  командой и направляется в существующий ActionResolver::cancelPending.
  Сохраняется проверка владельца источника; pending не удаляется клиентом.
- Новый helper sendPreRegistrationRejection возвращает коррелированный по
  command_id `request_status=rejected_before_registration` для определённого
  отказа текущего запроса. Перед этим он проверяет существующий journal record
  того же игрока/партии/ID. Если запись есть, возвращается её настоящий статус,
  а не признак незарегистрированного запроса. Ошибка чтения журнала не получает
  признак безопасного отказа.
- Клиент принимает этот признак только из HTTP 4xx для своего pending ID и
  выполняет текущий forced snapshot. До успешного snapshot новые команды
  заблокированы; ошибка snapshot повторяется существующим механизмом.
  После обновления показывается ошибка отклонённого запроса.

Это не новый journal status. `unknown`/`processing` не интерпретируются как
неудача или разрешение повторить команду. Схема журнала, его hash/ID validation,
expected_version, CAS, транзакция GameRepository::saveCommittedCommand и Engine
не менялись. Правила карт, величина урона и Turn Timer T1 не менялись.

## Проверки

Новые файлы:

- `tools/test_ajax_multi_target.php`: настоящий parser из index.php; допустимые
  и недопустимые списки, limits, обычные скалярные команды; Engine с 0/1/2/3/4
  целями, duplicate IDs, чужими/отсутствующими целями, отменой владельца и отказом
  противнику, round-trip состояния, multi_heal/row_spell. Отдельные fixture/inspect
  режимы доступны только в disposable checkout без .git, с явно указанным datadir
  и БД berserk_multitarget_test, после проверки её реального datadir и InnoDB.
- `tools/test_ajax_multi_target.js`: исполняет настоящий battle-sync.js в DOM/
  FormData adapter, без новых зависимостей. В обычном режиме проверяет сериализацию
  и recovery, включая старые query-параметры, early rejection, unknown/processing,
  invalid JSON, lost response, snapshot retry. В HTTP-режиме использует реальный
  endpoint и PHP fixture/inspect bridge.

Проверенная среда: PHP 8.1.9, Node 20.11.1, отдельный MariaDB 10.6.9 на
127.0.0.1:33916 с собственным новым datadir; PHP HTTP server на 127.0.0.1:8796.
БД berserk_multitarget_test, все таблицы InnoDB. Production DB не использовалась.
HTTP-приложение — копия исходников без .git и исходного storage; PHP sessions и
temp files размещались внутри тестового каталога. Ошибки PHP писались в лог,
не в JSON-ответ.

Результаты:

- PHP parser/Engine suite — PASS.
- JS transport/recovery suite — PASS.
- JS -> FormData adapter -> реальный HTTP parser -> Engine -> Journal/CAS ->
  snapshot — PASS. Проверены все выбранные IDs, HP, результаты у обоих игроков,
  повтор того же command_id без изменения сохранённого состояния, 409 при другом
  payload с тем же ID, stale expected_version, отмена до отправки выбора и после
  reload, multi_heal, row_spell, ранний 400 без записи, потерянный ответ после commit.
- Детерминированная fault-проба: повреждённый candidates в отдельном test fixture
  вызывает исключение Engine после регистрации. HTTP 500 оставляет processing;
  клиент продолжает recovery и не отправляет отмену. Ранний невалидный запрос
  с тем же ID возвращает processing и не маскирует его как незарегистрированный.
- Существующие `test_battle_command_endpoint.php`, `test_battle_sync_endpoint.php`,
  `test_command_journal_repository.php`, `test_repository_optimistic_locking.php`
  прошли с подключённой изолированной БД, без SKIP.
- `test_turn_timer.php`, `test_battle_cancel_action_ui.php`,
  `test_battle_stage1b_ui.php`, `test_row_spell_pending_dice.php`,
  `test_turn_instant_stack.php`, `test_combat_instant_phases.php`,
  `test_resign_pending.php` — PASS.
- Всего 12 релевантных PHP-скриптов и два режима нового JS suite прошли.
  PHP lint изменённых PHP-файлов и git diff --check — PASS.

Запуск без БД (PHP >=8.1, Node >=18, из корня приложения):

```text
php tools/test_ajax_multi_target.php
node tools/test_ajax_multi_target.js
```

Для HTTP-режима установить TEST_PHP_BINARY, MULTITARGET_TEST_URL и
MULTITARGET_TEST_DATADIR; запустить тот же JS-файл **в disposable копии**.
Нужен стандартный games schema и cards/elements catalogue для snapshot:
fixture ukids multi_fixture_1..6, имена Target 2..6 для соответствующих целей.
Остальные боевые данные fixture создаёт сам. Реальная VM-проба проверяет HTML
checkbox-формы из endpoint, но не заменяет отдельный визуальный браузерный smoke.

## Гарантии и оставшиеся риски

На реальной InnoDB подтверждено: один command ID с прежним payload и version
не применяет способность второй раз; другой payload с тем же ID не меняет
состояние. Дубликаты target IDs обрабатываются прежней нормализацией Engine:
урон наносится один раз на уникальную допустимую цель.

Исключения после регистрации и неопределённый commit намеренно не превращаются
в terminal по таймеру. Настоящий orphan processing по-прежнему требует установления
исхода операции; эта доработка не вводит автоматическое восстановление таких записей.
Если ранний ответ потерян либо его нельзя связать с валидным command_id, клиент
сохраняет осторожный recovery. Отсутствие записи само по себе не даёт разрешения
на повтор исполнения.

Уже зависший из-за старого transport бой можно продолжить после обновления клиента
и reload: сохранённый pending будет загружен, выбор/отмена выполнятся через Engine
и журнал. Для настоящего processing сначала нужна диагностика worker/транзакции.
Ни одна существующая партия в рамках исправления не редактировалась вручную.
