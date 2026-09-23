<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Developer settings → Logs: every `*.log` file directly under
 * `storage/logs` (the application log, the daily payments log, and any
 * worker / scheduler output the server writes there), grouped by log name
 * and downloadable one file at a time.
 *
 * Developer-only: the route carries `role:developer`, which the super-staff
 * Gate::before bypass cannot open, and the controller answers 404 to anyone
 * else so the role is never confirmed (F84). Only a bare filename matching
 * {@see FILE_PATTERN} is served — no directory part can reach the path — and
 * every download is audit-logged, because the application log can hold stack
 * traces and request context.
 */
final class AdminLogController extends Controller
{
    /** `name.log` or `name-YYYY-MM-DD.log`; letters, digits, `_` and `-` only. */
    public const FILE_PATTERN = '/\A([a-z0-9_]+(?:-[a-z0-9_]+)*?)(?:-(\d{4}-\d{2}-\d{2}))?\.log\z/i';

    public function index(Request $request): View
    {
        $this->ensureDeveloper($request);

        return view('admin.logs.index', ['groups' => collect($this->logs())->sortByDesc('modified')->groupBy('channel')->sortKeys()]);
    }

    public function download(Request $request, string $file): BinaryFileResponse
    {
        $this->ensureDeveloper($request);

        abort_unless(preg_match(self::FILE_PATTERN, $file) === 1, 404);
        $path = storage_path('logs/'.$file);
        abort_unless(is_file($path), 404);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'admin.log_downloaded',
            'subject_type' => 'log_file',
            'subject_id' => null,
            'before_hash' => AuditLog::digest($file),
            'after_hash' => AuditLog::digest($file),
            'details' => ['file' => $file, 'bytes' => filesize($path)],
            'ip' => $request->ip(),
        ]);

        return response()->download($path, $file, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * @return list<array{file: string, channel: string, date: ?Carbon, bytes: int, modified: Carbon}>
     */
    private function logs(): array
    {
        $logs = [];
        foreach (glob(storage_path('logs/*.log')) ?: [] as $path) {
            $file = basename($path);
            if (! is_file($path) || preg_match(self::FILE_PATTERN, $file, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
                continue;
            }

            $logs[] = [
                'file' => $file,
                'channel' => (string) $m[1],
                'date' => $m[2] !== null ? Carbon::parse($m[2]) : null,
                'bytes' => (int) filesize($path),
                'modified' => Carbon::createFromTimestamp((int) filemtime($path), config('app.timezone')),
            ];
        }

        return $logs;
    }

    private function ensureDeveloper(Request $request): void
    {
        abort_unless($request->user()?->hasRole('developer') === true, 404);
    }
}
