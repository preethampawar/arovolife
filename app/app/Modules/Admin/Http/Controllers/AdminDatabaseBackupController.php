<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\DatabaseBackupService;
use App\Modules\Admin\Services\SettingsFileBackupService;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings → Database backups: list the backups on the
 * `db-backups` disk, take one now, download one, or restore one.
 *
 * Admin and developer may list, download and take a backup; restore is
 * developer-only. Everyone else gets a 404 from here, never a 403 (the role
 * middleware answers 403, so the route group deliberately has none), so the
 * page and the hidden role are never confirmed (F84). Backup and restore are
 * started as detached artisan processes — a web request never runs the dump
 * or the import itself. A restore needs the file name typed back and the
 * developer's own password, and is audit-logged before it starts.
 */
final class AdminDatabaseBackupController extends Controller
{
    public function __construct(
        private readonly DatabaseBackupService $backups,
        private readonly SettingsFileBackupService $settingsCopies,
    ) {}

    public function index(Request $request): View
    {
        $this->ensureCanView($request);

        $status = $this->backups->status();

        return view('admin.database-backups.index', [
            'backups' => $this->backups->all(),
            'settingsCopies' => $this->settingsCopies->all(),
            'settingsPasswordUsable' => SettingsFileBackupService::passwordIsUsable(),
            'status' => $status,
            'running' => ($status['state'] ?? null) === 'running',
            'canRestore' => $request->user()->hasRole('developer'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureCanView($request);

        if ($this->backups->isBusy()) {
            return back()->withErrors(['backup' => 'Another backup or restore is already running. Wait for it to finish.']);
        }

        $this->backups->markRunning('backup', null);
        $this->backups->startInBackground('db:backup:run', ['--trigger=manual']);

        return redirect()->route('admin.database-backups.index')->with('status', 'Backup started. This page refreshes until it finishes.');
    }

    public function download(Request $request, string $file): Response
    {
        $this->ensureCanView($request);

        $backup = $this->backups->find($file);
        abort_if($backup === null, 404);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'admin.database_backup_downloaded',
            'subject_type' => 'database_backup',
            'subject_id' => null,
            'before_hash' => AuditLog::digest($file),
            'after_hash' => AuditLog::digest($file),
            'details' => ['file' => $file, 'bytes' => $backup->bytes],
            'ip' => $request->ip(),
        ]);

        $disk = Storage::disk((string) config('db_backup.disk'));

        if ($disk->providesTemporaryUrls()) {
            return redirect()->away($disk->temporaryUrl($file, Carbon::now()->addMinutes(5)));
        }

        return $disk->download($file);
    }

    public function downloadSettings(Request $request, string $file): Response
    {
        $this->ensureCanView($request);

        $copy = $this->settingsCopies->find($file);
        abort_if($copy === null, 404);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'admin.settings_backup_downloaded',
            'subject_type' => 'database_backup',
            'subject_id' => null,
            'before_hash' => AuditLog::digest($file),
            'after_hash' => AuditLog::digest($file),
            'details' => ['file' => $file, 'bytes' => $copy->bytes],
            'ip' => $request->ip(),
        ]);

        $disk = Storage::disk((string) config('db_backup.disk'));

        if ($disk->providesTemporaryUrls()) {
            return redirect()->away($disk->temporaryUrl($file, Carbon::now()->addMinutes(5)));
        }

        return $disk->download($file);
    }

    public function restore(Request $request, string $file): RedirectResponse
    {
        $this->ensureDeveloper($request);

        abort_if($this->backups->find($file) === null, 404);

        $request->validate([
            'confirm_file' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (! hash_equals($file, (string) $request->input('confirm_file'))) {
            return back()->withErrors(['confirm_file' => 'The file name you typed does not match this backup.']);
        }

        if (! Hash::check((string) $request->input('password'), $request->user()->getAuthPassword())) {
            return back()->withErrors(['password' => 'That password is not correct.']);
        }

        if ($this->backups->isBusy()) {
            return back()->withErrors(['backup' => 'Another backup or restore is already running. Wait for it to finish.']);
        }

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'admin.database_restore_requested',
            'subject_type' => 'database_backup',
            'subject_id' => null,
            'before_hash' => AuditLog::digest($file),
            'after_hash' => AuditLog::digest($file),
            'details' => ['file' => $file],
            'ip' => $request->ip(),
        ]);

        $this->backups->markRunning('restore', $file);
        $this->backups->startInBackground('db:backup:restore', [$file, '--actor='.$request->user()->id]);

        return redirect()->route('admin.database-backups.index')->with('status', "Restore of {$file} started. The site goes into maintenance mode until it finishes.");
    }

    private function ensureCanView(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(['admin', 'developer']) === true, 404);
    }

    private function ensureDeveloper(Request $request): void
    {
        abort_unless($request->user()?->hasRole('developer') === true, 404);
    }
}
