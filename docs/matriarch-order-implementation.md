# «Приказ матриарха» — реализация

22 октября 2026. База: `98a7e86f3c65f2b7d9fa76c79eec0960a6b70fd2`.

## Результат и стадии

Реализованы tagged continuation одного боя, локальная политика StrikeResolver, сериализуемая очередь приказа, выбор цели/следующего участника через ChoiceRegistry и InfoPanel. Обычные вызовы без метки сохраняют старые правила. Расчёт ударов, ответов, урона и гибели остаётся штатным.

Этап 1 проверен до перехода к политике и очереди: простой бой, before/after, after с coin, дочерний бой с оставшимися элементами turn-стека и Дегаска, включая JSON roundtrip. Затем отдельно проверены политика и полная серия. Финальные тесты проверяют два уровня вложенных боёв в настоящем приказе.

## Состояние

`battle.matriarch_order` содержит `order_id`, `source_id`, `owner`, `target_id`, `initial_ids`, `completed_ids`, `excluded`, `current_id`, `step`, `stage`, `cost_paid`, `combat_ref`.

`pending_matriarch_order` обслуживает два выбора: `kind=target` до оплаты и `kind=participant` после применения. Оба имеют `choice_id`; отмена разрешена только до применения. Получатели фиксируются один раз. Стойкость проверяется через общий механизм значения защиты CardStats — собственное свойство, наследование строя и modifiers; остальные защиты не исключают получателей, согласно окончательным правилам. При каждом следующем объявлении проверяются жизнь, поле, владелец и соседство, без повторной проверки Стойкости. Смерть источника не отменяет уже применённую серию.

Один `coins` и закрытие источника оплачиваются после подтверждения допустимой цели. При пустом списке оплаты нет. Сериализация использует GameState. Новых таблиц или изменений БД нет.

## Политика боя

У конкретного strike стоит `matriarch_order=true`; передача внешнего возврата в расширенный forced-вход включает эту политику. `CardStats::combatClosed` считает двух участников открытыми только в этом кадре, включая исходный расчёт, пересчёт таблицы, closed-dependent бонусы и Ragnar. Живые карты физически не открываются.

Нет обычного increment атак, first target, изменения attack_series_hit_ids, закрытия по лимиту и запуска/завершения обычной серии. Лечение `on_strike_self_heal` вынесено из блока оплаты и сохраняется. Собственные стоимости инстантов и Ragnar остаются. Combat-инстант без tap может использовать эффективную открытость; отдельная tap-стоимость требует реального открытого состояния. Допуск UI и сервера согласован, использование инстанта учитывается штатным счётчиком.

Назначение другого защитника и redirect запрещены сервером; соответствующие возможности не предлагаются UI. Пророчество, броски, before, dice/main, ответ, защиты, сильный удар, Дегаска и последствия проходят штатные обработчики. Блокировка пророчеством не расходует обычную атаку участника.

## Возврат после боя

`CombatContinuation` включается только явно помеченным корнем. Хранит `return_to`, статусы кадров, монотонный локальный номер кадра и suspended turn-стеки. Каждый кадр получает `continuation_frame`.

- `begin`: регистрирует кадр, без наследования политики Матриарха дочернему бою.
- `suspend`: отмечает родителя при before/after turn-окне; origin сохраняется в continuation существующего стека, а slot strike освобождается для дочернего боя.
- `resume`: возвращает сохранённый origin. After возобновляется с `instant_phase=after`, поэтому окно не открывается повторно.
- `localDone`: положительно отмечает терминальную ветку; обнуление strike само по себе не служит критерием окончания.
- `settle`: вызывается после CoinAbilities::advance в Engine. Ждёт положительно завершённых кадров и отсутствия обязательных pending, turn stack/result, coin queue/origin. Сохранённые Degasca/death продолжения входят в pending-барьер. При возврате в turn-стек продолжает только оставшиеся items и сохранённую summary. Это курсор существующего исполнителя, а не второй движок.

Для tagged after используется один carrier `combat_origin_strike`, вместо второго параллельного `coin_origin_strike`. Coin-эффекты переносятся штатным completeCombat и разрешаются прежним CoinAbilities. Механизмы Дегаски не переписаны: полные копии strike сохраняют frame metadata. Auto и push в tagged бою возвращаются в полный after lifecycle; execute и death pending удерживают завершение. Следующий участник запускается только по completion `(order_id, step)`.

## Изменённые файлы

Новые runtime-файлы:

- `src/Core/CombatContinuation.php`
- `src/Core/MatriarchOrderProcessor.php`
- `src/Core/Choice/MatriarchOrderChoice.php`

Изменённые runtime-файлы:

