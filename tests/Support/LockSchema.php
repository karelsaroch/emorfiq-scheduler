<?php

declare(strict_types=1);

namespace Emorfiq\Tests\Support;

/**
 * Testy zakládají tabulku zámků z dodávané migrace database/sql/scheduler_locks.sql,
 * takže ověřují i samotné DDL na každém backendu.
 */
final class LockSchema
{
    public const MIGRATION = __DIR__ . '/../../database/sql/scheduler_locks.sql';

    public static function install(\PDO $pdo, string $table = 'scheduler_locks'): void
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        if (self::exists($pdo, $table)) {
            return;
        }

        foreach (self::statements($table) as $sql) {
            $pdo->exec($sql);
        }
    }

    /** @return list<string> */
    public static function statements(string $table): array
    {
        $sql = (string) file_get_contents(self::MIGRATION);
        $sql = preg_replace('/--[^\n]*/', '', $sql);              // komentáře
        $sql = str_replace('scheduler_locks', $table, (string) $sql); // i název indexu

        return array_values(array_filter(array_map('trim', explode(';', $sql))));
    }

    private static function exists(\PDO $pdo, string $table): bool
    {
        try {
            $pdo->query("SELECT 1 FROM {$table} WHERE 1 = 0");

            return true;
        } catch (\PDOException) {
            return false;
        }
    }
}
