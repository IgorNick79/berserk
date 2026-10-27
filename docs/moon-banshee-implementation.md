# Лунная баньши (`s1_157`) — реализация

Реализация готова в рабочей копии. Рабочая БД/схема не изменены. После игровой проверки по отдельной просьбе изменены положение кнопок и подпись результата в InfoPanel. Commit/push не выполнялись.

## Предлагаемый полный prop

Сохранён отдельно: `docs/moon-banshee.prop.json`.

```json
{
  "start": {
    "type": "get_coins",
    "coins": 1,
    "side": 2
  },
  "instants": [
    {
      "key": "krik",
      "name": "Крик баньши",
      "trigger": "combat",
      "phase": "value",
      "target": "opposing_strike",
      "tap_source": true,
      "effect": {
        "type": "strike_value",
        "mode": "add",
        "value": -1,
        "gain_coins": 1
      },
      "uses_per_turn": 0
    }
  ],
  "actions": [
    {
      "key": "horror",
      "name": "Ужас",
      "type": "cast",
      "effect": "temporary_health",
      "coins": 1,
      "value": -3,
      "no_attack_wounds_this_turn": true
    }
  ]
}
```

`tap_source` — штатный контракт закрытия инстанта. `uses_per_turn: 0` не вводит отсутствующий на карте лимит «раз за ход»: повтор возможен только после штатного открытия карты. `start.side: 2` означает именно Второго игрока, независимо от host/player. Существующая реализация get_coins не изменена.

## Механика

**Крик.** Штатный `InstantProcessor`, фаза value и существующие очередь/LIFO. `target: opposing_strike` автоматически выбирает удар противника по вашему участнику текущего сражения: основной удар при защите либо ответный удар при нападении. Отдельный выбор существа не открывается. Когда противник не наносит удар, способность недоступна. Уже внесённый прежний prop с target=striking_creature и отрицательным strike_value/gain_coins также нормализуется в этот режим без изменения БД. Общий режим selectable striking_creature сохранён для других числовых инстантов и покрыт отдельными проверками token/отмены.

Новый числовой эффект strike_value хранит последовательные изменения отдельно от strike_level_effects. Он применяется после числовых бонусов/снижений, до установки величины и защиты. При разрешении учитываются ранее разрешённые изменения; уменьшение ограничено нулём. Модификаторы не перезаписывают друг друга. Жетон через CoinAbilities::gain начисляется только при успешном изменении величины. Промах, исчезнувший участник/источник, подавление или отсутствие числового изменения дают штатный no-op без нового выбора и жетона. Закрытие следует существующим правилам инстанта. Броски и исходный удар повторно не запускаются. Исходные механизмы смены ступени других карт сохранены.

**Ужас.** Обычное действие cast с параметризованным эффектом temporary_health. Общие проверки источника, цели, защита от заклинаний, пророчество, расход жетона и закрытие сохранены. Отдельного pending не требуется: существующий выбор цели действия отправляет card_id/action_key/target_id. BattleHelper и validateTarget используют один predicate; при отсутствии целей кнопка недоступна, команды также отвергаются. В BattleScreen изменён только predicate доступности кнопки; оформление и компонент InfoPanel не менялись.

**История атак.** В DamageResolver после брони/предотвращения отмечаются только фактические положительные раны типов strike, answer, uchr, tap, magic, shot, throw, discharge. Это отдельный attack_wounds_this_turn, а не проверка HP или общего damage_taken_this_turn. cast/impact/poison/self_wound/перенос ран и уменьшение здоровья не являются атакой. Лечение не стирает историю. Флаг сбрасывается у всех карт после завершения фазы конца хода, перед началом следующего.

