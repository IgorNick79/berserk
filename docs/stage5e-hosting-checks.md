# Stage 5E — короткие read-only проверки хостинга

Production не изменялся. Из этой среды SSH к u39357@berserk.hat-trick.ru:22 завершился `Permission denied` при соединении, до исполнения remote command. Это не проверка пароля/ключа и не доказательство отсутствия SSH у Lead. Никаких удалённых файлов или SQL агент не менял. Ниже команды **для Lead** из локального checkout; перед передачей результатов убрать private paths/DB user/hash из output. Не присылать содержимое configs, cookies, sessions или phpinfo.

## 1. CLI, файлы и inventory — один запуск

В локальном Bash/WSL с уже настроенным SSH (не отключать проверку host key):

```bash
ssh u39357@berserk.hat-trick.ru 'bash -s' < tools/stage5e_hosting_audit.sh
```

Скрипт только читает: id, `/usr/bin/php8.4 -v/--ini`, необходимые extensions, CLI session settings, stat/lint приватных configs, SHA256 comparison27 изменяемых runtime paths и guard lines journal-access. Он не выводит configs, не запускает application bootstrap, не подключает БД, не создаёт файл на сервере. Если путь отсутствует/недоступен — отметить failure и не угадывать его.

Ожидание: user=u39357, PHP8.4 CLI с mysqli/mysqlnd/get_result, json/session/hash и password_hash/verify; src/templates/www доступны service user. `php` по умолчанию не использовать: его старая версия уже известна. SAME означает совпадение с release; DIFFERENT требует review; MISSING означает добавить только после разрешения. Это **фактическая классификация**, которую надо вписать в manifest после запуска. Полный snapshot361 путей/хэшей в stage5e-release-manifest.json;27 delta относительно1e016374 не выдается за inventory production. Карточные изображения www/assets/cards не входят в Git и должны сохраняться.

Правила прав: config и session storage вне document root, только нужному service user; code readable, service writer не должен изменять исходники. Без chmod777 и слепого chown. Существующие .htaccess/PHP handler/HTTPS directives сначала сравнить с reference, не перезаписывать Git версией.

## 2. СУБД — только Lead после согласования read-only SQL

Через штатную панель SQL или mysql client с приватным defaults file (путь/schema подставить локально, пароль не в argv):

```bash
mysql --defaults-extra-file=<private-client.cnf> --database=<application-schema>   < tools/migrations/20261021_release_preflight.sql
```

Файл должен быть уже доступен оператору; не копировать его на сервер на этом этапе. Можно выполнить те же SELECT/SHOW через панель. Этот read-only файл не вызывает runtime constructors/DDL. Не отправлять raw SHOW GRANTS, если сервер включает authentication hashes; передать только перечень привилегий и pass/fail. Проверить VERSION, strict SQL mode, InnoDB, columns/indexes/FK/CHECK, отсутствие неожиданных triggers; сравнить со stage5e-sql-deployment-order.md. Отсутствие TRIGGER privilege нормально: релиз его не требует. Для catalog dependencies дополнительно SHOW TABLES LIKE cards/decks/elements/card_synergy и SHOW COLUMNS этих таблиц. cards/decks/elements обязательны, synergy чтение опционально в существующем провайдере. Данные карт/дек не выгружать в отчёт.

Отдельно по каждому runtime DB user сверить разрешения (auth/lobby/game), а не только admin. Existing games/journal/history должны сохраняться. Для сверки существующих данных достаточно агрегатов COUNT games по status, COUNT history/journal и legacy комнатного mapping, без game JSON или логинов. Запросы к lobby_rooms выполнять только если она уже есть; до установки её отсутствие ожидаемо. Snapshot backup и подсчёты до/после должен фиксировать Lead, фактических production чисел у Codex нет.

## 3. Web PHP/FPM и HTTPS — сейчас НЕ ПРОВЕРЕНО

CLI не доказывает web version, document root, handler, HTTPS flag или доступ к sessions. Через существующую закрытую панель диагностики/конфиг vhost провайдер подтверждает:

