# 05. Схема карт

Справочник по тому, что понимает код. Обновляется при добавлении нового
примитива, а не при добавлении карты.

**Как пользоваться:**
- Ищешь ключ из `prop` — попадаешь на строку, видишь формат, смысл, где читается, пример карты.
- Добавляешь карту — сначала ищешь нужный примитив здесь. Если не нашёл — значит новый примитив, сначала код, потом этот файл, потом карта.
- Если в данных появился ключ, которого тут нет — либо опечатка, либо забыл реализовать.

---

## 1. Зоны карты

Определены в `CardInstance::ZONE_*`.

| Константа | Строка | Где участвует |
|---|---|---|
| `ZONE_DECK` | `deck` | пророчество, добор, порядок через `order` |
| `ZONE_SIDEBOARD` | `sideboard` | запас, обмен до боя |
| `ZONE_HAND` | `hand` | (не используется в текущей логике) |
| `ZONE_SQUAD` | `squad` | сформированный отряд |
| `ZONE_FIELD` | `field` | земля: движение, удары, действия |
| `ZONE_DISCARD` | `discard` | сброс |
| `ZONE_GRAVEYARD` | `graveyard` | кладбище: инкарнация, revive |
| `ZONE_EXILE` | `exile` | изгнание (Сшиватель плоти) |
| `ZONE_FLYING` | `flying` | летуны на поле |

Только `field` и `flying` — активные в бою. Остальные — хранилища.

---

## 2. actions[].type — что карта делает по клику

Список действий карты лежит в `card->prop['actions']`. Каждое — объект с полем `type`.
Обработчик — `ActionResolver::handle` через `match` по `type`.

### 2.1. Атакующие (по врагу)

| type | Что | Доп. поля | Примеры карт |
|---|---|---|---|
| `strike` | простой удар (обрабатывается не тут, а `StrikeResolver`) | — | все |
| `uchr` | удар через одну клетку по прямой | `value`, `strike`, `apply_to_mid` | Кшар, Змееглав, Гуль, Ледовый охотник, Грызь, Пещерник, Кобольд, Ассасин, Берсерк |
| `shot` | выстрел на дальность | `value` или `strike`, `range`, `marker`, `strike_effects`, `coin_bonus`, `near_shot`, `close_on_ranged_hits` | Гиррит, Арбалетчик, Мира, Ижор, Эриала, Бегущая, Гаррид, Серый альв, Дозор |
| `throw` | метание на дальность | `value` или `strike`, `range`, `value: self_wounds`, `max_value`, `coin_bonus` | Мразень, Тови, Троллок, Рэккен, Хозяин склепа, Варлок |
| `discharge` | разряд | `value` или `strike`, `range`, `flying_bonus`, `all_rows_bonus`, `strike_effects` | Ост, Пустотник, Повелитель молний, Суккуб, Аргвальд, Сеятель, Айрин, Оури, Криомант |
| `magic` | магический удар | `value` или `strike`, `range`, `coin_bonus`, `reset_coins_after` | Мастер топора, Циклоп, Аргвальд, Сайкорон |
| `cast` | заклинание | `target: ally_other`, `coins`, `mode: shield` | Пустотник (Щит света) |

**Особые поля:**
- `key` — если задан, используется в UI для `mode: action:<key>`.
- `condition: {type: "ally_price_near", min: 7}` — Оури, требует союзника дороже N рядом.
- `no_close: true` — не закрывается после действия (Оури).

### 2.2. Поддержка / статусы

