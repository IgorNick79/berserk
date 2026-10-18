# Сайкорон — реализация и проверка

Ветка `codex/psycoron` создана от `origin/main`, коммит `0057a52`.

## Аудит и повторное использование

- `CardStats`, `BattleHelper`, `ActionResolver::resolveDamage`: стандартное действие `magic`, таблица `strike.weak/medium/strong`, доступность, выбор цели, закрытие. Существующие примеры: Мастер топора, Циклоп, Аргвальд; список типов — `docs/05_cards.md`.
- `StrikeResolver::continueAfterStrikeResultAck`: после ACK результата, отложенных ран, after-инстантов и локальных продолжений вызывает `CoinAbilities::completeCombat`.
- `InstantProcessor::ackTurnInstantResult/continueAfterTurnStack`: сохраняет исходный результат в `coin_origin_strike`, продолжает его после after-стека. Ничего в этом lifecycle не переписано.
- `CoinAbilities`: существующая сериализуемая очередь `coin_post_combat_effects` → `coin_post_combat_queue` → history (Бон и Берроу, Дети топи). Разрешён повторный вызов существующего `defer`, добавлен один вид эффекта; очередь не открывает выбор при strike, ChoiceRegistry, legacy pending или стеке инстантов.
- `DamageResolver::applyDamage`: факт ран фиксируется после брони/защиты и изменения HP, до `resolveDeath`. Соседство — `ZoneManager::areAdjacentFieldCards` в этот момент; принадлежность цели не ограничена.
- `ChoiceRegistry`, `PanelSpec`, `CellMarkerPicker`, штатные ссылки поля и InfoPanel: существующий выбор клетки расширен дополнительным источником целей, отдельный JS не добавлялся.
- Телепортация: проверки занятости и блокирующих маркеров как у `teleport_target`; перемещение и эффекты — `MovementEffectResolver::applyForcedPositionChange`, как у Двери измерений и Демона жадности.
- Лимит: существующее сериализуемое хранилище `CardInstance::flags`, по тому же принципу, что `revive_used`, `steal_coin_used`, `dive_used`. Флаг `ranged_neighbor_teleport_used` не очищается сменой хода, ставится только после изменения координат. Новых полей CardInstance/GameState нет.

## Контекст и порядок

`shot`, `throw`, `discharge` используют `resolveDamage` и единый путь завершения результата через StrikeResolver. В `applyDamage` записывается только отложенный эффект, UI там не открывается. Несколько соседних целей дают одно предложение экземпляру в рамках одного strike; несколько экземпляров последовательно обслуживаются существующей очередью.

Для `multi_discharge` тот же strike теперь создаётся перед первым нанесением ран и заполняется результатами после цикла. Это сохраняет единый контекст и дедупликацию нескольких целей, не вводя второй очереди. Существующий механизм отложенной гибели при активном strike теперь применяется и к этому циклу; тест проверяет его основной путь и сохранение результатов.

В эффекте сохраняются ID, координаты и зона атакующего, ID/координаты цели, фактические раны и тип атаки. Гибель цели не отменяет предложение: соседство после смерти не пересчитывается. После результата и обязательных продолжений очередь повторно проверяет источник и доступные клетки.

Выбранная трактовка неоднозначного случая: атакующий должен оставаться живым на той же наземной клетке, где находился в момент ран. При перемещении, гибели, уходе с поля или исходной зоне flying предложение не создаётся/снимается без расходования. Новые координаты либо последняя клетка погибшего не подставляются.

## Pending и UI

1. `pending_ranged_neighbor_teleport.stage=offer`: «Телепортироваться / Отмена».
2. `stage=cell`: подсветка всех допустимых клеток через существующий picker. Фильтры стороны и ряда изначально не выбраны; выбор стороны включает первый доступный ряд.
3. Клик по клетке сразу выполняет `select`: сервер проверяет границы, соседство с атакующим, занятость, маркеры, доступность источника и лимит; затем перемещает карту и расходует использование. Отдельного подтверждения нет. Старые ссылки `confirm` поддержаны для открытых до обновления страниц.

