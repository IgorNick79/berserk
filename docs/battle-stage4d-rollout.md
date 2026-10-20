# Stage 4D — план ограниченного доступа для Project Lead

Production не активирован. Без отдельного разрешения Lead не выполнять deploy, push, SQL или изменение server local config. Пять существующих history tables и journal не пересоздавать, SQL-триггеры не нужны. Stage4C Replay Preview остаётся под прежней trusted-auth policy.

## По умолчанию

В `config/battle_history.example.php` recording_enabled, preview_enabled, journal_enabled выключены; все allowlist и test bindings пусты. Параметры HTTP не включают запись или просмотр. На обычной странице кнопка «Логи» disabled, panel/script не подключаются. Загрузка согласованных файлов сама ничего не активирует.

## Разрешённый тест без настоящей авторизации

1. Выбрать только новые синтетические тестовые матчи до стадии place. Проверить владельцев, заморозить display catalog с revisions. Указать их точные integer IDs в record_game_ids и journal_game_ids; никогда не все матчи/wildcard. Для записи event envelope нужны оба server switches recording_enabled и journal_enabled; отдельные игры должны входить в оба списка.
2. Под контролем оператора создать каждому тестировщику отдельную PHP session. Сгенерировать непредсказуемый 32-byte token (`bin2hex(random_bytes(32))`). Только доверенный server-side provisioning записывает token в `berserk_battle_journal_binding` этой сессии и server local map: token => `{game_id: integer, side: host|player, expires_at: Unix integer}`. Установить короткий срок и передать session cookie по защищённому доверенному каналу конкретному тестировщику. Session cookie — секрет доступа; использовать HTTPS/HttpOnly/SameSite. Нельзя принимать game/side/token от публичного запроса, конвертировать legacy user_id или создавать URL, авторизующий произвольный матч.
3. В этом этапе НЕ создан публичный provisioning/login endpoint. Стендовый lab-login.php из evidence предназначен только для loopback disposable fixtures; категорически не выкладывать его в production www. Если оператор не может безопасно выдать server session/cookie, ограничиться изолированным стендом, а production оставить OFF.
4. Включить switches только после разрешения Lead. Игровой route whitelisted Recorder матча принимает либо настоящий trusted principal соответствующего участника, либо именно эту server binding с совпадающей ролью. Это исключение относится только к разрешённому тестовому матчу; оно НЕ устанавливает trusted principal и НЕ открывает полный replay.
5. Проверить независимо две сессии: legacy-only denied, попытка GET side denied, чужой game denied, скрытые refs отсутствуют, expire/revoke закрывают endpoint. Проверить journal GET под SELECT-only DB user на стенде. Production app Db по-прежнему должен иметь уже необходимые игровые права; новый read route не требует DML/DDL.
6. Кнопка журнала появляется только для разрешённой стороны/матча. Просмотр сам не вызывает игровой sync. Не делать журнал публичным, не включать для настоящих матчей при prototype-аутентификации.

Если позже появится настоящая авторизация, можно использовать integer trusted principal для получения стороны из participants; не назначать его из first/second или legacy session. В active journal нет admin bypass, server history или всех projections сразу.

## Отключение

Удалить binding/истечь expiry — немедленно запретить чтение со следующего запроса. Поставить journal_enabled=false или убрать ID — запретить endpoint/panel; на открытой странице read завершится 404. Отключение journal при продолжающейся записи возвращает прежний Stage4C уровень событий, который новый reader не обогащает задним числом. Повторное включение не восполняет пропуски.

Для полного безопасного завершения эксперимента отключить recording_enabled и journal_enabled атомарной заменой local config между запросами. Обычная игра продолжится прежним путём; историю не удалять, completeness не повышать. Как и Stage4C, не возобновлять Recorder в матче после игровых команд при OFF — создать новый тестовый матч. При выключении journal/удалении binding у продолжающего записываться тестового матча потребуется trusted principal; отключайте recording также, если его нет.

## Файлы и границы

Доставлять согласованный runtime Stage4C + изменения Stage4D из итогового отчёта. Не загружать local config, test tools/fixtures, lab-login, БД, sessions, datadir, storage, диагностические traps. Существующие postchecks схемы Stage4A.1 — только read-only сверка оператором; никаких новых миграций этого этапа.

Покрытие истории остаётся частичным. Complex nested combat сохраняет gap и event-only scope без нового Replay Step; полную причинную модель Coin/Degasca/всех continuations этот этап не обещает. Прямые внежурнальные battle saves (включая смену playmat) всё ещё ограничены guard Stage4B для выбранных Recorder матчей. Большой журнал пока хранит все загруженные строки в DOM; page limit ограничивает ответ, но массовый production rollout требует отдельной оценки памяти/нагрузки. Global event cursor не является публичным пользовательским ID или подписью, выдавать его только разрешённой сессии.
