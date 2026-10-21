# Stage 5A: auth API и сессии

Все страницы находятся в отдельном `www/auth.php`. Они не подключают index.php, игровой Db/config/db.php, Engine или repositories игры. Лобби отсутствует.

| Метод | URL | Контракт |
|---|---|---|
| GET | auth.php?page=login | Форма входа и session CSRF token |
| GET | auth.php?page=register | Форма регистрации |
| POST | auth.php?page=register | csrf, login, password; 303 на login после создания; 422 при валидации/занятом логине |
| POST | auth.php?page=login | csrf, login, password; 303 на account; неизвестный логин и неверный пароль дают одинаковые 422 и сообщение |
| GET | auth.php?page=account | Защищённая страница-заглушка с именем из БД; гость получает 303 на login |
| POST | auth.php?page=logout | csrf; удалить session data и cookie, 303 на login |

Недопустимый CSRF/обычный production HTTP: 403. Неизвестная страница: 404. Недопустимый метод: 405 + Allow. Превышение размера запроса: 413. Лимит попыток: 429 + Retry-After: 900. Ошибка конфигурации, сессии, БД или схемы: общее сообщение 503, без SQL/stack trace. Пароль никогда не возвращается в HTML, session или cookie. Auth GET не пишет в БД; session CSRF token записывается в session storage, что не является SQL mutation.

Логин строго ASCII `[A-Za-z0-9_-]{3,32}`. PHP strtolower и SQL LOWER для ASCII дают один login_key. Оригинальный регистр остаётся в login; ascii_bin + UNIQUE(login_key) и CHECK(key=LOWER(login)) обеспечивают однозначность. Регистрация не входит автоматически: после неё пользователь вводит пароль на странице входа.

Пароль: 10–72 **байта**, без NUL. Верхняя граница исключает скрытое усечение bcrypt в PHP 8.1. Клиентский maxlength не заменяет серверную проверку. PASSWORD_DEFAULT/password_verify; неизвестный логин проверяется против фиксированного bcrypt hash той же стоимости, а внешний текст ошибки совпадает. Не гарантируется постоянное время всего SQL/HTTP-запроса.

## Единственный principal

`AuthSession::id(): ?int` читает только положительный integer `berserk_authenticated_user_id`. `account_id` — смысл этого значения, дополнительного session key account_id нет. login() регенерирует ID с удалением старой session и полностью заменяет содержимое: user_id, role, game_id, test bindings и прежний CSRF исчезают. AuthSession::requireCsrf() проверяет token до мутаций; logout уничтожает session и истекает cookie.

Cookie называется **BERSERKAUTH5A**, host-only, Path=/, session lifetime, HttpOnly, SameSite=Lax, Secure при HTTPS. Это отдельное пространство от legacy PHPSESSID. Strict mode, cookies-only, trans_sid=0. HTTPS определяется только доверенным серверным HTTPS=on/1; произвольные X-Forwarded-* не принимаются. Production за TLS proxy обязан выставлять HTTPS на web/PHP стороне по доверенной инфраструктуре. `allow_loopback_http=true` разрешён исключительно для REMOTE_ADDR=127.0.0.1/::1 на изолированном стенде; production оставить false.

GET account повторно загружает account из БД; удалённый аккаунт теряет auth. Для Stage 5B использовать те же AuthSession и AuthStore; роли комнаты определять отдельным service. Для Stage 5C нельзя просто переименовать cookie в PHPSESSID или скопировать principal в legacy session: сначала требуется ownership gate для новых матчей, закрытие всех prototype/чат/history/replay обходов и отдельная политика legacy/admin IDs. Номер аккаунта не Engine side.

## Лимит попыток

AuthService резервирует попытку **до password_verify**, независимо от результата. В одном 15-минутном окне максимум 5 попыток на нормализованный login и 20 на IP, включая успешные. Невалидные логины используют общий invalid bucket и тот же IP limit. Registration имеет отдельные такие же buckets для ограничения hashing нагрузки.

Ключи — HMAC-SHA256(scope + login/IP) с приватным стабильным limiter_secret. IP берётся только из REMOTE_ADDR, нормализуется inet_pton; за proxy все посетители могут разделять лимит proxy. Не доверять X-Forwarded-For без отдельного согласованного trust configuration. Текущая политика допускает временную блокировку известного login атакующим; это осознанный простой лимит Stage 5A, без CAPTCHA/сложной антиабьюз-системы.

AuthStore блокирует обе строки в стабильном порядке в одной транзакции InnoDB. Второй параллельный запрос видит уже зарезервированную попытку. Проверка пароля проходит после commit, без удерживания row lock. Заблокированные запросы не продлевают окно; истечение сбрасывает счётчик атомарно. Нет отдельного runtime-DDL или session-only ограничения. Ошибка limiter storage означает отказ, не обход лимита. Ошибка/deadlock даёт общий 503 без слепого повторения.

Очистка устаревших buckets — операционная задача: bounded DELETE по idx_auth_window для строк старше, например, суток, только отдельным обслуживающим пользователем. Runtime user не имеет DELETE/DDL. Не менять limiter_secret при каждом запросе: это сбросит ограничения.