Оба этапа сериализуются в обычном GameState. Владелец проверяется сервером; offer/cell имеют разные случайные choice_id. После успешного выполнения pending удаляется, повторные команды отклоняются. История исходной атаки хранится в штатной history и дополняется координатой телепортации. Отмена и недопустимая клетка не расходуют способность. Владение ожиданием внесено в BattleWaitResolver и его обязательный тест всех ChoiceHandler.

## Идентификатор и prop

`ukid`: `s1_153`, найден в локальном каталоге `tools/1`. В игровом коде этот ID не используется.

БД не изменялась. Read-only запрос текущего prop недоступен: локальное подключение mysqli завершилось ошибкой. Поэтому независимые свойства текущей записи БД проверить не удалось; перед заменой prop необходимо сохранить такие свойства, если они есть. Полная конфигурация для описанных в задании способностей находится в `psycoron.prop.json`:

```json
{
  "actions": [
    {
      "key": "magic",
      "name": "Магический удар",
      "type": "magic",
      "strike": {
        "weak": 2,
        "medium": 3,
        "strong": 4
      }
    }
  ],
  "on_ranged_neighbor_wounded": {
    "types": [
      "shot",
      "throw",
      "discharge"
    ],
    "actual_wounds": true,
    "exclude_self": true,
    "destination": "adjacent_attacker",
    "once_per_battle": true,
    "optional": true
  }
}
```

## Изменённые файлы

- `src/Core/RangedNeighborTeleport.php`
- `src/Core/Choice/RangedNeighborTeleportChoice.php`
- `src/Core/Choice/ChoiceRegistry.php`
- `src/Core/CoinAbilities.php`
- `src/Core/DamageResolver.php`
- `src/Core/ActionResolver.php`
- `src/Core/BattleWaitResolver.php`
- `src/View/Ui/CellMarkerPicker.php`
- `src/View/Screen/Battle/InfoPanel.php`
- `tools/test_psycoron.php`
- `tools/test_pending_wait_timer.php`
- `tools/test_cell_marker_picker_browser.js`
- `tools/test_cell_marker_picker_runner.cjs`
- `www/assets/js/cell-marker-picker.js`
- `src/View/Screen/BattleScreen.php`
- `docs/cards/psycoron.prop.json`
- `docs/cards/psycoron-report.md`

## Проверки

PHP 8.1, все перечисленные проверки завершились успешно:

- `test_psycoron.php`: 261 assertions; типы атак, реальные раны/броня/защита, летальная цель, снимок соседства, обе стороны, независимые успешные лимиты нескольких экземпляров, несколько целей, отмена и новое событие, устаревшие команды, чужой владелец, занятая клетка, границы/маркеры, перемещение/гибель атакующего, reload на этапах offer/cell, стандартный magic 2/3/4, реальные after-инстанты всех трёх атак, архив результата, рендер поля/InfoPanel.
- `test_bon_and_berrow.php`: 337 assertions.
- `test_swamp_children.php`: 399 assertions.
- `test_damage_lifecycle.php`.
- `test_combat_instant_phases.php`, `test_combat_instant_ui.php`.
- `test_nested_combat_ownership.php`: 10 реальных детерминированных путей.
- `test_adelaide_gray_elf_cards.php`, `test_movement_effects.php`.
- `test_pending_wait_timer.php`: все 57 зарегистрированных handlers.
- `test_friendly_fire.php`, `test_battle_redirect_ui.php`.
- `test_cell_marker_cancel.php`, `test_resign_pending.php`.
- `test_row_spell_pending_dice.php`, `test_turn_instant_stack.php`.
- PHP lint всех изменённых PHP-файлов; `git diff --check`.

Фильтр клеток дополнительно проверяется `test_cell_marker_picker_runner.cjs` в headless Edge: 28 сценариев для двух владельцев на ширинах 375/1600, включая полное/разреженное поле, устаревшие данные, наблюдателя, no-JS и замену DOM-фрагментов. Два сетевых клиента и реальная БД не использовались. Полная комбинация новых реакций со всеми вариантами перенаправления, вложенных смертей и инстантов не перебиралась; связанные регрессионные тесты пройдены. Для применения карты в рабочей игре потребуется отдельная запись предоставленного prop в БД, что намеренно не выполнялось по заданию.
