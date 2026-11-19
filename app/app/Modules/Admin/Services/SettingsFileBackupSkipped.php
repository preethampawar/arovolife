<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use RuntimeException;

/**
 * The settings-file copy was deliberately not made. The message is one of a
 * few fixed phrases — never the password or any of the file's contents.
 */
final class SettingsFileBackupSkipped extends RuntimeException {}