**Здоровье.** По РП 508.8–508.8.3 изменение максимума меняет текущее здоровье на ту же величину, сохраняя раны. Источник: [текст расширенных правил, п. 508.8](https://bersnsk.ru/RP_2_11_2023.pdf) (в примере прямо приведена Лунная баньши). Например 15/20 → 12/17 → в конце хода 15/20; лечение на 2 между этими событиями даёт 17/20 после снятия. TemporaryHealth материализует разницу в hp/hpMax и сохраняет запись в существующем modifiers с expire=end_of_turn. TurnProcessor снимает запись и обратную разницу, не создавая события лечения или ран. Несколько эффектов складываются, независимые прибавки HP сохраняются, уход источника и подавление уникальных способностей не отменяют уже разрешённое заклинание. При нулевом HP используется штатная гибель от cast. Перед сбросом модификаторов на кладбище/в изгнании восстанавливается максимум, чтобы отрицательный максимум не пережил смерть/возрождение; погибшая карта не воскресает при истечении эффекта.

## Изменённые файлы

Runtime:
- `src/Core/CombatStrikeValue.php` — числовые изменения основного/ответного удара.
- `src/Core/TemporaryHealth.php` — общий параметризованный эффект здоровья и снятие.
- `src/Core/Engine.php` — разрешение числового инстанта и получение жетона.
- `src/Core/InstantProcessor.php` — фаза, predicate целей и token pending.
- `src/Core/Choice/CombatPickChoice.php` — фильтрация наносящих удар участников, token, штатная отмена.
- `src/Core/StrikeResolver.php` — применение числовых модификаторов до setter.
- `src/Core/ActionResolver.php` — Ужас через общий lifecycle действия.
- `src/Core/BattleHelper.php` — допустимые цели Ужаса.
- `src/Core/DamageResolver.php` — факт получения ран именно от атак.
- `src/Core/TurnProcessor.php` — снятие временного HP и сброс истории атак.
- `src/Core/ZoneManager.php` — очистка материализованного эффекта при смерти/изгнании.
- `src/View/Screen/BattleScreen.php` — только недоступность действия без допустимых целей.

Материалы/тесты:
- `docs/moon-banshee.prop.json`, `docs/moon-banshee-implementation.md`.
- `debug/scenarios/moon_banshee.json` — 41 сценарий с настоящими командами Engine и ожидаемым состоянием, включая атаки, излечение, ACK, pending и завершение хода.
- `tools/test_moon_banshee.php`, `tools/test_moon_banshee_edges.php`, `tools/test_moon_banshee_persistence.php`.
- `.gitignore` — разрешён только новый JSON в debug/scenarios.

## Проверки

Из корня `C:/Users/mi/Documents/Codex/Berserk`:

```powershell
& 'C:/OSPanel/modules/php/PHP_8.1/php.exe' tools/test_moon_banshee.php debug/scenarios/moon_banshee.json
& 'C:/OSPanel/modules/php/PHP_8.1/php.exe' tools/test_moon_banshee_edges.php
& 'C:/OSPanel/modules/php/PHP_8.1/php.exe' tools/test_moon_banshee_persistence.php
```

Реально выполнено: JSON 41 сценарий/342 проверки; edges 45 дополнительных; persistence 42 дополнительных. Итого 429 уникальных проверки Баньши; JSON/edges повторно запускаются при require и повторно в сумму не включены. В edge-тестах рендер BattleScreen обоих игроков, владелец/кнопки/токен pending, подавление и уход источника, чистый recalc. В persistence — настоящие GameRepository/CommandJournalRepository lock/CAS/rollback/retry/committed duplicate с существующим process-local Db adapter для Ужаса, помещения Крика в стек и разрешения Крика.

Прошли 23 соответствующих регрессионных набора:
- test_combat_instant_phases, test_combat_instant_ui, test_combat_panel_ui;
- test_instant_availability, test_instant_stage4, test_instant_reservation, test_free_instant_reservation;
- test_stage31_fixes (33 сценария, в изолированной копии с публичным config/instant-reservations.php);
- test_combat_continuation, test_damage_lifecycle, test_action_resolution;
- test_effective_features, test_effective_feature_continuations;
- test_morok, test_gloomy_noblewoman_edges, test_turn_instant_stack;
- test_ragnar, test_strangulation, test_informational_wait, test_creature_choice_followup;
- test_kersam_satyr_wild_healer, test_flesh_stitcher, test_lord_of_the_dead.

PHP lint изменённых/новых PHP и git diff --check прошли. Первоначальная попытка Stage31 в основной копии была штатно отклонена guard; после переноса в изолированную копию с публичным конфигом полный набор прошёл. Имени test_combat_instant_phases_ui.php в проекте нет; вместо него выполнены реальные test_combat_instant_ui.php и test_combat_panel_ui.php.

## Границы проверки

Рабочая SQL-БД не запускалась и не менялась; persistence проверена через нативные репозитории с in-memory адаптером, не через SQL-сервер. Ручная игра в браузере не проводилась. Для старого сохранения, созданного до добавления attack_wounds_this_turn, нельзя достоверно восстановить уже произошедшие в текущем ходу атаки по одному HP/общим ранам; точная история гарантируется для событий после обновления и новых ходов. Предлагаемый prop нужно внести отдельно перед игровой проверкой.


## Правки после игровой проверки

- `www/assets/css/global.css`: «Инстант» и «Пас» занимают одну строку, «Пас» выровнен по центру. Для основного действия и раскрывающегося списка используется одинаковый flex-контракт.
- Крик автоматически нацелен на удар противника по союзному участнику, без pending выбора карты; прежний внесённый prop поддерживается.
- JSON, edge и persistence тесты обновлены на автоматическое применение с защищающейся и атакующей стороны. Проверки общего picker оставлены для других инстантов.
- Реальный HTML BattleScreen проверен в headless Edge на ширинах панели 240, 280, 375 и 600 px: обе кнопки в одной строке, text-align Пас=center.

- Дополнительная правка: управление инстантом перенесено в начало контекста InfoPanel; результат показывает «Крик баньши: урон уменьшен на 1». Механика по-прежнему уменьшает величину на 1. Проверка подписи выполнена для обоих игроков.
