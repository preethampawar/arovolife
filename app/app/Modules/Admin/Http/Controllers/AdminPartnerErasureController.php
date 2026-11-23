<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\Erasure\PartnerErasureRefused;
use App\Modules\Admin\Services\Erasure\PartnerErasureService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ViewErrorBag;

/**
 * Developer-only: permanently delete one partner and everything below them in
 * the Genos. Three steps: type the APN, review the preview, retype the APN and
 * confirm. Anyone who is not a developer gets a 404, never a 403, so the page
 * and the hidden role are never confirmed (same as the database backups page).
 * The route group deliberately carries no `role:developer` middleware, which
 * would answer 403.
 */
final class AdminPartnerErasureController extends Controller
{
    private const APN_RULE = ['required', 'string', 'max:16', 'regex:/^\d{9}$/'];

    public function __construct(private readonly PartnerErasureService $erasure) {}

    public function form(Request $request): View
    {
        $this->ensureDeveloper($request);

        return view('admin.distributors.erase.form');
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $this->ensureDeveloper($request);

        $data = $request->validate(['adn' => self::APN_RULE]);

        try {
            $preview = $this->erasure->preview($data['adn']);
        } catch (PartnerErasureRefused $e) {
            return redirect()->route('admin.distributors.erase.form')
                ->withInput(['adn' => $data['adn']])
                ->withErrors(['adn' => $e->blockers[0]['message']]);
        }

        return view('admin.distributors.erase.preview', ['preview' => $preview]);
    }

    public function destroy(Request $request): View|RedirectResponse
    {
        $this->ensureDeveloper($request);

        $validator = Validator::make($request->all(), [
            'adn' => self::APN_RULE,
            'fingerprint' => ['required', 'string', 'size:64'],
            'confirm_adn' => ['required', 'same:adn'],
        ], [
            'confirm_adn.same' => 'The APN you typed again does not match.',
        ]);

        // Never redirect "back": the preview is a POST-only page, so a GET
        // to it answers 405. Draw the preview again with the error instead.
        if ($validator->fails()) {
            $adn = $request->input('adn');
            if (! is_string($adn) || preg_match('/^\d{9}$/', $adn) !== 1) {
                return redirect()->route('admin.distributors.erase.form')->withErrors($validator);
            }

            try {
                $preview = $this->erasure->preview($adn);
            } catch (PartnerErasureRefused $e) {
                return redirect()->route('admin.distributors.erase.form')
                    ->withInput(['adn' => $adn])
                    ->withErrors(['adn' => $e->blockers[0]['message']]);
            }

            return view('admin.distributors.erase.preview', [
                'preview' => $preview,
                'errors' => (new ViewErrorBag)->put('default', $validator->errors()),
            ]);
        }

        /** @var array{adn: string, fingerprint: string, confirm_adn: string} $data */
        $data = $validator->validated();

        set_time_limit(600);

        try {
            $result = $this->erasure->execute($data['adn'], $data['fingerprint'], $request->user());
        } catch (PartnerErasureRefused $e) {
            $messages = array_column($e->blockers, 'message');

            // The target may still resolve (blockers, or the tree moved):
            // show the fresh preview with the reasons. Otherwise start over.
            try {
                $preview = $this->erasure->preview($data['adn']);
            } catch (PartnerErasureRefused) {
                return redirect()->route('admin.distributors.erase.form')
                    ->withInput(['adn' => $data['adn']])
                    ->withErrors(['adn' => $messages[0] ?? 'The partner could not be deleted.']);
            }

            return view('admin.distributors.erase.preview', [
                'preview' => $preview,
                'refusals' => $messages,
            ]);
        } catch (\RuntimeException $e) {
            // Backup busy or failed, or the transaction rolled back. Every
            // step that can throw runs before the commit (file removal after
            // it never throws), so nothing was erased.
            $reason = $e instanceof QueryException
                ? 'A database error occurred.'
                : $e->getMessage();

            return redirect()->route('admin.distributors.erase.form')
                ->withInput(['adn' => $data['adn']])
                ->withErrors(['adn' => "Nothing was deleted. {$reason}"]);
        }

        $status = "APN {$data['adn']} and ".($result->erasedCount - 1).' partners below it were deleted.';
        if ($result->filesFailed > 0) {
            $status .= " {$result->filesFailed} files could not be removed from storage.";
        }

        return redirect()->route('admin.distributors.index')->with('status', $status);
    }

    private function ensureDeveloper(Request $request): void
    {
        abort_unless($request->user()?->hasRole('developer') === true, 404);
    }
}