| type | Что | Поля | Карты |
|---|---|---|---|
| `heal` | лечение | `value` (число или `"full"`), `heal_poison`, `filter: own_forest`, `max_targets` | Леший, Друид, Фея леса, Ледовый страж, Пещерник, Оури, Хронос |
| `give_coin` | отдать монету | `coins`, `target: ally_other` | Хронос |
| `grant_modifier` | выдать модификатор | `grant_modifier: {stat, value, expire}` | Молотобоец, Грызь |
| `grant_prop` | выдать свойство | `prop` | Ойуун |
| `poison_target` | наложить яд | `value` | Ундина |
| `damage_poisoned` | урон по отравленным | `value`, `filter: enemy` | Ундина |
| `sand_claws` | песчаные когти | `value` | Хозяйка прайда |
| `blood_tap` | кровавый разряд | `base_hp`, `max_extra` | Ведьма слуа |
| `dissonance` | диссонанс | `value`, `near` | Герольд мрака |
| `self_wound_strike` | таран | `coins`, `target: opposite` | Центурион |
| `revive` | возродить | `coins`, `once_per_battle` | Знахарь племени |
| `wound_transfer` | перераспределить раны | `donor_filter`, `target_filter`, `max_transfer`, `coins`, `on_finish` | Волхв |
| `steal_strike` | украсть удар | `coins`, `once_per_battle` | Лесной разбойник |
| `steal_coin` | украсть монету | `value`, `once_per_battle` | Махинатор |
| `dive` | пикирование | `coins`, `strike`, `once_per_battle` | Пустынный кондор |
| `become_fly` | получить полёт | — | Владыка небес |
| `place_cell_marker` | маркер на клетку | `marker`, `duration` | Крондак |
| `jump` | прыжок (в `MovementResolver`) | `range` | Грызь, Болотник, Ассасин |
| `multi_discharge` | разряд по нескольким | `value`, `max_targets`, `filter` | Аколит Дзара |
| `impact` | воздействие | `value`, `poison`, `target: all_near`, `self_destroy`, `transfer_wounds` | Болотник, Осклизг, Рэккен |
| `execute` | добивание | `value`, `near` | Гуль, Ассасин, Кровяница |
| `grezy_prophecy` | грезы | `count` | Грезы Архааля |

### 2.3. Что где обрабатывается

```
ActionResolver::handle()
  ├─ place_cell_marker → startPlaceCellMarker
  ├─ become_fly → resolveBecomeFly
  ├─ grant_prop → resolveGrantProp
  ├─ steal_coin → resolveStealCoin
  ├─ give_coin → resolveGiveCoin
  ├─ poison_target → resolvePoisonTarget
  ├─ damage_poisoned → resolveDamagePoisoned
  ├─ dive → startDive
  ├─ wound_transfer → WoundTransferProcessor::start
  ├─ grezy_prophecy → startGrezyProphecy
  ├─ dissonance → resolveDissonance
  ├─ heal (с max_targets) → startMultiHeal
  ├─ multi_discharge → startMultiDischarge
  ├─ steal_strike → resolveStealStrike
  ├─ sand_claws → resolveSandClaws
  ├─ blood_tap → startBloodTap
  ├─ revive → startRevive
  ├─ self_wound_strike → pending_self_wound
  ├─ impact (transfer_wounds) → startTransfer
  ├─ heal (одиночный) → resolveHeal
  ├─ impact (all_near) → resolveImpact
  ├─ execute → resolveExecute
  ├─ grant_modifier → resolveGrantModifier
  └─ всё остальное → resolveDamage (shot/throw/discharge/magic/cast/tap)
```

---

## 3. prop-ключи

### 3.1. Боевые характеристики

| Ключ | Формат | Смысл | Где читается | Примеры |
|---|---|---|---|---|
| `ova` | число или объект | +N к броску атакующего | `CardStats::getOva` | Кшар, Бронтобей, Скелос, Ловец душ |
| `ovz` | число или объект | +N к броску защитника | `CardStats::getOvz` | Змееглав, Скелос, Витязь |
| `armor` | `{value, line, line_elite}` | броня | `CardStats::computeArmor` | Минотавр, Гном-поджигатель, Пеший латник |
| `clumsy` | число | штраф к кубику | `CardStats::getClumsyPenalty` | Каменный голем, Волот, Поганище |
| `has_line` | true | может стоять в строю | `CardStats::hasLine` | Рубаки Холверта, Пеший латник, Санкторум |
| `has_armor` | true | отображать броню в UI | `CardStats::getActivePropBadges` | Бон и Берроу, Минотавр |

**Формат `ova`/`ovz`:**
```json
"ova": 1                                        // просто +1
"ova": {"value": 2}                             // +2
"ova": {"value": 1, "element": "swamps"}        // +1 по болотным
"ova": {"check": true, "types": [...]}          // условный
"ova": {"value": 1, "defender": true}           // Клаэр — только защитником
```

### 3.2. Защиты (ZO*)

Список защит (`zoo`, `zov`, `zot`, `zoal`, `zom`, `zoz`, `zor`, `zoa`, `zoan`, `zoda`).
Проверяются в `CardStats::hasDefense` по типу действия.

