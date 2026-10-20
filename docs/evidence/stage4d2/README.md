# Stage4D.2 — повторные release checks

checks.json: исходники против Stage4D.1 SHA, lint8, JS2, archive integrity, отсутствие известных plaintext bindings/private files в предыдущем evidence, пять SQL-пар, lint всего release runtime, OFF config и синтаксис документационных команд. smoke.json: реальные HTTP OFF ответы и запрет CLI issue без записи файла; стенд без config/db.php и БД, localhost33502.

Контрактный тест tools/test_journal_invitations.php повторно выполнен в source-only копии с новым приватным unit-каталогом вне app, BERSERK_STAGE4D1_ISOLATED=1. PASS: одноразовость, точный scope, revoke, выключенный scope, hash-only ledger, default OFF, отсутствие replay principal. Только искусственные grants в локальном изолированном реестре; в пакет не включены.

Stage4D.1 commit b266c3e14d4fb5cc737153a42f93ed02153554bc. Производственные команды не исполнялись. Полные DB/UI прогоны Stage4D.1 не повторялись и не выдаются за новые. Причина старого SQL mismatch остаётся неизвестной, см. release review. Stage4D.2 не меняет runtime.
