<?php

declare(strict_types=1);

namespace App\Modules\Shared\Testing;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Keeps a byte-for-byte copy of the e2e base state in a sibling database
 * (`<e2e db>_base`) and restores it table by table in SQL.
 *
 * A dump file was the first choice, but the container's MariaDB client cannot
 * authenticate against MySQL 8 (caching_sha2_password), and copying inside the
 * server is faster than a dump/restore round-trip anyway.
 *
 * Both databases are e2e-only: the live one is proven `*_e2e` by
 * {@see E2eMode::assertSafe()} before anything here runs, and the template's
 * name is derived from it, never configured.
 */
final class E2eDatabaseTemplate
{
    public function __construct(private readonly Connection $db) {}

    public function liveDatabase(): string
    {
        $name = (string) $this->db->getDatabaseName();

        if (! str_ends_with($name, '_e2e')) {
            throw new RuntimeException("Refusing: '{$name}' is not an e2e database.");
        }

        return $name;
    }

    public function templateDatabase(): string
    {
        return $this->liveDatabase().'_base';
    }

    /** Replace the template with the live database's current tables and rows. */
    public function capture(): int
    {
        $live = $this->liveDatabase();
        $base = $this->templateDatabase();

        $this->db->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($this->tables($base) as $table) {
                $this->db->statement("DROP TABLE `{$base}`.`{$table}`");
            }

            $tables = $this->tables($live);

            foreach ($tables as $table) {
                $this->db->statement("CREATE TABLE `{$base}`.`{$table}` LIKE `{$live}`.`{$table}`");
                $this->copyRows($live, $base, $table);
            }
        } finally {
            $this->db->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return count($tables);
    }

    /** Put every live table back to the template's rows and auto-increment. */
    public function restore(): int
    {
        $live = $this->liveDatabase();
        $base = $this->templateDatabase();
        $templateTables = $this->tables($base);

        if ($templateTables === []) {
            throw new RuntimeException("The template database '{$base}' is empty — run `php artisan e2e:snapshot` first.");
        }

        $this->db->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($this->tables($live) as $table) {
                $this->db->statement("TRUNCATE TABLE `{$live}`.`{$table}`");

                if (in_array($table, $templateTables, true)) {
                    $this->copyRows($base, $live, $table);
                }
            }
        } finally {
            $this->db->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return count($templateTables);
    }

    /** @return list<string> the migrations recorded in the template */
    public function templateMigrations(): array
    {
        $base = $this->templateDatabase();

        if (! in_array('migrations', $this->tables($base), true)) {
            return [];
        }

        /** @var list<object{m: string}> $rows */
        $rows = $this->db->select("SELECT migration AS m FROM `{$base}`.`migrations`");

        return array_values(array_map(static fn (object $row): string => (string) $row->m, $rows));
    }

    /** @return list<string> */
    private function tables(string $schema): array
    {
        /** @var list<object{t: string}> $rows */
        $rows = $this->db->select(
            "SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
            [$schema],
        );

        return array_values(array_map(static fn (object $row): string => (string) $row->t, $rows));
    }

    /**
     * Copy rows, skipping virtual/stored generated columns (MySQL refuses explicit
     * values for them). `DEFAULT_GENERATED` — MySQL 8's marker on every
     * CURRENT_TIMESTAMP default — is a normal column and must be copied.
     */
    private function copyRows(string $from, string $to, string $table): void
    {
        /** @var list<object{c: string}> $columns */
        $columns = $this->db->select(
            "SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND EXTRA NOT IN ('VIRTUAL GENERATED', 'STORED GENERATED')
             ORDER BY ORDINAL_POSITION",
            [$from, $table],
        );

        if ($columns === []) {
            return;
        }

        $list = implode(', ', array_map(static fn (object $row): string => '`'.$row->c.'`', $columns));

        $this->db->statement("INSERT INTO `{$to}`.`{$table}` ({$list}) SELECT {$list} FROM `{$from}`.`{$table}`");
    }
}