- `src/Core/Engine.php` — settle, continuation auto, локальный запрет interleaving, redirect guard.
- `src/Core/StrikeResolver.php` — opt-in вход, терминальные сигналы, ресурсная политика, послеударные ветки.
- `src/Core/InstantProcessor.php` — сохранение origin, cursor turn-стека, реальная стоимость combat-инстанта, допуск без redirect.
- `src/Core/CardStats.php` — effective state и общее извлечение значения защиты для однократной stamina-проверки.
- `src/Core/ActionResolver.php` — dispatch воздействия до оплаты.
- `src/Core/BattleHelper.php` — подбор целей действия без фильтров обычной атаки.
- `src/Core/BattleWaitResolver.php` — владелец нового pending.
- `src/Core/Choice/ChoiceRegistry.php` — регистрация выбора.
- `src/View/Screen/BattleScreen.php` — недоступность кнопки при отсутствии ресурса/целей.

Тесты: новые `tools/test_combat_continuation.php`, `test_matriarch_combat.php`, `test_matriarch_order.php`, `test_matriarch_boundaries.php`; расширен `tools/test_pending_wait_timer.php` для нового handler. Конфигурация: `docs/cards/matriarch_order.prop.json`. Этот отчёт: `docs/matriarch-order-implementation.md`.

## Проверки

PHP CLI: `C:/OSPanel/modules/php/PHP_8.1/php.exe` (8.1.9). Системный `php` указывает на 7.3 и для этого проекта не используется.

Команда основного набора:

```powershell
& 'C:/OSPanel/modules/php/PHP_8.1/php.exe' tools/test_matriarch_boundaries.php
```

Он включает три предыдущих этапных теста и проверяет: стоимость/отмену/replay, stamina latch, grave, ограничения обычного нападения, собственное участие Матриарха, смерть источника, выбор порядка, позднего участника, обычную атаку после серии, Ragnar, before/after/coin/Degasca, child/grandchild, remaining items, auto/push/execute, no_close/tap, redirect, смерть следующего реальным инстантом, death pending цели, reload и рендер PanelSpec. Проверены отдельные завершённые combat actions штатного live history recorder.

Дополнительно успешно выполнены:

```text
test_bon_and_berrow.php              337 assertions passed
test_degasca.php                     passed
test_combat_instant_phases.php       passed
test_combat_instant_ui.php           passed
test_nested_combat_ownership.php     10 deterministic real combat paths passed
test_forced_strike_targets.php       passed
test_turn_instant_stack.php          passed
test_ragnar.php                      passed
test_soul_eater_shaman.php           passed
test_strangulation.php               passed
test_pending_wait_timer.php          all registered handlers covered
test_turn_timer.php                  passed
test_turn_timer_sync.php             passed
test_informational_wait.php          passed
test_battle_helper_movement.php      passed
test_movement_effects.php            passed
test_forced_directional_movement.php passed
```

Всего 18 запускаемых suites в итоговом наборе; основной suite также исполняет три этапных файла. Проверка синтаксиса применяется ко всем изменённым PHP-файлам.

## Prop для ручного внесения

Полный prop, реализованный этим изменением, находится в `docs/cards/matriarch_order.prop.json`:

```json
{
  "zom": true,
  "start": {"side": 2, "type": "get_coins", "coins": 1},
  "actions": [{
    "key": "matriarch_order",
    "name": "Приказ матриарха",
    "type": "impact",
    "effect": "matriarch_order",
    "coins": 1,
    "self": true
  }]
}
```

`self` делает кнопку непосредственным запуском собственного pending, а не ударом по самой Матриарх. `effect=matriarch_order` — реализованный dispatch существующего типа impact, не новая категория атаки. `tap:true`, `counter_cost` и несуществующие параметры серии не требуются. Стартовая монета выдаётся только стороне 2 штатным get_coins. ukid в коде не используется; значение из БД не устанавливалось.

## Практические ограничения проверки

- БД не открывалась и не изменялась. Интеграционные DB/HTTP-тесты, которые создают партии, таблицы или меняют каталог, не запускались. Новая команда использует обычный transport → command journal → expected_version → locking/CAS → Engine; альтернативного пути записи не добавлено. Дубли выбора дополнительно проверены на уровне Engine через choice_id/stage/step.
- Проверен серверный рендер InfoPanel/PanelSpec; полный браузерный сетевой E2E на живой партии не выполнялся.
- История получает отдельные combat actions, но существующий консервативный certified replay может помечать сложные способности/вложенные механики как event-only. Новая система реплеев не создавалась.
- Тесты проверяют архитектурные границы и два уровня вложенности, а не все комбинации карт или неограниченную глубину. Формулы и существующие handlers не дублировались.
- Commit/push не выполнялись; prop требует отдельного ручного внесения в каталог.
