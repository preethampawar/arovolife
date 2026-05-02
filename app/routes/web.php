<?php

declare(strict_types=1);

use App\Modules\Admin\Http\Controllers\AdminAuditLogController;
use App\Modules\Admin\Http\Controllers\AdminContactController;
use App\Modules\Admin\Http\Controllers\AdminDashboardController;
use App\Modules\Admin\Http\Controllers\AdminDistributorController;
use App\Modules\Admin\Http\Controllers\AdminImpersonationController;
use App\Modules\Admin\Http\Controllers\AdminKycController;
use App\Modules\Admin\Http\Controllers\AdminSettingsController;
use App\Modules\Admin\Http\Controllers\AdminTreeController;
use App\Modules\Commerce\Http\Controllers\Admin\AdminOrderController;
use App\Modules\Commerce\Http\Controllers\Storefront\CartController;
use App\Modules\Commerce\Http\Controllers\Storefront\CheckoutController;
use App\Modules\Commerce\Http\Controllers\Storefront\ShopController;
use App\Modules\Compliance\Http\Controllers\CoolingOffController;
use App\Modules\Content\Http\Controllers\Admin\AdminContentPageController;
use App\Modules\Content\Http\Controllers\Public\PublicContentPageController;
use App\Modules\Genealogy\Http\Controllers\LineChangeController;
use App\Modules\Genealogy\Http\Controllers\TreeController;
use App\Modules\Identity\Http\Controllers\Auth\LoginController;
use App\Modules\Identity\Http\Controllers\Auth\PasswordResetController;
use App\Modules\Identity\Http\Controllers\Auth\SpouseActivationController;
use App\Modules\Identity\Http\Controllers\ProfileController;
use App\Modules\Identity\Http\Controllers\Registration\RegistrationWizardController;
use App\Modules\Public\Http\Controllers\ContactController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('landing.index'))->name('home');
Route::get('/about-us', fn () => view('landing.about'))->name('about');

// ── Authentication ───────────────────────────────────────────────────────────

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.post');

    // Public registration entry — referral-link gated per ADR-0003. Direct
    // visits redirect to Contact Us; valid links stash intent + advance to
    // /register/account.
    Route::get('/register', [RegistrationWizardController::class, 'start'])->name('register');
    Route::get('/register/account', [RegistrationWizardController::class, 'showAccount'])->name('register.account.show');
    Route::post('/register/account', [RegistrationWizardController::class, 'handleAccount'])->name('register.post');

    // Forgot-password flow. The send-link endpoint is throttled (3 requests
    // per 10 min per IP) so an attacker can't spam reset emails to a victim.
    Route::get('/forgot-password', [PasswordResetController::class, 'showRequest'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:3,10')->name('password.email');

    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showReset'])->name('password.reset.show');
    Route::post('/reset-password/{token}', [PasswordResetController::class, 'submit'])
        ->middleware('throttle:6,10')->name('password.reset.submit');
});

Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// Spouse-account activation via signed magic link. Both GET and POST require
// a valid Laravel signature (Url::temporarySignedRoute) so a leaked link
// past expiry can't be reused.
Route::get('/activate/{user}', [SpouseActivationController::class, 'show'])
    ->middleware('signed')->name('spouse.activate.show');
Route::post('/activate/{user}', [SpouseActivationController::class, 'submit'])
    ->middleware('signed')->name('spouse.activate.submit');

// ── Registration Wizard (steps 2-10) ─────────────────────────────────────────

