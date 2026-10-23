# Stage4D.2 basic restoration — включение одного нового контрольного матча

Ничего не включается загрузкой файлов. Production агентом не менялся. Используется текущая авторизация AuthSession, без приглашений и без Replay Preview.

1. На актуальном runtime, основанном на 094acde, создать комнату двумя авторизованными тестовыми аккаунтами. Получить ID комнаты; **ещё не подтверждать второго участника**. Для быстрой LuckTest это особенно важно: первый battle event возникает внутри транзакции принятия.
2. Оператор вручную задаёт в приватном config/battle_history.local.php (не Git):

```php
<?php
return [
    'recording_enabled' => true,
    'journal_enabled' => true,
    'control_room_ids' => [123], // заменить реальным integer ID ожидающей комнаты
    'record_game_ids' => [],
    'journal_game_ids' => [],
    'catalog' => [], // для текста внешний replay catalog не требуется
    'preview_enabled' => false,
    'preview_game_ids' => [],
    'admin_user_ids' => [],
    'journal_access_enabled' => false,
    'journal_test_bindings' => [],
    'journal_access_local_http' => false,
];
```

Это пример ручного opt-in, не активный конфиг в пакете. config/battle_history.example.php остаётся OFF, control_room_ids=[]. Сохранить другие необходимые server-owned настройки, не перезаписывать существующие секреты вслепую. После замены учитывать OPcache/FPM cache хостинга.
3. Проверить, что существующие пять history tables установлены и runtime имеет необходимые права без DDL/TRIGGER. Game DB writer: SELECT cards.name/ukid плюс прежние права history/journal/game. Lobby DB соединение auth.local.php→lobby_db: SELECT/INSERT battle_history_matches для регистрации; quick-game использует прежний locked executor в borrowed transaction, поэтому ему нужны соответствующие прежние SELECT/INSERT/UPDATE на journal/history/game и SELECT на карты. Если прав нет — принятие выбранной комнаты безопасно откатится; не обходить guard и не делать автоматическую миграцию.
4. Подтвердить второго участника. MatchCreator создаёт разрешение в существующей battle_history_matches в **той же** транзакции, что game и связь комнаты. При ошибке ничего частичного не сохраняется. Новый game ID разрешается runtime по выбранной комнате и существующему enrollment; frontend его не назначает. Добавление уже начатой комнаты без enrollment не включает запись задним числом. Списки game IDs прежнего режима остаются поддержаны.
5. Выполнить расстановку, начать бой, открыть «Логи» в двух независимых браузерных профилях. Расстановка/снятие не являются строками текстового журнала. Движение, обычный удар/броски/результат/раны, смерть и terminal должны появляться; скрытую карту видит только допустимая историческая сторона. В API не добавлены raw payload, ukid, prop или projections. После game_over журнал доступен по той же авторизованной привязке, пока чтение включено.
6. Для остановки после теста выставить recording_enabled=false и journal_enabled=false; данные не удалять. Для продолжения **только чтения завершённого боя** можно оставить journal_enabled=true и ID комнаты в control_room_ids при recording_enabled=false. Не выключать/возобновлять запись посреди старого матча после пропущенных команд — следующий эксперимент в новой комнате. Удаление room ID прекращает допуск через этот режим; явные game allowlists, если заданы отдельно, действуют независимо.

Новый provider читает только name из существующего cards на стороне записи и замораживает строку в _journal. При отсутствующей/неоднозначной/пустой/невалидной display definition используется нейтральное «Карта» лишь для видимого адресату объекта. Скрытая проекция остаётся null. Это не разрешает показывать противнику скрытый ukid/способность. Reader не обращается к текущему справочнику, старые projections/null не заполняются.

Базовое покрытие текста независимо от допуска к snapshots. Прежняя snapshot certification по-прежнему может дать diagnostic recording_gap; это не удаляет новые содержательные строки и не включает preview. Неизвестные/специальные команды всё ещё проверяются; whitelist не расширен на все ACK/continuation.

Не использовать старый Stage4D.2 manual-package.zip: это другой, прежний release. В текущем изменении runtime-файлы: config/battle_history.example.php; src/Core/BattleHistory/{HistoryRuntime,Recorder,JournalCardNames}.php; src/Core/GameRepository.php; src/Lobby/MatchCreator.php; www/{index,battle-journal}.php. Изменения не отправлялись на сервер автоматически.

Ручная приёмка: два реальных аккаунта и посторонний, реальная колода, скрытая карта до/после раскрытия, нормальный удар/инстант/результат, таймер/pending, reload/reconnect, завершение боя и чтение после повторного login. Отдельно мобильный layout и OPcache/TLS/права hosting DB. Старый guard GameRepository::save по-прежнему ограничивает прямые внежурнальные сохранения (например смену коврика во время recorded battle); он не ослаблен этим этапом.