- PHP_VERSION, PHP_SAPI, loaded ini и mysqli/mysqlnd/get_result именно web worker.
- Document root ровно /home/u39357/berserk.hat-trick.ru/www, не корень проекта; src/config/tools/docs/storage закрыты. Проверить также alias/rewrites и .htaccess PHP handler.
- session.save_handler/path и доступность для реального service user, общий storage/locking между workers; число backend и sticky/shared-session политика.
- Для **реального внешнего HTTPS** запроса значения `$_SERVER['HTTPS']` и SERVER_PORT; граница TLS termination и доверенные proxy/FastCGI peers. Не достаточно SERVER_PORT443 или адресной строки браузера.

Новый диагностический endpoint — изменение production, поэтому в Stage5E его **не создавать**. Если существующего безопасного средства нет, запросить провайдеру подтверждение этих полей. При будущей разрешённой установке допустим кратковременный IP-restricted probe под maintenance, без phpinfo, credentials, cookies или полного $_SERVER dump; удалить сразу и убедиться в404. Такой probe ещё не выполнялся.

AuthSession принимает только HTTPS=on или1; journal-access требует именноon. Lead сообщил прежний temporary bypass HTTPS в journal-access; актуальный серверный файл и точная причина rejection пока не получены. Требуемый общий результат **HTTPS=on**, а новая release версия восстанавливает guard. Не открывать сервис с temporary bypass.

Конкретное задание провайдеру:

1. Установить реальную схему ingress→proxy→FPM. Backend недоступен произвольным клиентам; ingress удаляет/перезаписывает клиентский X-Forwarded-Proto. REMOTE_ADDR должен иметь согласованную семантику для auth rate limit (общий proxy IP объединит bucket20/15min).
2. При прямом nginx TLS listener443: в **его** FastCGI location после include стандартных params установить `fastcgi_param HTTPS on;`. В публичном HTTP listener80 делать redirect на HTTPS, не запускать PHP с этим сигналом. Это пример для подтверждённого nginx, не команда к неизвестному серверу.
3. При TLS termination перед nginx/Apache: HTTPS=on выставляется только по проверенному сигналу конкретного доверенного ingress на закрытом backend. Конкретный allowlist и правило задаёт администратор хостинга. Не использовать `$http_x_forwarded_proto` без проверки источника и не ставить безусловный HTTPS=on на доступном по HTTP vhost. Для Apache/FPM провайдер проверяет передачу TLS environment, а не меняет AuthSession на чтение произвольных headers.
4. Повторить внешний HTTPS/HTTP контроль и поддельный forwarded header. Точный безопасный результат зафиксировать до восстановления обязательного journal guard и включения Auth. Если trusted signal отсутствует — STOP, конфигурацию должен исправить хостинг. allow_loopback_http=false в production.

## 4. Cookies и редиректы — будущий smoke под разрешённой установкой

Не делать login/register в production сейчас. После установки через HTTPS проверить BERSERKAUTH5A: Secure, HttpOnly, SameSite=Lax, Path=/; login меняет SID, старый SID не авторизует, logout удаляет principal, reload/relogin работают между workers. PHPSESSID приглашения не является auth principal. Обычный HTTP и поддельный X-Forwarded-Proto не дают авторизацию. DevTools только статус pass/fail, не публиковать Set-Cookie/session IDs.

Ожидаемые303: register→login, login→lobby, preparation PRG; post-accept lobby JS→index.php?game=ID. Нет ?first/?second в игровом переходе. Отклонённый кандидат получает403 и возврат в lobby. Существующий prototype index по GET может создавать/двигать игры, поэтому агент не проверял его публичными HTTP-запросами; сейчас не обходить старый UI ради проверки TLS.

## Ответ Lead

Достаточно прислать: PHP CLI/web versions и extensions PASS/FAIL; confirmed webroot; SQL version/mode/engines и таблицы absent/compatible/incompatible; привилегии без секретов; фактические SAME/DIFFERENT/MISSING для27 путей; HTTPS/server-port/proxy mapping; sessions/permissions PASS/FAIL. Не нужно повторно сообщать уже известные user/path/site/php8.4 или присылать приватные конфиги.

PHP8.4 auth cost gate: release dummy hash cost10, default bcrypt cost12 по php.net. До установки требуется отдельный Auth compatibility review и target-version проверка. Безопасно вывести только константу: `/usr/bin/php8.4 -r 'echo PASSWORD_BCRYPT_DEFAULT_COST, PHP_EOL;'`. Это не исправляет mismatch и не заменяет проверку FPM.
