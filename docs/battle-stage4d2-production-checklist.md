# Stage 4D.2 — production checklist для Project Lead

Команды ниже — инструкция для оператора, агент их на production не выполнял. Доступ только к одному новому тестовому матчу. Прежде чем изменять сервер, закончить read-only preflight. Пакет рассчитан на уже установленный Stage 4C (139979d) с пятью history tables; для другого baseline сверить зависимости, не накладывать вслепую.

## 1. Переменные shell

Примеры для Linux/SSH; заменить значения реальными путями/именами. Здесь нет паролей и invitation secrets. CLI и web должны работать с одним checkout и одним приватным ledger. Все CLI issue/revoke выполнять от PHP service user либо другого пользователя с корректными совместными ACL; режим 0600 требует осмысленного владения.

```sh
APP=/absolute/path/to/berserk
PHP=/absolute/path/to/php
WEB_USER=actual_php_service_user
ACCESS_DIR=/absolute/private/path/berserk-journal
SESSION_DIR=/actual/php/session/path
MYSQL_CNF=/private/existing/read-only-mysql-client.cnf
DB_NAME=actual_database_name
BASE=https://actual-host.example
TLS_HOST=actual-host.example
cd "$APP"
```

Не использовать `/tmp`, публичный storage, каталоги других vhost/alias или checkout для ACCESS_DIR. Не выполнять команды с шаблонными значениями. MYSQL_CNF — уже существующий приватный файл read-only SQL account; не передавать пароль в аргументах, env или shell history.

## 2. Read-only preflight: ничего не создавать и не включать

На компьютере оператора проверить SSH: `command -v ssh`; затем `ssh -o BatchMode=yes -T user@actual-host 'id -u; command -v php; php -v'`. Ошибка BatchMode не доказывает отсутствие SSH: проверить разрешённый интерактивный способ входа вручную. Не включать password/session recording.

На сервере:

```sh
"$PHP" -v
"$PHP" --ini
"$PHP" -r 'echo PHP_VERSION, " ", PHP_SAPI, PHP_EOL; foreach (["mysqli","mbstring","json"] as $e) { echo $e, "=", extension_loaded($e)?"yes":"NO", PHP_EOL; } echo "sessions=", ini_get("session.save_path"), PHP_EOL; echo "handler=", ini_get("session.save_handler"), PHP_EOL;'
mysql --defaults-extra-file="$MYSQL_CNF" --database="$DB_NAME" --batch -e 'SELECT DATABASE(),CURRENT_USER(),VERSION(),@@sql_mode;'
mysql --defaults-extra-file="$MYSQL_CNF" --database="$DB_NAME" --batch < tools/migrations/20261020_battle_history_postchecks.sql
openssl s_client -connect "$TLS_HOST:443" -servername "$TLS_HOST" -verify_hostname "$TLS_HOST" -verify_return_error </dev/null
```

PHP >=8.1; проверено 8.1.9/MariaDB 10.8.4. CLI php.ini не равен FPM ini: оператор отдельно сверяет версию FPM/Apache PHP, pool user, session.save_path/save_handler, session.gc_maxlifetime, session.auto_start=0, OPcache и права. Например, установленный php-fpm binary `-v`, `-i` с выводом только нужных ini параметров, чтение соответствующего pool/vhost config. Для установленного FPM binary (путь выбирает оператор) можно проверить ограниченный вывод:

```sh
FPM=/absolute/path/to/php-fpm
"$FPM" -v
"$FPM" -i | awk '/Loaded Configuration File|session.save_path|session.save_handler|session.auto_start|session.gc_maxlifetime|opcache.validate_timestamps|opcache.revalidate_freq/'
```

Pool overrides ещё сверяются чтением pool config; CLI/FPM -i не доказывает значения конкретного HTTP request. Не публиковать полный phpinfo/-i, env или конфиги с секретами. Redis/custom session handlers отдельно проверить; лаборатория использовала files.

