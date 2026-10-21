# Stage 5E — production readiness

**Решение: GO для review и read-only hosting audit с Project Lead; NO-GO для production SQL/установки/открытия сервиса.** Release preparation завершена, лабораторные проверки пройдены. Реальные FPM/HTTPS/schema/grants/inventory ещё не подтверждены. Этап5E не является разрешением выкладки.

## Точный release HEAD и состав

Проверенный исходный HEAD ветки codex/berserk-stage5d1-grid-timeout: **d59fc66970132f39c9c9d899984c191e8e1af6f7**. На старте рабочее дерево чистое. Stage5A822dacc89a32b7121fef4541f959f3d91ac2ee3f;5B46e9380941d8e1662cff3809fec07cdb998ac4b5;5C1b83c62a6197fe7dfaadebc48f645a72eef0a8a6;5D04316e18ab04cb5e0f4494830c55b6ece8910103;5D.1d59fc66970132f39c9c9d899984c191e8e1af6f7. Stage5E добавляет только документы, release tooling и evidence; runtime остаётся именно d59fc669. Hash commit пакета5E смотрится в git log/итоговом ответе. Не утверждается присутствие этих коммитов в main или production.

Единый **stage5e-release-manifest.json**:361 tracked source/runtime paths с SHA256 и размерами,27 delta относительно проверенной базы1e016374 (15 add /12 replace), отдельный статус actual_production=unverified у каждого пути. Это comparison baseline, не доказательство серверной версии. Список фактических MISSING/SAME/DIFFERENT должен вернуть Lead read-only скриптом; до этого нельзя объявлять файл новым/существующим на production. Все stage commits, SQL references, configuration parameters и dependency/preserve policy включены в manifest.

Local ZIP **evidence/stage5e/stage5e-runtime-d59fc66.zip**:360 deployable runtime files src/templates/www. Отслеживаемый .htaccess вынесен в operator/reference/www.htaccess: production PHP handler/HTTPS directives требуют сверки, не слепого overwrite. В operator/ — manifest,4 config templates,5 документов, hosting-audit script и7 read-only SQL. **Нет CREATE installer**, private configs, DB files, sessions, lab fixtures/runners, keys или storage. Результат roundtrip/entry verification и SHA256 — bundle-check.json. ZIP извлекать только в отдельное staging место, не прямо на сервер. Код не был загружен в production.

Custom src/Core/Autoloader и все tracked classes/templates/assets идут вместе; Composer/Node не требуются для runtime. PHP>=8.1 syntax и mysqli/mysqlnd/get_result,json,session,hash,password_hash/verify обязательны. Production CLI php8.4 известен со слов Lead; фактическая web version и PHP8.4 совместимость ещё требуют его проверки. Единственная локально выполненная runtime version — PHP8.1.9. Не использовать старый default php.

Untracked www/assets/cards, private configs, storage/games, session storage, DB/catalog data и production .htaccess не входят в заменяемый snapshot. Сохраняются отдельно; card artwork и catalog cards/decks/elements обязательны для реальной игры, optional card_synergy провайдер может не иметь. Каталог/карточные изображения production здесь не выгружались.

## Hosting audit: что известно и что не выполнено

Данные Lead: SSH user u39357; root /home/u39357/berserk.hat-trick.ru; public www; сайт https://berserk.hat-trick.ru; CLI /usr/bin/php8.4; default php старый; runtime DB user без TRIGGER. Повторно эти данные не запрашиваются.

Агент попытался выполнить только read-only SSH команду с BatchMode/StrictHostKeyChecking. Сетевое соединение к22 вернуло Permission denied, remote command не исполнялась. Поэтому SSH inventory, PHP/FPM versions/extensions, document root/vhost, MySQL/MariaDB version/mode, InnoDB/grants/schema/data aggregates, sessions/permissions и web proxy **не проверены реально**. Подготовлен короткий script tools/stage5e_hosting_audit.sh (bash -n PASS), инструкция запуска и компактный формат ответа Lead в stage5e-hosting-checks.md. Production SQL выполняет только Lead после отдельного согласования.

Особый HOLD: Lead сообщил временное отключение HTTPS guard в journal-access.php. Текущий серверный файл и точная причина HTTPS required недоступны для проверки. AuthSession доверяет только HTTPS=on/1, journal-access требует on; SERVER_PORT443/URL HTTPS не являются trusted signal. Требуется подтверждённый FPM HTTPS=on от доверенного ingress, обязательный guard восстановлен, allow_loopback_http=false, Secure/HttpOnly/Lax cookie и session continuity проверены. Произвольный X-Forwarded-Proto не принимать. Конкретные варианты конфигурации для провайдера и future restricted probe описаны в hosting-checks; ни probe, ни webserver/FPM config сейчас не создавались/менялись. Локальный TLS тест не доказательство production FPM.

## Дополнительный PHP8.4 security HOLD

