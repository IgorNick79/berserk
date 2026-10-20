# Stage 4D — аудит источников до изменений

База: Stage4C 139979d. Result.events — диагностические строки/структуры неодинакового формата, не исторические проекции. Не парсить их в пользовательский журнал. Не использовать diff конечного GameState.

| Событие | Фактический источник | Уже записывается Stage4C | Подключение Stage4D | Историческая видимость |
|---|---|---|---|---|
| Перемещение | Movement/MovementResolver::move после мутации | move.started, только simple | сохранить существующее; событие и при uncertified snapshot | все refs известны; иначе скрыть |
| Атака | StrikeResolver::declare после назначения strike | combat.started, только simple | сохранить один root через команды, даже при gap | атакующий + цель известны |
| Кубик | StrikeResolver::resolve после Dice::roll | combat.dice, только simple | сохранить; recalcTable не пишет dice | оба участника известны |
| Итог удара | StrikeResolver::apply после guards/reduction, перед уроном | combat.result | сохранять фактический final, не раннюю таблицу | оба участника известны |
| Раны/броня | DamageResolver::applyDamage после actual mutation | combat.wounds, только combat/simple | тот же hook, работает и для event-only scope | target/source известны; неизвестный source не утверждать |
| Гибель | DamageResolver::resolveDeath после dying=true | combat.death | тот же hook вне simple; не дублировать finalizeDying | card/source известны |
| Лечение действием | ActionResolver::resolveHeal | нет | local HP before/after именно исполнения, ability + heal | healer и target известны |
| Лечение инстантом | Engine::applyInstantEffect heal/heal_turn_wounds | нет | local HP delta после применения | source и target известны |
| Вампиризм | DamageResolver::applyDamage | нет | положительный фактический heal | обе карты известны |
| Вальхалла heal/modifier | ValhallaProcessor::applyEffect | нет | конкретные успешные heal/modifier; нет заявлений о полном покрытии | source/target известны, неизвестные скрывать |
| Инстант с результатом | InstantProcessor::appendInstantSummary / resolveTurnStack | summary вне истории | только applied=true; label из фактического item, без pending/reason/technical tokens | source + все refs фактического effectResult известны |
| Изменение статуса инстантом | Engine::applyInstantEffect open/close_target/marker | нет | локальное событие применённого эффекта, без отдельного snapshot | source/target известны |
| Конец/начало хода | TurnProcessor::endTurn и afterStartPhase timer resume | lifecycle turn_end/battle_ready | события turn.ended/turn.started через существующие hooks; номер завершаемого хода копируется до существующего переключения, порядок прежний | public событие без карт |
| Завершение серии | ActionResolver::chooseAttackSeriesEnd | series_end.started | существующее событие, один лог и прежний snapshot | Hydra известна |
| Завершение матча | Recorder::commandFinished после Engine success | match_terminal.settled | показать один terminal | public winner |
| Прочие heal/status/характеристики | multi heal, kobold, TurnPhaseProcessor::executeRegen/executeTrigger, TurnProcessor::applyStartEffects, DamageResolver death triggers, CoinAbilities, Degasca, WoundTransfer и прочие | неполное покрытие | не утверждать полный аудит всех ветвей; безопасные неподключённые пути остаются gap | ничего не реконструировать |

Новые hooks пассивны, после фактической мутации. Отдельная запись событий не повышает допуск к snapshots: uncertified combat root можно закончить без snapshot. Gap отмечает неполноту. Event-only root не создаёт Replay Step. Исторические имена/идентификаторы замораживаются в проекции во время emit; read API не обогащает её текущим GameState/каталогом. Неподтверждённая видимость — null, cursor всё равно проходит запись.

Фактическая battle UI visibility: BattleScreen::isKnown и field/flying rendering используют owner/revealed; closed — закрытие/затенение уже известной карты, не автоматическое сокрытие лица. Journal следует этой границе и дополнительно скрывает deck/sideboard/opponent hand и unknown definitions. Legacy Core/View::cards содержит более старую проверку closed для своего DTO; её не меняли и не использовали для обогащения истории.
