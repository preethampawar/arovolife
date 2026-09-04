<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Admin\Http\Controllers\AdminSettingsController;
use App\Modules\Compensation\Exceptions\PayoutGatewayNotConfiguredException;
use App\Modules\Compensation\Services\PayoutGatewaySettings;
use App\Modules\Compensation\Services\RazorpayPayoutGateway;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One screen answering "how does money leave this platform, and is that route
 * actually working?".
 *
 * It does not own the settings. The five payout levers live in the platform
 * settings registry like every other tunable, so there is exactly one
 * validation path, one audit shape and one ownership rule for all of them;
 * the edit forms here post to `admin.settings.update`. What this page adds is
 * the half the registry cannot show: which RazorpayX credentials the
 * environment actually holds, whether they are test or live keys, and a live
 * connection check.
 *
 * Credentials are never rendered — only whether each one is present.
 */
final class AdminPayoutSettingsController extends Controller
{
    /** The registry keys this page surfaces, in display order. */
    private const KEYS = [
        PayoutGatewaySettings::KEY_GATEWAY,
        PayoutGatewaySettings::KEY_TRANSFER_MODE,
        PayoutGatewaySettings::KEY_NARRATION,
        PayoutGatewaySettings::KEY_MAX_RETRIES,
        PayoutGatewaySettings::KEY_AUTO_RETRY_HOURS,
    ];

    public function __construct(private readonly PayoutGatewaySettings $settings) {}

    public function index(Request $request): View
    {
        $registry = AdminSettingsController::registry();
        $stored = DB::table('settings')->whereIn('key', self::KEYS)->pluck('value', 'key');

        $rows = [];
        foreach (self::KEYS as $key) {
            $meta = $registry[$key] ?? null;
            if ($meta === null) {
                continue;
            }

            $rows[$key] = [
                'meta' => $meta,
                'value' => (string) ($stored[$key] ?? ($meta['default'] ?? '')),
            ];
        }

        return view('admin.compensation.payout-settings', [
            'rows' => $rows,
            'gateway' => $this->settings->gateway(),
            // Editing payout routing decides where company money goes, so the
            // forms render only for the role that owns platform configuration
            // (the `payout` group is developer-owned). The write route carries
            // the same gate — this only keeps the UI honest.
            'canEdit' => $request->user()?->hasRole('developer') ?? false,
            'credentials' => [
                'key_id' => $this->settings->keyId() !== '',
                'key_secret' => $this->settings->keySecret() !== '',
                'webhook_secret' => $this->settings->webhookSecret() !== '',
                'account_number' => $this->settings->accountNumber() !== '',
            ],
            'razorpayMode' => $this->settings->razorpayMode(),
            'razorpayReady' => $this->settings->razorpayReady(),
            'webhookUrl' => route('webhooks.razorpay.payouts'),
        ]);
    }

    /**
     * Ask RazorpayX to list one contact. Proves the key pair is accepted and
     * nothing else — no payout is created, no money moves.
     */
    public function testConnection(Request $request, RazorpayPayoutGateway $gateway): JsonResponse
    {
        if (! $this->settings->razorpayConfigured()) {
            return response()->json([
                'status' => 'error',
                'message' => 'RazorpayX Payouts credentials are not configured in the environment. Contact your DevOps team.',
            ]);
        }

        try {
            $gateway->ping();
        } catch (PayoutGatewayNotConfiguredException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            AuditLog::create([
                'actor_id' => $request->user()?->id,
                'action' => 'payout.gateway.connection_tested',
                'subject_type' => 'settings',
                'subject_id' => null,
                'details' => ['result' => 'error', 'message' => mb_substr($e->getMessage(), 0, 500)],
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }

        AuditLog::create([
            'actor_id' => $request->user()?->id,
            'action' => 'payout.gateway.connection_tested',
            'subject_type' => 'settings',
            'subject_id' => null,
            'details' => ['result' => 'ok', 'mode' => $this->settings->razorpayMode()],
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'status' => 'ok',
            'message' => 'Connected to RazorpayX ('.($this->settings->razorpayMode() ?? 'unknown').' keys).',
        ]);
    }
}
