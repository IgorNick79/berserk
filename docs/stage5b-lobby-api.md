# Stage 5B — API лобби и комнаты

Единственный principal — `AuthSession::id()` из BERSERKAUTH5A / berserk_authenticated_user_id. GET/POST account_id, first/second, role и invitation bindings его не устанавливают. AuthStore подтверждает существование account. HTTP session закрывается **до** работы с комнатой: конкурентность защищают InnoDB row locks, не session lock.

После login auth.php возвращает 303 на lobby.php. Страница auth.php?page=account остаётся совместимым защищённым экраном профиля с ссылкой в лобби. Прежние игровые entrypoints не подключены.

| Метод и URL | Назначение |
|---|---|
| GET lobby.php | HTML таблицы (данные в безопасном data-state, отрисовка внешним JS) |
| GET lobby.php?room=N | HTML своей комнаты, ожидание или started-заглушка |
| GET lobby.php?api=1 | JSON `{rooms:[...]}` активных комнат |
| GET lobby.php?api=1&room=N | JSON `{room:{...}}` текущему creator/candidate/accepted |
| POST lobby.php?api=1 | action=create, csrf; ответ `{room,url}` |
| POST lobby.php?api=1&room=N | action=open/accept/reject, csrf, version; для accept/reject ещё candidate_request_id |

Без api POST возвращает 303 на безопасный URL комнаты; api POST возвращает JSON 200. JSON ошибки: `{error: текст}`. Нет login — 401 (HTML GET гостя — 303 login); нет доступа — 403; неизвестная комната mutation — 404; stale version/request/state — 409; некорректный payload — 400/413; недопустимый метод — 405; DB/schema/неопределённый commit outcome — общий 503 без SQL деталей. Нельзя слепо повторять mutation после 503/обрыва — сначала GET persisted state.

В публичной таблице только room id, version, state, отображаемые имена, текст status и доступное действие. GameState JSON, колоды, игровые стороны, candidate_request_id и game_id в список не выдаются. В приватной комнате добавляются role, game_id, can_decide и candidate_request_id **только создателю при актуальной заявке**. Это идентификатор поколения заявки, не самостоятельный credential: права и CSRF проверяются отдельно.

Таблица показывает активные комнаты в порядке room id DESC. Значение «Игра идёт» включает подготовку будущего матча после accept. Завершённые определяются через games.status=game_over и исчезают из активной таблицы; started room сохраняется для ownership/истории. Архива и пагинации в этом этапе нет; при масштабировании потребуется отдельное ограничение/пагинация, не скрывающая собственные игры.

## Состояния и полномочия

| Состояние | Состав | Допустимые действия |
|---|---|---|
| open | creator, остальные nullable поля пусты, game отсутствует | creator читает; другой account явным POST open занимает слот |
| awaiting_confirmation | creator + candidate + новый server-generated request id; game отсутствует | creator accept/reject; candidate только читает ожидание; третий account denied |
| started | creator + accepted + game_id, candidate/request пусты | только creator/accepted читают заглушку; новых переходов комнаты нет |

Нажатие на строку (или Enter/Space на ней) отправляет POST open с актуальной версией и CSRF, затем открывает room URL. Отдельной кнопки «Присоединиться» нет. GET комнаты и polling никогда не занимают место. Повторный POST open текущего кандидата с актуальной версией только открывает ту же заявку; версия не меняется. Создатель не становится кандидатом при открытии своей комнаты.

Reject очищает candidate и request, увеличивает version, возвращает state=open. Следующий GET кандидата получает 403, polling переводит его в лобби. Старый GET остаётся запрещённым. Старый POST open с прежней версией получает 409; новая заявка возможна только по свежей строке и новому явному клику. Если принят другой account, отвергнутый не становится участником будущего матча.

Accept/reject под SELECT room FOR UPDATE проверяют creator, state, exact version и exact request id. Новый запрос того же кандидата после отказа получает новый request id; устаревшая кнопка не может принять его заново. На каждый переход увеличивается room.version; он независим от games.version/GameState.version. Переполнение версии отклоняется.

## Атомарное создание матча

Room service открывает **одну** mysqli transaction. После блокировки и проверок accept вызывает MatchCreator на **том же соединении**. Adapter требует активную transaction, создаёт games и начальный существующий GameState, назначает playmats существующим PlaymatCatalog и сохраняет согласованный JSON. Начальное состояние mode, mode/draft=null, PlayerState.side=null, пустой battle; SQL CAS version=1 соответствует результату GameRepository::create(). Затем room связывается с game_id, accepted устанавливается, candidate очищается, выполняется один commit.

Adapter не конструирует GameRepository (его constructor может выполнять runtime DDL), не вызывает Engine/PrepareProcessor/Recorder/automatic/timer и не начинает собственную transaction. Это минимальный мост к существующему формату начального матча, а не новый игровой lifecycle. Ошибка JSON UPDATE после INSERT games или ошибка UPDATE комнаты откатывает весь accept. Повтор запроса после успешного commit возвращает 409 и не создаёт ещё одну games.

Порядок locks: room → новая games → FK account/game locks; старые игровые пути не получают обратных room locks. FK удерживают account/game references, UNIQUE game_id запрещает неоднозначное владение. Нет cascade deletion. Commit с неизвестным результатом даёт общий отказ: последующий GET на новом HTTP/DB соединении определяет persisted room; повторный POST со старой версией не может создать дубликат.

После started отображается **«Игра создана. Игровой процесс будет подключён на следующем этапе»** и game_id. Нет redirect/link на first/second/index.php. Stage 5C должен использовать persisted ownership и серверное сопоставление creator→host, accepted→player; account ID не является side 1/2.

## Polling

Внешний lobby.js читает lobby/room примерно каждые 1500 ms, только один GET в полёте на странице. При сети/503 интервал растёт до 15 s, запросы имеют timeout 10 s. Ответы с room.version ниже последней принятой игнорируются. Mutation инвалидирует поколение уже запущенного GET; старый ответ не применяется после POST. POST тоже имеет timeout; автоматического повторения POST нет, восстановление только чтением. Успешное чтение сбрасывает backoff/уведомление сети.

Все данные отображаются через textContent/экранированные HTML attributes. Same-origin credentials, private/no-store, CSP external scripts/self connect, HttpOnly/SameSite auth-cookie сохраняются из Stage 5A. Public lobby polling не загружает модели игры, private room polling не возвращает GameState и ничего не пишет в БД. Для таблицы и автоматического обновления требуется JavaScript; no-JS выводит явное сообщение.

## Граница безопасности перед 5C

Stage 5B использует отдельную auth-cookie и только lobby_rooms ownership. Это не делает существующие публичные прототипные игровые маршруты безопасными. Они не изменены и не открываются из лобби; в частности, direct legacy URLs остаются известным риском, если включать 5B на окружении с доступным старым прототипом. До production activation необходимо отдельное решение Lead и gate Stage 5C для account-owned игр, всех gameplay/chat/history/replay endpoints и legacy/admin ID collisions. Приглашения Stage 4D.1 не открывают комнаты; авторизация аккаунта не выдаёт дополнительный principal в старой PHPSESSID.