SQL postchecks содержат SELECT/SHOW, не CREATE/ALTER/UPDATE. Сверить SHOW CREATE, поля, InnoDB, JSON, FKs, CHECK и unique indexes всех пяти таблиц: battle_history_matches/batches/actions/events/steps, а также games и battle_command_journal, с утверждённой миграцией Stage 4A. **Миграцию CREATE не запускать**. Нулевые trigger metadata без TRIGGER privilege не являются доказательством отсутствия триггеров; оператор сверяет существующую схему своими read-only административными средствами. Новых триггеров не требуется. Несоответствие схемы/SQL engine — STOP.

TLS: openssl проверяет цепочку и hostname без вызова PHP. Не использовать index.php/корень сайта для read-only preflight: GameRepository/CommandJournal имеют существующие schema guards, а игровые GET могут запускать automatic progress. Дополнительно локально просмотреть vhost/FPM конфигурацию: document root строго APP/www, корректная передача HTTPS=on именно при защищённом входе. Код проверяет точную строку `$_SERVER['HTTPS']==='on'`; CLI, REQUEST_SCHEME, port 443 и один X-Forwarded-Proto это не подтверждают. За proxy заголовок должен обрабатываться только доверенной конфигурацией ingress, внешний клиент не может назначать HTTPS. Не включать local_http exception. Если фактическое значение HTTPS на PHP request нельзя подтвердить существующей закрытой диагностикой, отметить preflight «не подтверждено»; окончательно проверить форму на ограниченном включении. Не добавлять публичный phpinfo/debug endpoint. Read-only OFF-проверка сама по себе этого не доказывает.

Проверки прав, без записи:

```sh
stat -c '%a %U:%G %n' "$APP/config" "$APP/config/battle_history.local.php" "$SESSION_DIR"
namei -l "$APP/config/battle_history.local.php"
namei -l "$SESSION_DIR"
test -d "$ACCESS_DIR" && stat -c '%a %U:%G %n' "$ACCESS_DIR"
sudo -u "$WEB_USER" test -r "$APP/config/battle_history.local.php"
sudo -u "$WEB_USER" test -w "$SESSION_DIR"
```

Если local config или ACCESS_DIR ещё отсутствует, отсутствие ожидаемо: проверить stat/namei существующего родительского каталога и его write/search права у оператора/PHP user, без mkdir в preflight. test -w подтверждает Unix доступ, но не доказывает корректность rename/flock/SELinux/quota — до активации оператор отдельно проверяет на выделенном приватном каталоге. Нужен локальный диск с flock и atomic rename, один узел; NFS/разные несогласованные nodes — STOP. Приватные config/ledger/invitation/session files недоступны посторонним Unix users и любому HTTP vhost. Проверить отсутствие web aliases на приватные пути по конфигу; один ответ 404 не является полной проверкой ACL.

## 3. Загрузка с OFF и приватная конфигурация

Сделать защищённую резервную копию runtime и существующего local config вне webroot. Сверить `battle-stage4d2-files.txt` и SHA256SUMS внутри архива. Загружать только указанные 24 runtime/reference файла, включая CLI вне www. Приложенные SQL/документы — для оператора, не публичная раздача. Не загружать source tree лаборатории, тесты, DB, sessions или evidence archive. Не перезаписывать config/db.php. Активный config/battle_history.local.php в пакет **не входит**.

Образец `docs/examples/battle_history.local.php.example` возвращает только реальные config keys и по умолчанию всё выключает. Создать/обновить активный local config вручную, сохранив утверждённый frozen catalog. Если local config уже включает функции, сама загрузка runtime их не выключает: сначала явно выставить OFF. Смена config атомарная; проверить обновление OPcache согласно политике хостинга. Не использовать CLI opcache_reset как доказательство сброса FPM cache.