| Ключ | От чего |
|---|---|
| `zoo` | от отравления (и вообще всех магических) |
| `zov` | от `shot` (выстрел) |
| `zot` | от `throw` (метание) |
| `zoal` | от атак летающих |
| `zom` | от `magic` |
| `zoz` | от `discharge` |
| `zor` | от атак на дальние (уточнить) |
| `zoa` | от всех атак |
| `zoan` | от нелетающих |
| `zoda` | от дальних |

Формат: `true` или `{"line": true, "condition": "..."}` — если строй обязателен.

Примеры:
- Степной волколак: `"zoo": true`
- Пеший латник: `"zov": true`
- Скелос: `"zoal": {"line": true}` — только в строю
- Мантикора: `"zoz": true`

### 3.3. Игнорирование защит

| Ключ | Формат | Смысл | Примеры |
|---|---|---|---|
| `ignore` | `["zoal", "zoa"]` | игнорирует конкретные защиты цели | Мантикора, Владыка небес |

Читается в `CardStats::isDefenseIgnored` при проверке `hasDefense`.

### 3.4. Безответный удар

| Ключ | Формат | Смысл | Примеры |
|---|---|---|---|
| `direct` | `true` | любой удар безответен | Мразень, Гоблин, Зомби, Тич |
| `direct` | `{check, element}` | безответен по элементу | Орк (по горным) |
| `direct` | `{check, types}` | безответен против magic/cast/discharge | Мантикора, Дракс |
| `direct` | `{check, closed}` | безответен по закрытым | Гном-басаарг |
| `direct` | `{check, condition}` | условно | Страж чертогов, Головорез |
| `unanswer` | `true` | то же самое, но другая семантика | Вампир |
| `unanswer` | `{check, condition: "isolated_horizontally"}` | только если цель изолирована | Римаанды |
| `unanswer` | `{check, target_marker: "prey"}` | по цели с маркером | Владыка небес |

Проверяется в `CardStats::isDirectStrike` и `CardStats::hasUnanswer`.

### 3.5. Движение и позиция

| Ключ | Формат | Смысл | Примеры |
|---|---|---|---|
| `can_move_diagonal` | `true` | ходит по диагонали | Лесной разбойник |
| `row_extreme` | `true` | ход/удар между крайними клетками ряда | Возница |
| `forced_strike` | `true` | обязан бить закрытое рядом | Гном-басаарг |
| `movement_direction_bonus` | объект | бонус за движение | Риала |
| `force_opponent_directional_move` | объект | враг обязан двигаться | Тови |
| `open_on_opponent_turn` | `true` | открывается перед ходом врага | Ловец удачи |
| `no_close_after_attack` | `true` | не закрывается после удара | Барака |
| `strike_range_line` | число | удар на N в строю | Страж чертогов |
| `can_attack_flying` | `{condition}` | может бить летающих | Скелос |

### 3.6. Множественные атаки

| Ключ | Формат | Смысл | Примеры |
|---|---|---|---|
| `attacks_per_turn` | `2` | атакует N раз за ход | Минотавр, Берсерк |
| `strike_targets_unique` | `true` | каждая атака по новой цели | Берсерк |
| `strike.opposite` | `true` | удар только напротив | Циклоп |
| `strike.redirect` | `true` | враг может перенаправить удар | Волот |

### 3.7. HP и ресурсы

| Ключ | Формат | Смысл | Примеры |
|---|---|---|---|
| `hp_max_override` | число | перезаписать hpMax | Вампир, Ведьма слуа, Медуза |
| `vampire` | `true` | вампиризм | Вампир, Медуза, Рогатый демон, Вурдалак |
| `vampire_offset` | число | сдвиг лечения (обычно -1) | Вампир, Ведьма, Вурдалак |
| `deadeat` | `true` | трупоедство | Гуль, Корпит, Жжраг, Медуза |
| `deadeat_hp_bonus` | число | +N макс HP после трупоедства | Жжраг |
| `regeneration` | число или `{value}` | регенерация | Степной волколак, Тролль, Гобрах |
| `block_strike_answer` | `true` | не получает ответку | Килсус |
| `block_weak_strike` | `true` | блокирует слабый удар | Санкторум |

### 3.8. Монеты

