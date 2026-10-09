<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use Throwable;

/** PDO wrapper. All statements with user-supplied values use prepared statements. */
final class DB
{
    private static ?PDO $pdo = null;
    /** @var list<string>|null cached result of nonTransactionalTables() for this request */
    private static ?array $nonTransactional = null;

    public static function connect(array $cfg): PDO
    {
        $port = (int) ($cfg['port'] ?? 3306);
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'] ?? 'localhost',
            $port > 0 ? $port : 3306,
            $cfg['name'] ?? ''
        );
        $pdo = new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        // Store and compare all timestamps in UTC.
        $pdo->exec("SET time_zone = '+00:00'");
        // NO_ENGINE_SUBSTITUTION: never silently create MyISAM tables when InnoDB is requested;
        // MyISAM ignores transactions, which can leave half-recorded sales behind.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect((array) Config::get('db', []));
        }
        return self::$pdo;
    }

    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$nonTransactional = null;
    }

    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Run $fn inside a database transaction; commits on success, rolls back on any exception.
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            // Nested call: join the outer transaction; the outermost caller commits or rolls back.
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Tables of this application that do NOT use a transactional engine (should be none).
     * @return list<string>
     */
    public static function nonTransactionalTables(): array
    {
        if (self::$nonTransactional === null) {
            self::$nonTransactional = array_map('strval', self::pdo()->query(
                "SELECT table_name FROM information_schema.tables
                  WHERE table_schema = DATABASE()
                    AND table_name IN ('users','settings','categories','products','sales','sale_items','stock_movements')
                    AND engine <> 'InnoDB'"
            )->fetchAll(\PDO::FETCH_COLUMN));
        }
        return self::$nonTransactional;
    }

    public static function isDuplicateKey(Throwable $e, ?string $keyName = null): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $info = $e->errorInfo ?? [];
        $isDup = ($info[1] ?? null) === 1062 || $e->getCode() === '23000' && str_contains($e->getMessage(), 'Duplicate');
        return $isDup && ($keyName === null || str_contains($e->getMessage(), $keyName));
    }
}