Route::middleware(['auth'])->group(function (): void {
    Route::get('/register/orientation', [RegistrationWizardController::class, 'showOrientation'])
        ->middleware('wizard.progress:2')->name('register.orientation');
    Route::post('/register/orientation', [RegistrationWizardController::class, 'handleOrientation'])
        ->middleware('wizard.progress:2');

    Route::get('/register/personal', [RegistrationWizardController::class, 'showPersonal'])
        ->middleware('wizard.progress:3')->name('register.personal');
    Route::post('/register/personal', [RegistrationWizardController::class, 'handlePersonal'])
        ->middleware('wizard.progress:3');

    Route::get('/register/kyc/pan', [RegistrationWizardController::class, 'showPan'])
        ->middleware('wizard.progress:4')->name('register.pan');
    Route::post('/register/kyc/pan', [RegistrationWizardController::class, 'handlePan'])
        ->middleware('wizard.progress:4');

    Route::get('/register/kyc/aadhaar', [RegistrationWizardController::class, 'showAadhaar'])
        ->middleware('wizard.progress:5')->name('register.aadhaar');
    Route::post('/register/kyc/aadhaar', [RegistrationWizardController::class, 'handleAadhaar'])
        ->middleware('wizard.progress:5');

    Route::get('/register/kyc/bank', [RegistrationWizardController::class, 'showBank'])
        ->middleware('wizard.progress:6')->name('register.bank');
    Route::post('/register/kyc/bank', [RegistrationWizardController::class, 'handleBank'])
        ->middleware('wizard.progress:6');

    Route::get('/register/documents', [RegistrationWizardController::class, 'showDocuments'])
        ->middleware('wizard.progress:7')->name('register.documents');
    Route::post('/register/documents', [RegistrationWizardController::class, 'handleDocuments'])
        ->middleware('wizard.progress:7');

    Route::get('/register/placement', [RegistrationWizardController::class, 'showPlacement'])
        ->middleware('wizard.progress:8')->name('register.placement');
    Route::post('/register/placement', [RegistrationWizardController::class, 'handlePlacement'])
        ->middleware('wizard.progress:8');

    Route::get('/register/consent', [RegistrationWizardController::class, 'showConsent'])
        ->middleware('wizard.progress:9')->name('register.consent');
    Route::post('/register/consent', [RegistrationWizardController::class, 'handleConsent'])
        ->middleware('wizard.progress:9');

    Route::get('/register/complete', [RegistrationWizardController::class, 'showComplete'])
        ->middleware('wizard.progress:10')->name('register.complete');
    Route::post('/register/complete', [RegistrationWizardController::class, 'handleComplete'])
        ->middleware('wizard.progress:10');
});

// ── Admin Console ────────────────────────────────────────────────────────────

Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

    Route::get('/distributors', [AdminDistributorController::class, 'index'])->name('distributors.index');
    Route::get('/distributors/export', [AdminDistributorController::class, 'export'])->name('distributors.export');
    Route::get('/distributors/{id}', [AdminDistributorController::class, 'show'])->name('distributors.show');
    Route::post('/distributors/{id}/freeze', [AdminDistributorController::class, 'freeze'])->name('distributors.freeze');
    Route::post('/distributors/{id}/unfreeze', [AdminDistributorController::class, 'unfreeze'])->name('distributors.unfreeze');
    Route::post('/distributors/{id}/terminate', [AdminDistributorController::class, 'terminate'])->name('distributors.terminate');

    Route::get('/settings', [AdminSettingsController::class, 'index'])->name('settings');
    Route::post('/settings/age-rules', [AdminSettingsController::class, 'updateStateAgeMinimums'])->name('settings.age-rules');

    Route::get('/audit-log', [AdminAuditLogController::class, 'index'])->name('audit-log');

    // Contact inquiries — admin inbox for the public /contact-us form
    Route::get('/contact-inquiries', [AdminContactController::class, 'index'])->name('contact-inquiries.index');
    Route::get('/contact-inquiries/{id}', [AdminContactController::class, 'show'])->name('contact-inquiries.show');
    Route::post('/contact-inquiries/{id}/handle', [AdminContactController::class, 'markHandled'])->name('contact-inquiries.handle');
    Route::post('/contact-inquiries/{id}/unhandle', [AdminContactController::class, 'markUnhandled'])->name('contact-inquiries.unhandle');

    // KYC manual review
    Route::get('/kyc', [AdminKycController::class, 'index'])->name('kyc.index');
    Route::get('/kyc/{id}', [AdminKycController::class, 'show'])->name('kyc.show');
    Route::get('/kyc/{id}/documents/{docId}', [AdminKycController::class, 'streamDocument'])->name('kyc.document');
    Route::post('/kyc/{id}/approve', [AdminKycController::class, 'approve'])->name('kyc.approve');
    Route::post('/kyc/{id}/reject', [AdminKycController::class, 'reject'])->name('kyc.reject');

    // Commerce — orders
    Route::get('/commerce/orders', [AdminOrderController::class, 'index'])->name('commerce.orders.index');
    Route::get('/commerce/orders/{order}', [AdminOrderController::class, 'show'])->name('commerce.orders.show');
    Route::post('/commerce/orders/{order}/ship', [AdminOrderController::class, 'markShipped'])->name('commerce.orders.ship');
    Route::post('/commerce/orders/{order}/deliver', [AdminOrderController::class, 'markDelivered'])->name('commerce.orders.deliver');

    // Content pages CRUD
    Route::get('/content', [AdminContentPageController::class, 'index'])->name('content.index');
    Route::get('/content/create', [AdminContentPageController::class, 'create'])->name('content.create');
    Route::post('/content', [AdminContentPageController::class, 'store'])->name('content.store');
    Route::get('/content/{page}/edit', [AdminContentPageController::class, 'edit'])->name('content.edit');
    Route::patch('/content/{page}', [AdminContentPageController::class, 'update'])->name('content.update');
    Route::delete('/content/{page}', [AdminContentPageController::class, 'destroy'])->name('content.destroy');

    // Genealogy — admin can view the entire company tree, or any distributor's
    // subtree. The id-less route shows the company root (Distributor whose
    // sponsor_id == its own id); /admin/tree/{id} scopes to that subtree.
    Route::get('/tree/{id?}', [AdminTreeController::class, 'show'])
        ->whereNumber('id')->name('tree.show');

    // Impersonation — admin assumes the identity of a distributor for support
    // purposes. The "stop" route lives OUTSIDE the role:admin middleware
    // (below) because the active session during impersonation is the
    // distributor's, not the admin's.
    Route::post('/impersonate/{userId}/start', [AdminImpersonationController::class, 'start'])
        ->whereNumber('userId')->name('impersonate.start');
});