| Ключ | Формат | Смысл | Примеры |
|---|---|---|---|
| `coins` | `{max_value, <type>: {spend, value, max_value, min_value, mode}}` | конфиг монет | Арбалетчик, Пустотник, Аргвальд |
| `save_coins` | `true` | может копить монеты | Кшар, Арбалетчик, Камнедрев |
| `coins_receive_deny` | `true` | не получает монеты от других | Пустотник |
| `coin_strike_bonus` | `true` | +X к удару, X = монеты | Камнедрев |
| `lose_coins_on_move` | `true` | теряет монеты при движении | Камнедрев |
| `on_discharge_self` | `{coins}` | получает монету при разряде по себе | Аргвальд |
| `coin_bonus` (в action) | число | бонус за каждую монету | Аргвальд, Хозяин склепа |
| `reset_coins_after` (в action) | `true` | теряет монеты после действия | Аргвальд |

**Формат `coins`:**
```json
"coins": {
  "max_value": 4,
  "shot": {"spend": "all", "value": 2},        // тратит все, +2 за монету
  "magic": {"spend": "choice", "value": 1, "max_value": 5},  // выбор количества
  "cast": {"mode": "shield", "spend": "choice", "value": 1, "min_value": 1, "max_value": 3}
}
```

### 3.9. Пророчество

| Ключ | Формат | Смысл | Примеры |
|---|---|---|---|
| `prophecy` | число | пророчество N в начале хода | Искатель тайн |
| `prophecy_transform` | `true` | может трансформироваться на элите | Искатель тайн |
| `prophecy_block` | `{chance: "odd", once_per_turn}` | блок атаки по нечётной цене | Дочь перламутра |
| `prophecy_reorder` | `true` | может переставить топдек | Махинатор |
| `prophecy_summon` | `true` | может вызвать из пророчества | Отряд карателей |
| `prophecy_charge` | `{cap}` | накапливает +удар за пророчество | Исхарская ненасыть |

### 3.10. Инкарнация

| Ключ | Формат | Смысл | Примеры |
|---|---|---|---|
| `incarnation` | число | N ходов до инкарнации | Талион |
| `incarnation` | `{type: "fly", turns: N}` | инкарнирует в летуна | Вестник мора |
| `incarnation` | `{open: true, turns: N, abilities: {ova: 2}}` | открыто с бафами | Моровой всадник |
| `on_strong_strike` | `{type: "incarnation_token_graveyard"}` | жетон на кладбище от сильного удара | Талион |

### 3.11. Активируемые триггеры

| Ключ | Формат | Когда срабатывает | Примеры |
|---|---|---|---|
| `start` | `{side, type: "get_coins", coins}` | начало боя | Волхв, Болотник, Осклизг |
| `turn_start` | массив | начало своего хода | Смотритель стойла (whip), Мастер топора (coins), Гном-поджигатель (damage) |
| `turn_end` | массив | конец своего хода | Алвалинд (discharge) |
| `opponent_turn_start` | массив | начало хода врага | Бул'Багур |
| `opponent_turn_end` | массив | конец хода врага | (примеров нет) |
| `pre_turn_start` | `{options, expire}` | до начала хода — выбор | Оборотень |
| `on_move` | массив | после движения | Смотритель стойла, Килсус, Бронтобей, Бегущая |
| `on_move_half` | массив | смена половины поля | Кабаний наездник |
| `on_death` | массив | при гибели | Степной волколак, Ном |
| `on_any_death` | объект | при чужой/любой смерти | Сейатель (яд), Хозяин склепа (монета) |
| `on_hit_marker` | `{type}` | при попадании удара | Владыка небес |
| `on_hit_gain` | массив модификаторов | при попадании по себе | Древний огр, Гоблин |
| `on_become_defender` | массив модификаторов | при защите | Клаэр, Бьерн |
| `on_successful_strike` | `{key, type, uses_per_turn}` | при успешном ударе | Рубаки Холверта |
| `on_successful_hit` | `{type: "optional_heal", value_from}` | при успешном попадании | Кобольд |
| `line_death_next_strike_bonus` | `{value}` | гибель состроевика | Тан Ханеранга |
| `valhalla` | массив эффектов | Йордлинги | Знахарь, Ледовый охотник, Оборотень, Костедробитель |
| `strike_prophecy` | `{count, all_elite, all_ordinary}` | при ударе | Двухголовый тролль |

