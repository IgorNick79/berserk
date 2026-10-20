# Live Battle Journal API — Stage 4D

`GET /battle-journal.php?game=ID&after=0&limit=100`

Только read-only отдельный route, без index.php, Engine, automatic progress, GameRepository/CommandJournalRepository, schema initialization или таймеров. Проверки доступа выполняются на каждом запросе, в том числе во время активного боя. Replay Preview Stage4C не изменён и этим доступом не открывается.

## Доступ

Нужны `journal_enabled=true` и точный integer ID в `journal_game_ids` server local config. Затем сервер определяет сторону:

- trusted integer `berserk_authenticated_user_id`, совпадающий с games.host_id/player_id;
- либо opaque `berserk_battle_journal_binding` в PHP session, который сервер заранее сопоставил с ровно одним game_id, side и expires_at в `journal_test_bindings`. Сессия и привязка создаются доверенным серверным оператором, не игровым HTTP route.

Нельзя выбирать сторону параметром. Единственные допустимые query keys: game, after, limit. first/second, side, role, player_id и другие ключи отвергаются. Legacy user_id/role без binding не дают доступа. Admin-only полного server view в live API нет.

## Ответ

```json
{
  "entries": [{"seq": 12, "kind": "event", "text": "Карта восстанавливает 2 HP."}],
  "cursor": 15,
  "has_more": false,
  "recording": {"available": true, "partial": true, "has_gaps": false}
}
```

`after` — последний подтверждённый cursor, от 0; `limit` от 1 до 200, применяется к исходным строкам событий. Backend читает только сохранённый host_json или player_json соответствующей стороны и committed journal rows, по event_seq. Не выбирает server payload или snapshot. До чтения фиксируется watermark метаданных; последующие события попадут в следующий запрос.

Cursor продвигается через скрытые и технические строки, даже если entries пуст. Поэтому нельзя заменять cursor номером последней видимой записи. `has_more=true` требует следующей страницы с возвращённым cursor; последовательность не пропускает видимые события. Повтор того же after идемпотентен; клиент дедуплицирует seq и подтверждает cursor только после проверки ответа/добавления entries. Global seq отражает продвижение общего источника, но не раскрывает payload скрытых событий; timestamps/root/command IDs не возвращаются.

`available=false` означает, что метаданные записи ещё отсутствуют. `available=true` и пустые entries означают отсутствие новых видимых строк, а не отсутствие истории. partial всегда true; has_gaps показывает незаписанные участки. Gap выдаётся только общим предупреждением без сохранённой причины, source, hidden ability или иных деталей. Наличие terminal snapshot не меняет partial.

Имена и refs заморожены в `_journal` сохранённой проекции при emit; endpoint не загружает текущие карты и не обогащает старые события после раскрытия. Старые проекции Stage4B/C без этого display envelope не превращаются в текст задним числом (кроме общего gap). Unknown refs/catalog и неподтверждённая видимость скрываются. Имена активных/пассивных способностей и статусы инстантов консервативно выдаются только владельцу; другая сторона получает лишь отдельно разрешённые фактические результаты.

## Ошибки и клиент

400 invalid_parameter/invalid_game/invalid_after/invalid_limit/invalid_cursor; 403 journal_access_denied; 404 journal_unavailable; 405 method_not_allowed; 503 journal_unavailable. Нет raw exception/SQL/server payload. Ответы JSON, private/no-store, nosniff. Session read_and_close, только SELECT, без записи session role.

Панель сохраняется вне заменяемых battle fragments. Использует уведомление существующего battle-sync `berserk:battle-updated`: при открытой панели читает новые страницы, не создавая собственного polling interval и не запрашивая gameplay endpoint ради журнала. На открытие/online выполняется read GET; timeout 8 секунд освобождает зависший запрос. При ошибке cursor не продвигается. Entries добавляются textContent. Если пользователь прокрутил вверх, scrollTop сохраняется; иначе следуем за последними строками. Все более ранние загруженные записи доступны прокруткой. При первом открытии постранично загружается сохранённая видимая история, без выдуманного backfill.
