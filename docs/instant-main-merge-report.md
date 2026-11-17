# Слияние инстантов в main

Объединены `main` (`291241d`) и `feature/instant-system-final` (`8daeccb`).
Работа проведена в отдельном чистом worktree; рабочая OSP-копия не переключалась.

Разрешены четыре конфликта:

- DiceChoiceChoice: сохранён новый фильтр main, исключающий отсутствующие
  кубики, и добавлен выбор контекста single_roll из feature.
- LegalActions: сохранены CardChoice и FirstStrikeJump из main, добавлен WhipChoice.
- InfoPanel: сохранены уведомления main и окна одиночных бросков из feature.
- Шаблон боя: сохранены версии остальных скриптов main, battle-ui обновлён до v23.

20 основных наборов проверок прошли: battle_types, доступность, C1/C2/D,
переброс, Stage 4, InstantControl, результат инстанта, фазы, continuation,
turn-стек, ACK, pending-таймер, бот Whip и регрессии бота, бот healing/uchr,
wounds/gate/grave ritual, Abyss luck window.
Браузер результата: 19 проверок, Edge 1600/800, без ошибок JS.
Дополнительно прошли card-selection stage1, Ragnar, snapshot renderer,
creature choice и реальные turn-карты. PHP lint изменённых файлов и JS syntax
прошли; конфликтных маркеров и ошибок diff-check нет.

`test_card_selection_stage3.php` и зависящий от него
`test_life_gift_stale_targets.php` падают на проверке
«An unrelated version increment does not replace the life gift decision».
Та же ошибка воспроизведена в отдельном baseline-worktree чистого `291241d`;
к слиянию не относится и здесь не исправлялась.

Миграция battle_types остаётся подготовленным файлом: рабочая БД не изменялась.