**Формат `on_any_death`:**
```json
"on_any_death": {
  "side": "enemy" | "any",           // чья смерть триггерит (по умолчанию enemy)
  "cause": "poison" | "any",          // причина смерти
  "once_per_turn": true,
  "effect": "get_coin",
  "value": 1,
  "filter": "any" | "not_flying"
}
```

### 3.12. Модификаторы урона / брони

| Ключ | Формат | Смысл | Примеры |
|---|---|---|---|
| `ability` | объект или массив | +N к удару при условиях | Змееглав, Орк, Хранитель гор, Фагор |
| `damage_reduction` | массив | снижение урона | Скелос, Гобрах, Щитоносец, Мира |
| `strike_reduction` | `{medium: "weak", strong: "weak"}` | сведение урона | Степной волколак |
| `column_range_aura` | `{types, value, target}` | +дальность союзникам в столбце | Паладин Алламора |
| `aura_plains_ranged` | `{types, value}` | -1 ranged соседям-степнякам | Щитоносец |

**Формат `ability`:**
```json
"ability": {"value": 1, "element": "mountains"}                    // +1 по горным
"ability": {"only": ["strike"], "value": 1, "element": "dark"}     // +1 к удару по тёмным
"ability": {"value": 1, "position": "opposite"}                    // +1 по стоящим напротив
"ability": {"value": 1, "position": "row_mirror"}                  // Эриала
"ability": {"value": 1, "position": "enemy_second_row"}            // Ижор
"ability": {"value": 1, "defender": true}                          // +1 когда защитник
"ability": {"value": 1, "target_marker": "prey"}                   // +1 по цели с маркером
"ability": {"value": 1, "per_ally": {"element": "dark"}, "max": 2} // +1 за тёмного союзника, до +2
"ability": [                                                       // несколько условий
  {"line": true, "value": 1},
  {"closed": true, "value": 1}
]
```

### 3.13. Перехваты

| Ключ | Формат | Смысл | Примеры |
|---|---|---|---|
| `air_intercept` | `true` | перехват атак летающих | Паук-пересмешник |
| `ranged_intercept` | `true` \| массив типов | перехват дальних | Резчик идолов, Аргвальд, Мерцающий змей |
| `ranged_intercept_all` | `true` | **НЕ ЧИТАЕТСЯ КОДОМ** (проверить, удалить) | Мерцающий змей (устаревшее) |

Срабатывает: если у обороняющейся стороны есть перехватчик, все дальние/летающие обязаны бить по нему.

### 3.14. Прочее

| Ключ | Формат | Смысл | Примеры |
|---|---|---|---|
| `prophecy_summon` | `true` | карта может быть вызвана из пророчества | Отряд карателей |
| `copy_target_strike` | `true` | копирует удар цели | Метаморф |
| `fly_defend` | `true` | может защищать нелетающих | Ртунх |
| `open_on_opponent_turn` | `true` | открывается перед ходом врага | Ловец удачи |
| `deal` | объект | механики набора | Мародер, Лазутчица, Линнет и др. |

---

## 4. instants — инстанты

Массив в `prop['instants']`. Открывается через кнопку или в окне боя.

```json
{
  "key": "flash",
  "name": "Отвлекающая вспышка",
  "trigger": "combat",
  "phase": "setter",
  "target": "enemy" | "ally" | "self",
  "coins": 1,
  "uses_per_turn": 1,
  "effect": {
    "type": "damage_cap",
    "value": 1,
    "condition": "target_closed"
  }
}
```

### 4.1. trigger

- `combat` — окно боя; для него нужен `phase`
- `turn` — обычное окно инстантов

Combat-фазы исполняются в порядке:

`redirect` → `dice` → `power` → `value` → `setter` → `wounds`.

Внутри одной фазы действует LIFO по порядку заказа.

### 4.2. effect.type

| type | Поля | Смысл | Примеры |
|---|---|---|---|
| `open` | `damage`, `condition` | открыть карту + урон | Ойуун |
| `close_target` | `condition` | закрыть карту | Ледовый страж |
| `damage` | `value` | урон | Взрывная Мэри, Повелитель мёртвых |
| `heal` | `value` | лечение | (примеров нет) |
| `heal_turn_wounds` | — | лечение ран этого хода | Хронос |
| `marker` | `marker` | маркер | — |
| `strike_level` | `mode: set \| reduce_one`, `value` | изменить уровень удара | Ост (set strong), Лунная баньши (reduce_one) |
| `damage_cap` | `value`, `self_wound`, `except_element` | ограничить урон | Глорм |
| `damage_on_dice` | `value`, `damage` | урон при кубике | Взрывная Мэри |
| `dice_choice` | — | ±1 или reroll | Ловец удачи |
| `redistribute_wounds` | — | перераспределить раны | Отшельница |