```sh
"$PHP" -l config/battle_history.local.php
"$PHP" -r '$c=require "config/battle_history.local.php"; foreach (["recording_enabled","journal_enabled","journal_access_enabled","preview_enabled","journal_access_local_http"] as $k) { if (($c[$k]??false)!==false) { fwrite(STDERR,"NOT OFF: ".$k.PHP_EOL); exit(1); } } echo "OFF verified",PHP_EOL;'
curl --silent --show-error --output /dev/null --write-out '%{http_code}
' "$BASE/journal-access.php"
```

Ожидается 404 до включения. Новый ledger не создавать автоматически при загрузке. Только на шаге ручной подготовки создать приватный каталог от service user с umask077, mode0700/эквивалентными ACL (например `sudo -u "$WEB_USER" mkdir -m 700 -- "$ACCESS_DIR"` при заранее проверенном пути и разрешённом родителе). Config и секретные файлы 0600, владелец согласован с FPM/CLI; не делать world-writable и не выдавать права всей shared hosting группе. Отключить POST body capture/APM/request dumps на journal-access.php. Обычный access log не должен содержать тело запроса. HTTPS и session cookie обязательны.

Параметры режима:

| Назначение | Реальный параметр/условие |
|---|---|
| Один матч | `$testGameId` в образце — локальная PHP переменная; вписать положительный integer. Она заполняет `record_game_ids` и `journal_game_ids` |
| Recorder | `recording_enabled=true` и точный ID в `record_game_ids` |
| Live Journal | `journal_enabled=true` и точный ID в `journal_game_ids` |
| Приглашения | `journal_access_enabled=true`, `journal_access_directory` — существующий приватный абсолютный путь |
| TLS | `journal_access_local_http=false`, PHP HTTPS=on |
| Статические bindings | `journal_test_bindings=[]` |
| TTL | **Не config key**: четвёртый аргумент CLI после issue/GAME/side, 1..86400 секунд; expires_at записывается сервером в ledger |
| game_over | Отдельного config key нет: тот же journal allowlist, активная PHP session, неотозванный binding, expires_at > time(); recording_enabled для чтения не нужен |
| Полный replay | `preview_enabled=false`, `preview_game_ids=[]`, `admin_user_ids=[]`; invitation не задаёт trusted principal |
| Каталог | `catalog` — реальные замороженные определения по ukid/revision. Пустой образец не обещает полноценный текст карточных событий; неизвестные определения скрываются/gap |

## 4. Один матч: точный порядок по существующему коду

1. После загрузки подтвердить OFF. Создать один **новый** тестовый матч прототипом, записать его ID; не расставлять карты и не подтверждать расстановку. Первая/вторая роль пока не аутентификация. Закрыть исходную прототипную вкладку.
2. Под паузой ручного теста вписать integer ID в оба allowlists до расстановки. Никаких `*`, строковых IDs, массовых списков. Обычные матчи вне списков не затрагиваются.
3. Выставить для этого ID recording_enabled=true, journal_enabled=true, journal_access_enabled=true; preview остаётся OFF. Без игровых команд само включение не записывает бой. **Это должно быть до issue/redeem:** CLI issue и HTTP consume проверяют records() AND journals(). Поэтому порядок «приглашения при Recorder OFF → подключение → включение записи» текущим кодом не поддерживается. Не обходить проверку и не создавать фиктивный ключ staging. Все игровые действия начинаются только после обеих привязок; повторно переключать Recorder между подключением и расстановкой не нужно.
4. Выдать два приглашения по разделу 5. В двух независимых браузерных профилях открыть `$BASE/journal-access.php`, ввести только свой секрет. POST CSRF + session ID regeneration, cookie Secure/HttpOnly/SameSite Strict; URL после входа содержит только роль и game, секрета там нет. При форме 403 HTTPS проверить vhost; не разрешать HTTP.
5. Сверить host→first, player→second и game ID. Попытка поменять first/second в адресе для выбранного матча должна дать 403. Journal foreign game — 403/404; replay без trusted principal — 401. Обычные prototype matches по-прежнему имеют прежнюю политику доступа: приглашение не создаёт общую авторизацию.
6. Расставить карты и подтвердить с двух сторон. Провести реальное движение, удар с бросками/ACK, доступное правилами лечение, конец хода; если тестовый набор не содержит лечения — заранее выбрать подходящий набор, не редактировать бой SQL. Открыть «Логи» с обеих сторон. Проверить автообновление без reload, порядок, owner-only/скрытые события, gaps, прокрутку, закрытие/открытие, reconnect и сохранение панели при sync.
7. Завершить матч; в той же сессии открыть сохранённый журнал. Не обновлять проекции из текущего состояния. Incomplete/gaps остаются incomplete. Для доступа после game_over оставить journal_enabled и invitation mode включёнными, тот же game ID в journal_game_ids и неистёкшие grants; запись можно выключить после terminal. Новую выдачу CLI текущий код разрешает лишь при records+ journals, поэтому лучше выдать достаточный TTL заранее, максимум24ч.
8. Зафиксировать результат без секретов и завершить эксперимент по rollback. После потери сессии выдать новое приглашение, старое не использовать; browser restore cookies может сохранить старый доступ до expiry/revoke.