Статический review выявил конкретное расхождение: AuthStore::register вызывает password_hash(PASSWORD_DEFAULT), AuthService::DUMMY_HASH фиксирован на bcrypt cost10. По [официальной документации PHP](https://www.php.net/manual/en/function.password-hash.php) с8.4 default bcrypt cost=12. Для новых зарегистрированных на8.4 аккаунтов проверка неправильного пароля будет cost12, а для неизвестного логина — cost10. Это потенциальный timing enumeration канал; одинаковый текст ошибки и limiter не делают стоимость одинаковой. Не заявляется подтверждённая эксплуатация на production, фактический web PHP пока неизвестен.

Локальный CLI-only synthetic probe tools/test_stage5e_auth_cost.php подтвердил dummy10 и отличие от explicit cost12; evidence/auth-cost-compatibility.json не содержит hash/password/session. Это не запуск PHP8.4 и не production timing test. Ранее48 security checks выполнялись8.1(default10), поэтому не закрывают эту target-specific проблему.

До установки требуется отдельное согласование минимальной Auth compatibility правки: единая согласованная hash policy/dummy cost с учётом разных сохранённых hash costs, затем проверки registration/login/unknown-user и limiter на целевом PHP8.4/FPM. Не понижать bcrypt cost или отключать limiter без решения Lead. В5E runtime не исправлялся; review ZIP содержит исходный d59fc669 с этим HOLD и не является deploy-ready. Этот пункт — дополнительная причина NO-GO, помимо недоступных hosting/HTTPS/schema gates.

## SQL, configs и данные

SQL deployment order — stage5e-sql-deployment-order.md. Existing games.version/types/InnoDB и FK/UNIQUE/CHECK должны быть совместимы; отсутствие version/partial schema требует STOP, а не runtime ALTER. Stage5A–5C DDL reviewed additive и без TRIGGER, runtime не делает CREATE/ALTER/DROP. После реального inventory Lead выбирает **отдельно** только отсутствующие таблицы. Существующие accounts/journal/chat/history не пересоздаются; отсутствие любой таблицы пока не заявляется фактом production. Общий ZIP намеренно исключает все CREATE-миграции, но manifest содержит точные ссылки и SHA источников read-only checks.

Новые auth.local.php параметры: auth db, отдельный lobby_db, private limiter_secret, allow_loopback_http=false. Game db.host принимает host:port, отдельное port поле старый Core Db не читает. Оба игровых соединения должны указывать на ту же проверенную схему. Session/FPM reference не заменяет действующий save_handler/path. Все templates placeholders — не готовые configs. Existing battle_history.local.php сохраняется, включая recording/journal/preview flags, целочисленные allowlists, catalog/admin/invitation settings. При отсутствии config дефолт off; запись всех боёв не включается.

Legacy host/player1/2 не мигрируют в accounts1/2. GameAccess требует started lobby_rooms mapping и совпадение SQL/JSON identities; числовое совпадение не даёт доступ. Старые матчи и журналы сохраняются, archive UI не добавлен. Никаких backfill, ID reassignment, удаления боёв/storage/games или восстановления backup поверх новых данных. Старый journal493 не исправлялся.

## Повторная pre-production проверка

Изолированные MariaDB10.8.4/InnoDB datadirs, проверка точного port/datadir, private loopback workers, synthetic catalog; runtime users без DDL/TRIGGER. Запущены заново в5E:

- Stage5C integration **71/71 HTTP** и **7/7 browser**: register→login→lobby→candidate→accept→существующий index/preparation/place/battle/terminal; System, Grid, Solomon, Sealed; два аккаунта, outsider/side spoof, post-accept transition, pending/timers, chat, live journal/replay, reload/relogin.
- Grid после5D.1: **168/168** исходных сценариев и отдельный runner **1000 frozen seeds +100 новых pool fixtures**, conservation/copy3/5, impossible prefix/error rollback. HTTP для обоих акторов подтверждает отсутствие persisted JSON/version mutation на impossible prefix, допустимый frozen input достигает view и полного battle lifecycle.
- Auth security **48/48**; Lobby concurrency **44/44** (HTTP races/rollback; актуальный browser выше, obsolete5B placeholder UI не запускается).
- Existing regression suites **11/11**; дополнительные Grid/booster/timer/view **4/4**, DB sections без skips. Дополнительный runner пишет историческую5D.1 evidence директорию: результаты этого повторного запуска скопированы в5E, прежняя5D.1 evidence восстановлена из Git; runtime не менялся.
- Все runtime PHP lint локальной8.1, Bash syntax и Python tooling AST; source/ZIP hash и archive roundtrip. Фактический счётчик PHP файлов — runtime-lint.json. Сценарии/outputs — evidence/stage5e. Архив не содержит тестовых данных.

Browser покрывает System и общий интерфейс; остальные режимы real HTTP. Некоторые browser шаги same-session fetch, не все click-only. Timer/HP/fault injections только в lab, это не естественная длительная production партия. Региcтрация/вход на production сейчас не выполнялись; старый prototype index может иметь GET side effects, агент его публичными запросами не открывал. Load/soak, реальные PKI/FPM/multi-host sessions и production catalog не проверены. Experimental Replay сохраняет прежний incomplete-metadata контракт, не обещает полноту старых событий.

## Следующий разрешаемый шаг

Готовы: manifest+review ZIP, short hosting checks, conditional SQL order,22-шаговый deployment checklist (action/expected/STOP) и rollback. **Следующий шаг — Lead выполняет read-only hosting/schema audit**, возвращает pass/fail и actual inventory без секретов. После устранения HTTPS bypass/trusted signal, подтверждения schema/grants/permissions/sessions и backup restore возможно отдельное решение о ручной установке. Сейчас это NO-GO для установки, а не новый Grid blocker.

Rollback — stage5e-rollback.md: сначала закрыть все endpoints, сохранить данные, выбрать runtime с account ownership guards; если безопасной версии нет, оставаться в maintenance. Нельзя публично вернуть prototype ?first/?second после появления account-owned games. Нет DROP или автоматического DB restore поверх новых матчей.

Работа5E остановлена для review Lead. Production SQL/config/deploy, push/merge не выполнялись; игровые механики/Engine/Grid/Recorder не менялись, storage/games сохранён.