### 4.3. effect.condition

- `target_closed` — цель закрыта (Ойуун)
- `target_not_moved` — цель не двигалась в этот ход (Ледовый страж)

---

## 5. Маркеры

### 5.1. Маркеры на карте (`card->markers`)

| Тип | Поля | Смысл | Источник |
|---|---|---|---|
| `poison` | `value`, `source`, `timing: permanent` | яд | многие |
| `hunt` | `bonus`, `expire` | охоты | Гиррит |
| `rooted` | `sources` | обездвижен | Василиск |
| `sand_claws` | `value`, `source`, `expire` | +кубик по цели | Хозяйка прайда |
| `stun` | `expire`, `timing`, `skip_first_tick` | оглушение | Борг, Уриил, Медуза |
| `incarnation` | `value`, `threshold`, `open` | жетоны | инкарнация |
| `prey` | — | добыча | Владыка небес |

### 5.2. Маркеры клеток (`state->cell_markers`)

| Тип | Поля | Смысл | Источник |
|---|---|---|---|
| `bonfire` | `expire`, `source` | костёр (занятая клетка) | Крондак |
| `дрожь` | `expire` | дрожь | Скелетный червь |
| `gates` (врата) | `expire` | врата | Демон жадности |

Формат ключа: `"<row>_<col>"`.

---

## 6. pending_* — окна ожидания

Все в `state->battle['pending_<name>']`. Отменяются `cancel_pending` (если разрешено).

| Ключ | Владелец | Обработчик | Отмена |
|---|---|---|---|
| `pending_coin_spend` | attacker | `chooseCoinSpend` | да |
| `pending_self_wound` | attacker | `chooseSelfWound` | да (возврат монет) |
| `pending_multi_heal` | attacker | `chooseMultiHeal` | да |
| `pending_multi_discharge` | attacker | `chooseMultiDischarge` | да |
| `pending_transfer` | attacker | `chooseTransferDonor/Amount` | да (возврат монет) |
| `pending_wound_transfer` | attacker | `WoundTransferProcessor` | да |
| `pending_blood_tap` | attacker | `chooseBloodTap` | да |
| `pending_revive` | healer | `chooseReviveTarget/Cell` | да (возврат монет) |
| `pending_forced_strike` | attacker | `chooseForcedStrike` | **нет** |
| `pending_any_death` | seeder owner | `chooseAnyDeathTarget` | да (target_id=0) |
| `pending_card_choice` | owner | `chooseCardOption` | нет |
| `pending_incarnation` | owner | `chooseIncarnationCell` | нет |
| `pending_prophecy` | owner | `ProphecyProcessor` | через `close_prophecy` |
| `pending_grezy` | owner | `grezyPick` | нет |
| `pending_whip` | owner | `chooseWhipTarget` | да |
| `pending_kobold_heal` | owner | `chooseKoboldHeal` | да |
| `pending_valhalla_pick` | owner | `ValhallaProcessor::chooseTarget` | да |
| `pending_instant_pick` | owner | `InstantProcessor::chooseTurnTarget` | да |
| `pending_cell_marker_pick` | owner | `chooseCellMarker` | да |
| `pending_dice_choice` | owner | `chooseDiceChoice` | да |
| `pending_combat_pick` | owner | `InstantProcessor::chooseTarget` | да |
| `pending_turn_instants` | owner | `InstantProcessor::playTurnInstant` | да |
| `pending_talion_incarnation` | owner | `chooseTalionIncarnation` | да |
| `pending_holvert_open` | owner | `chooseHolvertOpen` | да |
| `pending_after_strike_execute` | owner | `chooseAfterStrikeExecute` | нет |
| `pending_dive` | owner | `chooseDiveCell` | да |
| `pending_forced_directional_move` | responder | `ForcedDirectionalMoveChoice` | да |

