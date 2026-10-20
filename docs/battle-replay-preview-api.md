# Experimental finished replay API

Один отдельный route, только GET. Он не подключает index.php и игровой AJAX sync.

| URL | Результат |
|---|---|
| `/replay.php?game=ID` | HTML первого сохранённого кадра |
| `/replay.php?game=ID&step=N` | HTML сохранённого кадра N |
| `/replay.php?game=ID&api=metadata` | game_id, participants IDs, status, experimental, completeness, recording_status, step_count, gaps |
| `/replay.php?game=ID&api=steps` | упорядоченные step_seq/type/action_id/event watermark/initial-final flags без state; gaps |
| `/replay.php?game=ID&api=step&step=N` | выбранный descriptor и сохранённый `state` ReplayState v1 |

Все запросы требуют доверенного integer session principal `berserk_authenticated_user_id`, явного preview allowlist, участия в games.host_id/player_id либо серверного admin allowlist. `user_id`, GET first/second, POST роли не дают полномочий. Admin тоже не обходит `game_over` и confirmed final proof. Разрешение всегда проверяется заново для запрошенного game ID, перед чтением snapshots.

Возвращаются только сохранённые значения. Full state не пересчитывается из GameState, правил, будущих кадров или текущего каталога. Читатель проверяет SHA-256 exact persisted JSON envelope всех кадров, включая public/host/player projections. Partial preview не меняет старый контракт completedServerHistory и не повышает completeness.

`gaps`: массив `{event_seq, type:"recording_gap", detail:{reason:...}}`. Событие маркирует неизвестный участок; нельзя интерполировать его по следующему state. UI сопоставляет watermark кадров и gap event_seq, показывает границы участка и предупреждение partial для всей истории. Неподдержанные команды могут оставить только terminal, что не считается полным покрытием.

Ошибки JSON `{error:code}`: 400 invalid game/step/api; 401 authentication_required; 403 forbidden; 404 replay_not_available/step_not_found; 405 method_not_allowed; 409 replay_requires_game_over/confirmed_final_snapshot_required/snapshot_integrity_failure; 503 replay_unavailable. На ошибке checksum state не возвращается. Для API/HTML одинаковая policy. Ответы `Cache-Control: private, no-store`, CSP запрещает scripts/формы/фрейминг, session открывается read_and_close.

Чтение делает только SELECT; не создаёт GameRepository/CommandJournalRepository, не запускает schema initialization, Engine.apply, automatic progress, таймеры, pending или SQL writes. Для минимального этапа все snapshots проверяются в памяти, даже если запрошен один. Не публиковать как API больших production историй без следующего этапа лимитов/пагинации.
