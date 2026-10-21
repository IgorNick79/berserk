# Ручная установка SQL Stage 5A

Production SQL агент не выполнял. Применяет Project Lead после проверки базы и резервной копии. Никакого автоматического installer или runtime migration нет.

1. Подтвердить checkout/серверную версию, имя схемы и отсутствие активной установки Stage 5A. Использовать приватный client config, не пароль в argv/log. Не подключать тестовый runner к production.
2. Выполнить read-only `tools/migrations/20261021_auth_preflight.sql`. Проверить games.ind/host_id/player_id: signed INT, и соответствие утверждённой базе. Проверить реальную версию PHP >=8.1 с mysqli/mysqlnd, MariaDB/MySQL поддержку enforced CHECK, InnoDB, strict SQL mode. Миграция реально проверена на MariaDB 10.8.4; другую версию отдельно проверить. Если accounts/auth_login_attempts уже существуют — остановиться, сверить структуру и состояние; не накладывать миграцию повторно.
3. Отдельным DDL пользователем вручную применить `tools/migrations/20261021_auth.sql` в правильной схеме. Это два CREATE, без IF NOT EXISTS, DROP, TRIGGER, UPDATE games и связей с legacy участниками. DDL MySQL не атомарен: если второй CREATE не удался, auth не включать; исследовать частичную установку, не удалять таблицы автоматически.
4. Выполнить read-only `tools/migrations/20261021_auth_postchecks.sql`: обе таблицы InnoDB, PK, UNIQUE login_key, ascii_bin, password_hash VARCHAR(255), CHECK constraints, invalid_accounts=0. Проверить исходные игры не изменились. Создание auth ID начинается обычным AUTO_INCREMENT; 1/2 не резервируются.
5. Создать отдельного runtime DB пользователя с SELECT,INSERT на accounts и SELECT,INSERT,UPDATE на auth_login_attempts. Без доступа к games, TRIGGER, CREATE/ALTER/DROP. Подставить реальные schema/user/host вручную средствами администратора; не копировать тестовый пустой пароль. Runtime проверяет engine, обязательные типы/nullability/collation и ключи только чтением; несовместимая/отсутствующая схема даёт 503.
6. Скопировать config/auth.example.php в приватный config/auth.local.php. Заполнить отдельное DB соединение и уникальный случайный limiter_secret (не менее 32 байт). Файл исключён из Git; ограничить чтение service user. Настроить HTTPS и session storage, убедиться в display_errors=Off и отсутствии логирования POST/password. Production allow_loopback_http=false.
7. Развернуть только утверждённые auth файлы, проверить register/login/logout и cookie через TLS. Входная страница auth.php открывается отдельно; index.php не меняется и лобби не создаётся.

Отключение: убрать доступ к auth endpoint/файлам и сохранить БД/историю. Не удалять таблицы при rollback. Весь игровой runtime и его старые schema mutations остаются вне Stage 5A.

## Воспроизведение лаборатории

Нужны Python 3, PHP 8.1 с mysqli/mysqlnd и MariaDB 10.8 binaries. На Windows создать новый datadir в `<checkout>/tmp/auth-lab/data` посредством `mariadb-install-db.exe --datadir=... --port=33551`. Не использовать рабочий/production datadir.

Запустить из checkout:

```powershell
python tools/test_auth_http.py --php C:/OSPanel/modules/php/PHP_8.1/php.exe --db-bin C:/OSPanel/modules/database/MariaDB-10.8-Win10/bin --openssl C:/OSPanel/modules/http/Apache_2.2-PHP_5.2-5.4/bin/openssl.exe
```

Runner требует свободные loopback порты 33551–33558 и отсутствие config/auth.local.php, создаёт уникальную тестовую схему/пользователя на каждый прогон, сам запускает/останавливает процессы. Никакие существующие таблицы не удаляет; остающиеся лабораторные данные находятся только в tmp. На auth connection нет TRIGGER/DDL/прав games. HTTP workers независимы и разделяют InnoDB/session storage, проверяются настоящие конкурирующие запросы. TLS proxy и тестовый HTTPS server-variable adapter существуют исключительно в tmp: это проверка cookie через реальный TLS с самоподписанным сертификатом, не проверка production reverse proxy, PKI или FPM конфигурации.

Runner включает SQL setup/fault injection только внутри проверенного лабораторного datadir: изменение/восстановление unique index, временное переименование accounts. Эти действия не входят в runtime или production migration. После завершения приватная auth.local.php удаляется, процессы останавливаются. Не публиковать tmp datadir, session files, ключ TLS и limiter secret. Публикуемое evidence — только результаты проверок и postchecks без private artifacts.