// "Stop impersonation" must be reachable while the admin is logged in as the
// target distributor (no admin role on Auth::user() in that state). Auth + the
// presence of session('impersonator_id') is the gate.
Route::post('/admin/impersonate/stop', [AdminImpersonationController::class, 'stop'])
    ->middleware('auth')->name('admin.impersonate.stop');

// ── Public Content Pages ─────────────────────────────────────────────────────

Route::get('/p/{slug}', [PublicContentPageController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('content.show');

// ── Contact Us ───────────────────────────────────────────────────────────────

Route::get('/contact-us', [ContactController::class, 'show'])->name('contact.show');
Route::post('/contact-us', [ContactController::class, 'submit'])->name('contact.submit');

// ── Public Storefront (Commerce) ─────────────────────────────────────────────

Route::middleware('capture.attribution')->group(function (): void {
    Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
    Route::get('/shop/p/{slug}', [ShopController::class, 'show'])->name('shop.product');

    Route::get('/shop/cart', [CartController::class, 'show'])->name('shop.cart');
    Route::post('/shop/cart/add', [CartController::class, 'add'])->name('shop.cart.add');
    Route::patch('/shop/cart/items/{item}', [CartController::class, 'update'])->name('shop.cart.update');
    Route::delete('/shop/cart/items/{item}', [CartController::class, 'remove'])->name('shop.cart.remove');

    Route::get('/shop/checkout', [CheckoutController::class, 'show'])->name('shop.checkout');
    Route::post('/shop/checkout', [CheckoutController::class, 'place'])->name('shop.checkout.place');
    Route::get('/shop/confirmation/{orderNo}', [CheckoutController::class, 'confirmation'])->name('shop.confirmation');
});

// ── Authenticated App ────────────────────────────────────────────────────────

Route::middleware(['auth'])->group(function (): void {
    Route::get('/dashboard', function () {
        $user = auth()->user();
        $distributor = $user->distributor;

        // Slot-state for the referral-link widget. Per ADR-0003 the
        // dashboard's default link routes new joiners under the user's own
        // ADN; once both direct slots are taken, that link silently fails.
        // Read both slots up front so the view can render the correct copy.
        $leftOpen = $rightOpen = false;
        $maxObservedDepth = 0;
        if ($distributor !== null) {
            $engine = app(\App\Modules\Genealogy\Services\PlacementEngine::class);
            $leftOpen  = $engine->hasOpenSlot($distributor->id, 'L');
            $rightOpen = $engine->hasOpenSlot($distributor->id, 'R');

            // Used by the "Open my tree" button so the tree view jumps
            // straight to the user's actual depth instead of the default 4.
            $maxObservedDepth = (int) \Illuminate\Support\Facades\DB::table('genealogy_closure')
                ->where('ancestor_id', $distributor->id)
                ->where('depth', '>', 0)
                ->max('depth');
        }

        return view('dashboard.index', compact('user', 'distributor', 'leftOpen', 'rightOpen', 'maxObservedDepth'));
    })->name('dashboard');

    Route::get('/cooling-off', [CoolingOffController::class, 'show'])->name('cooling-off.show');
    Route::post('/cooling-off/cancel', [CoolingOffController::class, 'cancel'])->name('cooling-off.cancel');

    Route::get('/tree', [TreeController::class, 'binary'])->name('tree.binary');
    Route::get('/tree/sponsorship', [TreeController::class, 'sponsorship'])->name('tree.sponsorship');

    Route::get('/line-change', [LineChangeController::class, 'show'])->name('line-change.show');
    Route::post('/line-change', [LineChangeController::class, 'submit'])->name('line-change.submit');

    // Profile + change-password (any logged-in user; admins included).
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/profile/password', [ProfileController::class, 'showPasswordForm'])->name('profile.password.show');
    Route::post('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
});
