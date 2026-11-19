<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Database backups (developer settings → Database backups)
|--------------------------------------------------------------------------
|
| A daily gzipped mysqldump of the default connection, written to the
| `db-backups` disk and kept for `retention_days`. The disk listing is the
| only record of what exists: a restore replaces the whole database, so a
| table of backups would be rolled back with it.
|
*/

return [

    'disk' => env('DB_BACKUP_DISK', 'db-backups'),

    'retention_days' => 30,

    // The PHP CLI that runs the detached backup / restore. Null resolves it
    // with Symfony's PhpExecutableFinder; under FPM that finds the first
    // `php` on PATH, which on Cloudways is not 8.4 — set /usr/bin/php8.4 there.
    'php_binary' => env('DB_BACKUP_PHP_BINARY'),

    'mysqldump_binary' => env('DB_BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),

    'mysql_binary' => env('DB_BACKUP_MYSQL_BINARY', 'mysql'),

    // Space-separated options appended to both mysqldump and mysql, e.g.
    // `--skip-ssl` where a MariaDB client talks to a server it cannot verify.
    'extra_client_options' => env('DB_BACKUP_CLIENT_OPTIONS', ''),

    // The server settings file (.env) is saved with each daily run as a
    // password-protected ZIP. The password lives only in the server's own
    // settings file; under 20 characters, or unset, no copy is made at all.
    'settings_password' => env('ENV_BACKUP_PASSWORD'),

    'settings_file' => base_path('.env'),

    // Local disks mirrored to the backup disk under files/{disk}/ each run:
    // `public` holds the catalogue images (catalog/).
    'file_disks' => ['public'],

];