## 5. SSH/CLI: выдача, статус, отзыв и expiry

Ниже команды только для Project Lead после включения **одного** allowlisted ID. GAME_ID — реальное число; TTL_SECONDS — срок с момента issue, общий для приглашения и последующей сессии, не sliding timeout. Путь выдачи должен быть новым файлом **непосредственно в ACCESS_DIR**.

```sh
GAME_ID=12345  # заменить реальным новым ID, уже включённым в обоих allowlists
TTL_SECONDS=7200
sudo -u "$WEB_USER" "$PHP" tools/journal_access.php issue "$GAME_ID" host "$TTL_SECONDS" "$ACCESS_DIR/host-invitation.json"
sudo -u "$WEB_USER" "$PHP" tools/journal_access.php issue "$GAME_ID" player "$TTL_SECONDS" "$ACCESS_DIR/player-invitation.json"
```

Успех: exit0 и `Invitation saved privately; no credential printed`. Проверить mode/owner обоих файлов `stat`; не использовать cat, grep secret, shell command substitution с секретом, terminal recording, общий clipboard, чат боя или URL. Передать каждый приватный файл только соответствующему участнику отдельным защищённым каналом (например защищённая передача файла оператором); участник читает secret локально в приватном редакторе, вводит в password поле. Файлы не публиковать в webroot. Ни одной production выдачи в ходе Stage4D.2 не делалось.

Проверка файла и получение **несекретного** grant ID, выполняется с правами service user:

```sh
HOST_GRANT_ID=$(sudo -u "$WEB_USER" "$PHP" -r '$v=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if (!preg_match("/^[a-f0-9]{32}$/D",$v["id"]??"") || !preg_match("/^[a-f0-9]{64}$/D",$v["secret"]??"")) exit(1); echo $v["id"];' "$ACCESS_DIR/host-invitation.json")
PLAYER_GRANT_ID=$(sudo -u "$WEB_USER" "$PHP" -r '$v=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if (!preg_match("/^[a-f0-9]{32}$/D",$v["id"]??"") || !preg_match("/^[a-f0-9]{64}$/D",$v["secret"]??"")) exit(1); echo $v["id"];' "$ACCESS_DIR/player-invitation.json")
```

Сохранить оба grant ID до удаления файлов выдачи. Статус/expiry — несуществующих CLI подкоманд status/expire нет. Только read-only просмотр отфильтрованных полей ledger:

