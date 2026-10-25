# Stage 2A — завершение эффективных особенностей

Дата: 2026-10-25. Статус: перечисленные пять маршрутов закрыты, архитектура готова к приёмке Stage 2A в проверенном контуре PHP CLI + MariaDB/InnoDB. FPM/HTTP и Oracle MySQL не сертифицированы. Stage 2B не начат; решение о переходе остаётся за Project Lead.

## Изменённые файлы этой итерации

Core:
- src/Core/ActionResolver.php
- src/Core/CoinAbilities.php
- src/Core/DamageResolver.php
- src/Core/DegascaProcessor.php
- src/Core/InstantProcessor.php
- src/Core/StrikeResolver.php
- src/Core/TurnPhaseProcessor.php
- src/Core/WoundTransferProcessor.php

Тесты:
- tools/test_effective_feature_continuations.php — новый.
- tools/test_effective_features_sql.php — новый, guard --isolated и проверка порта/datadir до DML.

Отчёты:
- docs/effective-features-stage2a-completion.md — настоящий отчёт.
- docs/effective-features-stage2a.md — обновлены статус и перечень закрытых рисков.
- docs/machinator-reorder-stage2a.md — добавлена ссылка на последующий итоговый отчёт.

Предыдущие незакоммиченные изменения Stage 2A сохранены. Нового массового аудита/рефакторинга prop не проводилось. Посторонние исходники, UI, InfoPanel, Recorder, GameRepository, CommandJournalRepository, CombatContinuation и SQL-схема не изменены. Commit/push не выполнялись.

## Пять маршрутов

| Маршрут | Проблема | Исправление | Адресные тесты |
|---|---|---|---|
| Cached death-choice | Сохранённый effect исполнялся без проверки погибающего источника | chooseDeathTarget проверяет подавление после владельца и до выбора/эффекта. При подавлении удаляется только неисполненный pending_choice; остаются результаты боя и ACK | normal/suppressed/released, dying остаётся field, JSON reload, отсутствие нового урона/расходов, retry, отсутствие отката завершённого результата |
| Incarnation-wound и resumeCoinAttack | Две сохранённые реакции обходили актуальные особенности | Для жетона проверяется владелец источника и подавление; подавленная запись потребляется без урона через прежний хвост обработки очереди. Для монеточной атаки проверяется исходный card_id/owner перед resolveDamage; CoinAbilities не оплачивает свежую защиту против уже недоступной атаки | Реальные onTokenGranted и объявление платного выстрела; pending/reload; снятие; повтор; фиксация стоимости до окна; отсутствие повторной оплаты и нового закрытия неисполненной атаки |
| Отложенный row_spell | flags.row_spell_pending мог открыть новый выбор из cached action | После штатной очистки расписания/маркеров executeRowSpell проверяет подавление до кубика и выбора. Уже открытый pending_row_spell_pick использует точечную проверку перед исполнением | Реальное объявление/выбор ряда; task/ACK; reload расписания и выбора; подавление на обоих этапах; снятие; сохранение цены/закрытия; отсутствие повторного урона |
| Вложенные pending Дегаски | Пропуск Талиона терял сохранённую последовательность; подавленный выбор изучения возвращал ошибку после изменения состояния; цепи требовали цели даже для недоступного эффекта | skipSuppressedChoice возвращает сохранённый degasca_sequence существующему restoreAndResume. Подавленное завершение chooseCard возвращает success и сохраняется транзакционно. Цепь пропускается до валидации новых целей с возвратом в прежнее продолжение | Chain/Talion, any-death/Nokami, выбор изучения; normal/suppressed/released/reload/retry; независимая реакция другой карты не отменяется из-за подавления родителя; следующий эффект родителя не исполняется; завершённый базовый удар сохраняется |
| Подавленные инстанты: расходы, закрытие, продолжение | Ранний стек мог сообщать applied=true для эффекта без исполнения; подавление переноса ран вызывало refundable cancel; no_close терялся при чтении пустого effective instants | Честный summary applied=false. Новый локальный хвост подавления переноса ран не возвращает монеты и не открывает карту, проверяет соответствие paused queue item и продолжает прежнюю очередь. Сохраняется прежний tap-контракт обычного failed item и исходный no_close | Платный turn instant, paused dice с/без no_close, paused wounds с соседним item, все шаги переноса; reload, нормальное исполнение, подавление, снятие и retry/ACK |

## Границы фиксации и ресурсы

- Death-choice: необратимость — фактическое исполнение выбранного эффекта. Уже нанесённый урон/лечение не отменяется. hp=0/dying не меняет границу ухода с поля; порядок гибели и Вальхаллы прежний.
- Incarnation-wound: уже выданный жетон — завершённое действие. Подавление касается неисполненной реакции на жетон. Из очереди удаляется только обработанная запись; ранее созданные сообщения/ACK сохраняются.
- resumeCoinAttack: фиксированная цена action.coins уже могла списаться в handle до окна защиты. Она остаётся. При подавлении не запускаются resolveDamage, дополнительное расходование монет, кубик и новое закрытие. Повтор команды владельца pending не исполняет атаку снова.
- Row spell: цена объявления и состоявшееся закрытие при выборе ряда остаются. Подавление не возвращает эти ресурсы. Расписание удаляется штатно, чтобы после снятия подавления не запустить старую задачу повторно.
- Дегаска: уже разрешённые strike_effects и независимые реакции других источников сохраняются. Неисполненная часть родителя повторно проверяется через его актуальные эффективные особенности. Изучение определяется только prop/реальными strike_effects; UKID и перечень изучаемых карт не захардкожены.
- Turn instant оплачивает монеты при pushTurnInstantEntry. Оплата и per-turn use остаются и при fizzle. Закрытие выполняется в прежней точке завершения, как у обычного failed item; это не новое правило оплаты.
- Combat paused item использует существующий completePausedCombatItem и обычный возврат в ACK/следующий item. Условия no_close не превращаются в новую оплату закрытием.
- Осознанное прямое чтение исходного prop осталось в combatItemRequiresTap: оно восстанавливает tap-контракт уже объявленного инстанта, а не исполняет его особенность. Эффект проверяется отдельно через effective features. Это устраняет ошибку, когда пустой effective instants давал default tap=true для no_close.
- Автоматический возврат цены из skipSuppressedChoice удалён. Прежняя добровольная отмена игроком (cancel/cancelWithRefund) не переписывалась.
- Подавление переноса ран отделено от cancel: нет универсального возврата монет или открытия карты. Соседний technical queue item не потребляется при несовпадении источника/типа.

