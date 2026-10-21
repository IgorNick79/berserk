<?php
declare(strict_types=1);
namespace Berserk\Auth;

/** Separate parameterized connection: no gameplay bootstrap and no runtime DDL. */
final class AuthStore
{
    private \mysqli $db;

    public function __construct(array $config)
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->db = new \mysqli($config['host'], $config['user'], $config['pass'], $config['name'], (int)($config['port'] ?? 3306));
        $this->db->set_charset('utf8mb4');
        // Fail closed on missing/incompatible tables without creating or altering them.
        foreach (['accounts', 'auth_login_attempts'] as $table) {
            $row = $this->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])->fetch_assoc();
            if (($row['ENGINE'] ?? '') !== 'InnoDB') throw new \RuntimeException('Auth schema unavailable');
        }
        $this->query('SELECT id,login,login_key,password_hash,created_at FROM accounts LIMIT 0');
        $this->query('SELECT bucket_key,window_start,attempts FROM auth_login_attempts LIMIT 0');
        $indexes = $this->query("SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounts'")->fetch_all(MYSQLI_ASSOC);
        $unique = [];
        foreach ($indexes as $index) if ((int)$index['NON_UNIQUE'] === 0) $unique[$index['INDEX_NAME']][] = $index['COLUMN_NAME'];
        if (!in_array(['login_key'], $unique, true)) throw new \RuntimeException('Auth schema unavailable');
        $expected = [
            'accounts'=>['id'=>'int(11)','login'=>'varchar(32)','login_key'=>'varchar(32)','password_hash'=>'varchar(255)','created_at'=>'datetime(6)'],
            'auth_login_attempts'=>['bucket_key'=>'char(64)','window_start'=>'datetime(6)','attempts'=>'int(10) unsigned'],
        ];
        foreach ($expected as $table => $fields) {
            $columns = $this->query('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLLATION_NAME,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetch_all(MYSQLI_ASSOC);
            foreach ($fields as $name => $type) {
                $matches = array_values(array_filter($columns, static fn(array $c): bool => $c['COLUMN_NAME'] === $name));
                $column = $matches[0] ?? [];
                // MySQL 8 may omit integer display widths.
                $normalize = static fn(string $t): string => preg_replace('/\bint\(\d+\)/', 'int', $t);
                if ($normalize($column['COLUMN_TYPE'] ?? '') !== $normalize($type) || ($column['IS_NULLABLE'] ?? '') !== 'NO') throw new \RuntimeException('Auth schema unavailable');
                if (in_array($name,['login','login_key','password_hash','bucket_key'],true) && ($column['COLLATION_NAME'] ?? '') !== 'ascii_bin') throw new \RuntimeException('Auth schema unavailable');
                if ($name === 'id' && !str_contains($column['EXTRA'] ?? '', 'auto_increment')) throw new \RuntimeException('Auth schema unavailable');
            }
        }
        foreach (['accounts'=>'id','auth_login_attempts'=>'bucket_key'] as $table=>$primary) {
            $keys = $this->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX",[$table])->fetch_all(MYSQLI_ASSOC);
            if (array_column($keys,'COLUMN_NAME') !== [$primary]) throw new \RuntimeException('Auth schema unavailable');
        }
    }

    private function query(string $sql, array $params = []): \mysqli_result|bool
    {
        $stmt = $this->db->prepare($sql);
        try {
            if ($params) $stmt->bind_param(str_repeat('s', count($params)), ...$params);
            $stmt->execute();
            return $stmt->get_result();
        } finally { $stmt->close(); }
    }

    public function find(string $key): ?array
    {
        return $this->query('SELECT id,login,password_hash FROM accounts WHERE login_key=?', [$key])->fetch_assoc();
    }

    public function account(int $id): ?Account
    {
        $row = $this->query('SELECT id,login FROM accounts WHERE id=?', [$id])->fetch_assoc();
        return $row ? new Account((int)$row['id'], $row['login']) : null;
    }

    public function register(string $login, string $password): Account
    {
        $key = Account::loginKey($login);
        Account::validatePassword($password);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $this->query('INSERT INTO accounts(login,login_key,password_hash,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))', [$login,$key,$hash]);
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) throw new \DomainException('Этот логин уже занят.');
            throw new \RuntimeException('Auth storage unavailable');
        }
        return new Account((int)$this->db->insert_id, $login);
    }

    /** Reserve BOTH buckets before verification. Rows locked in stable lexical order. */
    public function reserveAttempt(string $key, string $address, string $secret, string $scope = 'login'): bool
    {
        $buckets = [
            hash_hmac('sha256', $scope . ':login:' . $key, $secret) => 5,
            hash_hmac('sha256', $scope . ':ip:' . $address, $secret) => 20,
        ];
        ksort($buckets);
        $this->db->begin_transaction();
        try {
            $allowed = true;
            foreach ($buckets as $bucket => $limit) {
                $this->query('INSERT INTO auth_login_attempts(bucket_key,window_start,attempts) VALUES(?,UTC_TIMESTAMP(6),0) ON DUPLICATE KEY UPDATE bucket_key=VALUES(bucket_key)', [$bucket]);
                $row = $this->query('SELECT attempts,(window_start <= UTC_TIMESTAMP(6) - INTERVAL 15 MINUTE) AS expired FROM auth_login_attempts WHERE bucket_key=? FOR UPDATE', [$bucket])->fetch_assoc();
                if ((int)$row['expired'] === 1) {
                    $this->query('UPDATE auth_login_attempts SET window_start=UTC_TIMESTAMP(6),attempts=0 WHERE bucket_key=?', [$bucket]);
                    $row['attempts'] = 0;
                }
                if ((int)$row['attempts'] >= $limit) $allowed = false;
            }
            // Even blocked requests never reset or extend the window.
            if ($allowed) foreach ($buckets as $bucket => $limit) {
                $this->query('UPDATE auth_login_attempts SET attempts=attempts+1 WHERE bucket_key=?', [$bucket]);
            }
            $this->db->commit();
            return $allowed;
        } catch (\Throwable) {
            try { $this->db->rollback(); } catch (\Throwable) {}
            throw new \RuntimeException('Auth limiter unavailable');
        }
    }
}
