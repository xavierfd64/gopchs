<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\DB;
use PDO;

/** Applies database/migrations/NNN_*.sql files in order, once each. */
final class Migrator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{version:int,file:string}> */
    public static function available(): array
    {
        $out = [];
        $files = array_merge(glob(MOTO_ROOT . '/database/migrations/*.sql') ?: [], glob(MOTO_ROOT . '/database/migrations/*.php') ?: []);
        foreach ($files as $file) {
            if (preg_match('/^(\d{3})_[a-z0-9_]+\.(sql|php)$/', basename($file), $m)) {
                $out[] = ['version' => (int) $m[1], 'file' => $file];
            }
        }
        usort($out, static fn ($a, $b) => $a['version'] <=> $b['version']);
        return $out;
    }

    public function applied(): array
    {
        try {
            return array_map('intval', $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
        } catch (\PDOException) {
            return [];
        }
    }

    /** @return list<int> versions applied by this call */
    public function migrate(): array
    {
        $done = $this->applied();
        $ran = [];
        foreach (self::available() as $m) {
            if (in_array($m['version'], $done, true)) {
                continue;
            }
            if (str_ends_with($m['file'], '.php')) {
                // PHP migrations return function (PDO $pdo): void. They must be idempotent, because
                // MySQL DDL cannot be rolled back: a retried migration simply skips finished steps.
                $fn = require $m['file'];
                $fn($this->pdo);
            } else {
                foreach (self::statements((string) file_get_contents($m['file'])) as $sql) {
                    $this->pdo->exec($sql);
                }
            }
            $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
            $stmt->execute([$m['version'], Clock::nowUtc()]);
            $ran[] = $m['version'];
        }
        return $ran;
    }

    /** Split a migration file into statements (no semicolons inside string literals are used). */
    public static function statements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn (string $l) => !preg_match('/^\s*--/', $l)
        );
        $parts = preg_split('/;\s*(?:\R|$)/', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn ($s) => $s !== ''));
    }

    public static function pending(): array
    {
        $m = new self(DB::pdo());
        $applied = $m->applied();
        return array_values(array_filter(self::available(), static fn ($x) => !in_array($x['version'], $applied, true)));
    }
}