Внутри `battle['strike']`:
| Ключ | Смысл | Отмена |
|---|---|---|
| `pending_push` | Молотобоец | нет |
| `pending_close_or_damage` | Овражный гном | нет |
| `pending_ally_modifier` | Ледяной змей | нет |
| `pending_auto` | авто-атака | да (target_id=0) |
| `pending_choice` | on_death.choice | нет |

---

## 7. Флаги карты (`card->flags`)

Живут в рамках хода / боя. Сбрасываются в `TurnProcessor::continueStartTurn`.

| Флаг | Тип | Смысл | Сбрасывается |
|---|---|---|---|
| `moved_this_turn` | bool | двигалась в этот ход | в начале хода владельца |
| `moved_last_turn` | bool | двигалась в прошлый ход | копируется из `_this_turn` |
| `damage_taken_this_turn` | int | раны за ход | в начале хода |
| `damage_taken_this_strike` | int | раны за удар | в `StrikeResolver::declare` |
| `ranged_hits_this_turn` | int | попадания ranged | в начале хода |
| `attacks_used_this_turn` | int | счётчик атак | в начале хода |
| `first_attack_target_id` | int | первая цель Берсерка | в начале хода |
| `shot_used_this_turn` | bool | Оури | в начале хода |
| `after_strike_execute_used_this_turn` | int | Килсус, не более 2 | в начале хода |
| `any_death_used_this_turn` | bool | Сейатель, Хозяин склепа | в начале хода |
| `prophecy_done_this_turn` | bool | пророчество | в начале хода |
| `attack_block_used_this_turn` | bool | Дочь перламутра | в начале хода |
| `instant_uses_this_turn` | `{key: count}` | инстанты | в начале хода |
| `revive_used` | bool | once_per_battle | не сбрасывается |
| `steal_weapon_used` | bool | Лесной разбойник | не сбрасывается |
| `dive_used` | bool | Пустынный кондор | не сбрасывается |
| `steal_coin_used` | bool | Махинатор | не сбрасывается |
| `choice_done` | bool | pre_turn_start | в начале хода |
| `incarnation_ready` | bool | инкарнация | не сбрасывается |
| `incarnated` | bool | инкарнировала | не сбрасывается |
| `airin_triggered_this_turn` | int | Айрин, до 2 | в начале хода |
| `in_stack` | bool | инстант в стеке | после resolve |
| `in_prophecy_pending` | bool | в окне пророчества | после close |
| `prophecy_reorder_used_this_turn` | bool | Махинатор | в начале хода |
| `movement_direction_bonus` | `{directions, ...}` | Риала | в конце своего хода |
| `trigger_used_this_turn:<key>` | int | Рубаки Холверта | в начале хода |
| `no_close_this_turn` | bool | Кабаний наездник | в начале хода |

---

## 8. Зоны и поля CardInstance

- `hp / hpMax` — здоровье
- `move / moveMax` — ходы (эффективный ход = `effectiveMove()`)
- `armor / armorMax` — броня
- `strikeWeak / strikeMedium / strikeStrong` — урон по уровням
- `coins` — монеты
- `element` — plains / mountains / forests / swamps / dark
- `class` — Йордлинг, Орк, Тоа-Дан, Аккениец, Гном, Дракон
- `type` — creature / fly
- `price` — цена
- `elite`, `single`
- `revealed`, `closed`, `dying` — состояния

---

## 9. Правила

- **Строй** (`has_line`) — соседние по ортогонали (dr + dc == 1). Проверка: `CardStats::isInLine`.
- **Напротив** — тот же столбец, row ± 1 в сторону врага. `CardStats::isOpposite`.
- **Обязательная атака** — если рядом закрытый враг и есть `forced_strike`, атака обязательна, блокирует `end_turn`.
- **Перехват** — при перехвате цель ограничена. Для летающих — Паук, для дальних — Резчик/Аргвальд/Мерцающий.
- **Перераспределение ран** — шаги source → amount → target → target_amount.
- **Пророчество** — берёт N верхних карт из колоды, показывает, кладёт вниз.
- **Инкарнация** — только карты с `prop['incarnation']`, жетоны в `markers.incarnation`.

---

## 10. Что не покрыто в этом документе

- `deal.*` — механики набора отряда (`PrepareProcessor`).
- `Prepare/*` — драфт, отряд, расстановка.
- UI-интеграция (templates).
