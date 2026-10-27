# Stage 4 — предварительный аудит

InfoPanel.renderInstantOffer выводит старый open_turn_instants в scroll-content.
Combat waiting_instant рисует карточки combat_instant_play и обязательный pass.
TurnInstantStackChoice выводит карточки play_turn_instant и pass_turn_instant.
Footer уже flex:0 0 auto; новый блок размещается здесь перед end_turn.
battle-ui.syncCombatPanel скрывает весь footer при inspect — это скрытие отменяется.
Выбор способности остаётся штатным, но открывается локальным details-компонентом
footer: единственная основная кнопка не выполняет боевую команду и не выдаёт приоритет.
Пас переносится в footer без дублирования и остаётся доступен при inspect.
Режим резерва берётся исключительно из InstantReservation.controls.

Отшельница сейчас допускается в main, объявленная wounds-запись откладывается
pending_wounds_resolution и разрешается после result ACK. Сохраняются старые
объявленные записи и их continuation. Новые wounds-декларации исключаются из
dice/main; в continueAfterStrikeResultAck добавляется однократное существующее
combat-окно wounds после damage_applied и finalizeDying. Пустое окно пропускается.
Окончание этого окна вызывает прежнее wounds-разрешение/continuation, не apply урона.
Доступность проверяет существующие source/target predicates Stage 3A и чистую
проверку доноров/получателей WoundTransferProcessor, не меняющую эффект.
Фундаментальная перестановка боя не требуется. Резервирование, БД и таймер не меняются.