## Тесты

В stage2a-final-validation/app выполнены 32 скрипта, все PASS.

Адресные наборы:
- test_effective_features.php — 93 проверки.
- test_machinator_reorder.php — 51 проверка.
- test_effective_feature_continuations.php — 339 проверок поверх инфраструктурного набора.
- test_effective_features_sql.php — 44 SQL/integration проверки поверх инфраструктурного набора.

Остальные регрессии: combat_continuation, matriarch_order, matriarch_boundaries, movement_effects, anubisar, talion, kobold, krovyanitsa, kilsus, soul_eater_shaman, medusa_goblin_borg, swamp_children, hydra, bon_and_berrow, informational_wait, nested_combat_ownership, chaos_germet_poganishche, degasca, strangulation, kersam_satyr_wild_healer, live_battle_journal, journal_gap_causes, stage31_fixes, battle_helper_movement, forced_directional_movement, riala_movement, combat_instant_phases, turn_instant_stack.

Все 8 изменённых Core PHP и 2 новых теста проходят php -l. diff --check проходит с нормализацией Windows CRLF. Хэши 202 PHP-файлов текущих исходников и проверенной копии совпадают. Регрессии без «Потери памяти» проходят; изменения результатов в этом контуре не обнаружены.

## Реальная SQL-проверка

Сервер: MariaDB 10.8.4, InnoDB, 127.0.0.1:33991.
Datadir: C:/Users/mi/Documents/Berserk/stage2a-final-validation/mysql-data.
Это отдельная копия ранее сохранённого disposable lucktest-stage3/sql-data. Рабочий сервер и рабочая БД не использовались; исходный retained datadir не запускался и не модифицировался тестами. Использована существующая тестовая схема berserk_lucktest_stage3_c7a111282b внутри копии. Тест не читает product DB config и не выполняет CREATE/ALTER/DROP. Структурные хэши таблиц до/после совпали (data-dependent AUTO_INCREMENT исключён).

Проверено через настоящие GameRepository::executeLockedCommand, CommandJournalRepository и обязательный live Recorder:
- Начальная запись создаётся реальной командой confirm_place и существующим Recorder::initial.
- Для Stage 2A без активной способности Stage 2B инфраструктурное подавление и настоящая onTokenGranted-реакция устанавливаются в locked test callback после реального move. Это тестовая инъекция инфраструктуры, не новая игровая команда/способность.
- GameState с воздействием и pending сохраняется и заново загружается из InnoDB.
- Подавленная реакция не наносит урона, очередь потребляется атомарно вместе с журналом.
- Снятие воздействия и последующее SQL reload восстанавливают исполнение уникальной реакции.
- Ошибка json_encode при сохранении откатывает действие, подавление, pending и observer.
- Реальная SQL-ошибка 1406 в UPDATE games также откатывает всё перечисленное.
- Инъекция недоступности recording metadata внутри disposable транзакции вызывает ошибку обязательного append после CAS-save; откатываются GameState, потребление pending и временное изменение metadata. Схема не меняется.
- Повтор после rollback проходит под тем же processing journal id.
- Повтор уже committed command_id возвращает committed запись без нового исполнения.
- CAS conflict обнаруживается до вызова Engine; состояние и события не меняются.

Изолированный сервер остановлен через mysqladmin после повторной проверки порта/datadir. Данные и логи оставлены как доказательства.

## Ограничения

- Проверен PHP 8.1.9 CLI + mysqli и реальная MariaDB/InnoDB. PHP-FPM в доступном PATH не найден, активного php-cgi worker нет; FPM/HTTP-проверка не выполнялась. Oracle MySQL 5.7/8.0 в этой итерации также не запускался. Полная сертификация этого контура не заявляется.
- Сохранена существующая классификация Recording gap для неохваченных Recorder команд, в том числе перестановки Махинатора. Тесты не выдают gap за полноценную запись способности; Recorder не изменён.
- SQL failure injection и CAS проверяют командную транзакцию, а не сетевой HTTP transport.
- Проверены конкретные перечисленные маршруты. Это не новый полный аудит всех текущих/будущих карт.

## Доказательства

- ../stage2a-final-validation/results.json — 32/32 PASS и полные stdout/stderr.
- ../stage2a-final-validation/lint.json — проверка синтаксиса.
- ../stage2a-final-validation/source-integrity.json — совпадение исходников с проверенной копией.
- ../stage2a-final-validation/environment.json — версия/изоляция/остановка SQL и пределы FPM.

## Решение по готовности

Пять заявленных рисков закрыты без изменения боевых правил, порядка инстантов, общей архитектуры pending и Recorder. В проверенном контуре Stage 2A готов к приёмке Project Lead. Переход к Stage 2B автоматически не выполняется.