```sh
sudo -u "$WEB_USER" "$PHP" -r '$p=$argv[1]; $l=fopen($p."/journal-access.lock","r"); if (!$l || !flock($l,LOCK_SH)) exit(1); try { $d=json_decode(file_get_contents($p."/journal-access.json"),true,512,JSON_THROW_ON_ERROR); $r=$d[$argv[2]]??null; if (!$r) exit(2); echo json_encode(["game_id"=>$r["game_id"],"side"=>$r["side"],"used"=>$r["used"],"revoked"=>$r["revoked"],"expires_at_utc"=>gmdate("c",$r["expires_at"]),"expired"=>time()>=$r["expires_at"]],JSON_THROW_ON_ERROR),PHP_EOL; } finally { flock($l,LOCK_UN); fclose($l); }' "$ACCESS_DIR" "$HOST_GRANT_ID"
```

Повторить для PLAYER_GRANT_ID. Никакие invite_hash/binding_hash/secret не печатаются. До входа used=false, после единственного входа used=true; серверные часы синхронизированы. При time>=expires_at доступ прекращается; из той же браузерной сессии запрос журналу получает403. Для проверки на production не менять часы/ledger и не создавать лишних приглашений: дождаться срока уже согласованного grant. После game_over срок не продлевается. Тесты expiry уже выполнены в изолированной лаборатории.

Отзыв (работает до и после погашения; invitation mode пока должен быть включён):

```sh
sudo -u "$WEB_USER" "$PHP" tools/journal_access.php revoke "$HOST_GRANT_ID"
sudo -u "$WEB_USER" "$PHP" tools/journal_access.php revoke "$PLAYER_GRANT_ID"
```

Ожидается exit0, `Revoked`; metadata revoked=true, следующий journal/защищённый игровой запрос даёт403. Уже показанные строки остаются в памяти браузера. После передачи/погашения убрать приватные файлы выдачи по политике оператора, не удалять ledger на ходу и не редактировать consumed обратно в false. Ошибка сохранения сессии может сжечь приглашение: отозвать grant и выпустить новое, не возвращать прежнее в оборот.

## 6. Быстрое отключение / rollback

1. Попросить обоих тестировщиков прекратить команды. Атомарно выставить recording_enabled=false, journal_enabled=false, **пока оставить journal_access_enabled=true** для CLI revoke. preview_enabled=false, journal_test_bindings=[]. Сохранить IDs в обоих allowlists: protects() опирается на них даже при OFF, удаление IDs вернуло бы небезопасную prototype роль.
2. CLI revoke обоих grant IDs, затем атомарно journal_access_enabled=false; повторно проверить OFF и OPcache. Если уже выключили access раньше, CLI revoke вернёт disabled: не открывать journal/Recorder; временно оставить только journal_access_enabled=true при обоих recording/journal=false, выполнить revoke, снова false. Погашение при этих OFF состояниях не разрешено.
3. Убедиться: journal endpoint и invitation route404, полный replay по-прежнему закрыт. Выбранный тестовый game route403 без trusted principal; это ожидаемая безопасная остановка, не повод убирать ID. Обычный существующий матч вне allowlists открывается и обрабатывает штатные действия, лог-кнопка недоступна, новые history rows для него не появляются (проверять по read-only SQL).
4. Если ошибка истории откатывает тестовую команду, не повторять массово, сохранить время/game/command ID и обезличенную ошибку, остановить эксперимент. Никаких SQL repair, удаления таблиц/строк, изменения completeness или обхода обязательной транзакции истории. История и игровые данные сохраняются.
5. Не возобновлять запись этого матча после пропущенных команд. Для повторного запуска новый матч и новые grants. Если нужен возврат исходников — согласованный целостный предыдущий runtime с OFF config, а не отдельный index.php. Старый Stage4D runtime не знает protects() при OFF: перед откатом ограничить HTTP доступ именно к тестовому матчу на сервере либо остановить этот тест; обычные матчи сохранять. Не откатывать на старый код с активными IDs без оценки его политики доступа.

Фактическая совместимость production остаётся неподтверждённой, пока Project Lead не заполнит preflight/приёмку. Отсутствие SSH/CLI, доверенного HTTPS, приватных ACL или schema match — не активировать.
