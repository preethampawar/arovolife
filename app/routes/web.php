<?php

declare(strict_types=1);

use App\Modules\Admin\Http\Controllers\AdminAuditLogController;
use App\Modules\Admin\Http\Controllers\AdminContactController;
use App\Modules\Admin\Http\Controllers\AdminDashboardController;
use App\Modules\Admin\Http\Controllers\AdminDistributorController;
use App\Modules\Admin\Http\Controllers\AdminDistributorCreateController;
use App\Modules\Admin\Http\Controllers\AdminDistributorEditController;
use App\Modules\Admin\Http\Controllers\AdminFeatureFlagController;
use App\Modules\Admin\Http\Controllers\AdminHelpController;
use App\Modules\Admin\Http\Controllers\AdminImpersonationController;
use App\Modules\Admin\Http\Controllers\AdminKycController;
use App\Modules\Admin\Http\Controllers\AdminLineChangeController;
use App\Modules\Admin\Http\Controllers\AdminSettingsController;
use App\Modules\Admin\Http\Controllers\AdminTreeController;
use App\Modules\Catalog\Http\Controllers\Admin\AdminBannerController;
use App\Modules\Catalog\Http\Controllers\Admin\AdminCategoryController;
use App\Modules\Catalog\Http\Controllers\Admin\AdminProductController;
use App\Modules\Commerce\Http\Controllers\Admin\AdminBvLedgerController;
use App\Modules\Commerce\Http\Controllers\Admin\AdminCouponController;
use App\Modules\Commerce\Http\Controllers\Admin\AdminOrderController;
use App\Modules\Commerce\Http\Controllers\Storefront\AddressController;
use App\Modules\Commerce\Http\Controllers\Storefront\CartController;
use App\Modules\Commerce\Http\Controllers\Storefront\CheckoutController;
use App\Modules\Commerce\Http\Controllers\Storefront\MyOrdersController;
use App\Modules\Commerce\Http\Controllers\Storefront\ShopController;
use App\Modules\Compliance\Http\Controllers\Admin\AdminComplianceDocumentController;
use App\Modules\Compliance\Http\Controllers\CoolingOffController;
use App\Modules\Compliance\Http\Controllers\PublicComplianceDocumentController;
use App\Modules\Content\Http\Controllers\Admin\AdminContentPageController;
use App\Modules\Content\Http\Controllers\Public\PublicContentPageController;
use App\Modules\Genealogy\Http\Controllers\LineChangeController;
use App\Modules\Genealogy\Http\Controllers\TreeController;
use App\Modules\Identity\Http\Controllers\Auth\LoginController;
use App\Modules\Identity\Http\Controllers\Auth\PasswordResetController;
use App\Modules\Identity\Http\Controllers\Auth\SpouseActivationController;
use App\Modules\Identity\Http\Controllers\DashboardController;
use App\Modules\Identity\Http\Controllers\DirectSellerApplicationController;
use App\Modules\Identity\Http\Controllers\DistributorDetailsController;
use App\Modules\Identity\Http\Controllers\IdPhotoController;
use App\Modules\Identity\Http\Controllers\KycDocumentSelfServiceController;
use App\Modules\Identity\Http\Controllers\KycResubmitController;
use App\Modules\Identity\Http\Controllers\MembershipCardController;
use App\Modules\Identity\Http\Controllers\ProfileController;
use App\Modules\Identity\Http\Controllers\ProfileStatsController;
use App\Modules\Identity\Http\Controllers\Registration\RegistrationWizardController;
use App\Modules\Identity\Http\Controllers\TaxStatementsController;
use App\Modules\Identity\Http\Controllers\TeamRosterController;
use App\Modules\Kyc\Http\Controllers\KycDocumentReuploadController;
use App\Modules\Messaging\Http\Controllers\MessageController;
use App\Modules\Public\Http\Controllers\ContactController;
use App\Modules\Public\Http\Controllers\FindMyIdController;
use App\Modules\Returns\Http\Controllers\Admin\AdminReturnController;
use App\Modules\Returns\Http\Controllers\Storefront\ReturnController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('landing.index'))->name('home');
Route::get('/about-us', fn () => view('landing.about'))->name('about');

// ── Authentication ───────────────────────────────────────────────────────────

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.post');

    // Public registration entry.
    //   /register?sponsor=X&placement=Y — referral-link form (ADR-0003);
    //                                     stashes intent, redirects to step 1
    //   /join                            — back-compat alias for the
    //                                     step-1 sponsor-placement form
    Route::get('/register', [RegistrationWizardController::class, 'start'])->name('register');
    Route::get('/join', [RegistrationWizardController::class, 'showJoin'])->name('join.show');
    Route::post('/join', [RegistrationWizardController::class, 'handleJoin'])->name('join.submit');

    // ADN-name lookup used by step 1's live name-resolution UI.
    Route::get('/join/lookup', [RegistrationWizardController::class, 'lookupAdn'])
        ->middleware('throttle:30,1')->name('join.lookup');

    // Step 2 — create account. Requires the intent from step 1.
    Route::get('/register/account', [RegistrationWizardController::class, 'showAccount'])->name('register.account.show');
    Route::post('/register/account', [RegistrationWizardController::class, 'handleAccount'])->name('register.post');

    // Real-time availability check for email + phone uniqueness — called via
    // AJAX on blur from step 2 so users see "this email is already registered"
    // before submitting and going through the whole wizard.
    Route::get('/register/check-availability', [RegistrationWizardController::class, 'checkAvailability'])
        ->middleware('throttle:60,1')->name('register.check-availability');

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

// ── Registration Wizard (steps 3-10, auth-gated) ─────────────────────────────
//
// New step order (2026-05):
//   1. Sponsor & Placement   /register (start → /join)            public
//   2. Account               /register/account                    public
//   3. Orientation           /register/orientation                auth
//   4. Consent               /register/consent                    auth
//   5. PAN                   /register/kyc/pan                    auth
//   6. Aadhaar               /register/kyc/aadhaar                auth
//   7. Bank                  /register/kyc/bank                   auth
//   8. Personal              /register/personal                   auth
//   9. Documents             /register/documents                  auth
//  10. Complete              /register/complete                   auth

// Wizard steps 3..10 are gated by `wizard.progress` middleware. The session
// must be active and have valid wizard state at each step — users mid-flow
// who lose their session must restart from step 1.
Route::middleware([])->group(function (): void {
    Route::get('/register/orientation', [RegistrationWizardController::class, 'showOrientation'])
        ->middleware('wizard.progress:3')->name('register.orientation');
    Route::post('/register/orientation', [RegistrationWizardController::class, 'handleOrientation'])
        ->middleware('wizard.progress:3');

    Route::get('/register/consent', [RegistrationWizardController::class, 'showConsent'])
        ->middleware('wizard.progress:4')->name('register.consent');
    Route::post('/register/consent', [RegistrationWizardController::class, 'handleConsent'])
        ->middleware('wizard.progress:4');

    Route::get('/register/kyc/pan', [RegistrationWizardController::class, 'showPan'])
        ->middleware('wizard.progress:5')->name('register.pan');
    Route::post('/register/kyc/pan', [RegistrationWizardController::class, 'handlePan'])
        ->middleware('wizard.progress:5');

    Route::get('/register/kyc/aadhaar', [RegistrationWizardController::class, 'showAadhaar'])
        ->middleware('wizard.progress:6')->name('register.aadhaar');
    Route::post('/register/kyc/aadhaar', [RegistrationWizardController::class, 'handleAadhaar'])
        ->middleware('wizard.progress:6');

    Route::get('/register/kyc/bank', [RegistrationWizardController::class, 'showBank'])
        ->middleware('wizard.progress:7')->name('register.bank');
    Route::post('/register/kyc/bank', [RegistrationWizardController::class, 'handleBank'])
        ->middleware('wizard.progress:7');

    Route::get('/register/personal', [RegistrationWizardController::class, 'showPersonal'])
        ->middleware('wizard.progress:8')->name('register.personal');
    Route::post('/register/personal', [RegistrationWizardController::class, 'handlePersonal'])
        ->middleware('wizard.progress:8');

    Route::get('/register/documents', [RegistrationWizardController::class, 'showDocuments'])
        ->middleware('wizard.progress:9')->name('register.documents');
    Route::post('/register/documents', [RegistrationWizardController::class, 'handleDocuments'])
        ->middleware('wizard.progress:9');

    Route::get('/register/complete', [RegistrationWizardController::class, 'showComplete'])
        ->middleware('wizard.progress:10')->name('register.complete');
    Route::post('/register/complete', [RegistrationWizardController::class, 'handleComplete'])
        ->middleware('wizard.progress:10');
});

// ── Admin Console ────────────────────────────────────────────────────────────

// The admin area admits the whole admin family (R-17). `admin` is the
// super-admin (Gate::before); the scoped roles can browse the console but each
// sensitive action is additionally gated by a `can:` permission so e.g.
// admin-finance can't freeze and admin-compliance can't record payments.
Route::middleware(['auth', 'role:admin|admin-operations|admin-finance|admin-compliance'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

    Route::get('/distributors', [AdminDistributorController::class, 'index'])->name('distributors.index');
    Route::get('/distributors/export', [AdminDistributorController::class, 'export'])->name('distributors.export');

    // Admin-created distributor (paper-onboarding flow). MUST appear before
    // the /distributors/{id} catch-all so `/create` doesn't resolve to an
    // id of "create".
    Route::get('/distributors/create', [AdminDistributorCreateController::class, 'create'])->name('distributors.create');
    Route::post('/distributors', [AdminDistributorCreateController::class, 'store'])->name('distributors.store');

    Route::get('/distributors/{id}', [AdminDistributorController::class, 'show'])->whereNumber('id')->name('distributors.show');
    Route::get('/distributors/{id}/edit', [AdminDistributorEditController::class, 'edit'])->whereNumber('id')->name('distributors.edit');
    Route::patch('/distributors/{id}', [AdminDistributorEditController::class, 'update'])->whereNumber('id')->name('distributors.update');
    Route::post('/distributors/{id}/password-reset', [AdminDistributorEditController::class, 'sendPasswordReset'])->whereNumber('id')->name('distributors.password-reset');
    Route::post('/distributors/{id}/set-password', [AdminDistributorEditController::class, 'setPassword'])->whereNumber('id')->name('distributors.set-password');
    Route::post('/distributors/{id}/identity', [AdminDistributorEditController::class, 'updateIdentity'])->whereNumber('id')->name('distributors.identity');
    Route::post('/distributors/{id}/id-photo', [AdminDistributorEditController::class, 'updateIdPhoto'])->whereNumber('id')->name('distributors.id-photo');
    // Account discipline (block / unblock / terminate) — admin-compliance (R-17).
    Route::post('/distributors/{id}/freeze', [AdminDistributorController::class, 'freeze'])->whereNumber('id')->middleware('can:compliance.discipline')->name('distributors.freeze');
    Route::post('/distributors/{id}/unfreeze', [AdminDistributorController::class, 'unfreeze'])->whereNumber('id')->middleware('can:compliance.discipline')->name('distributors.unfreeze');
    Route::post('/distributors/{id}/terminate', [AdminDistributorController::class, 'terminate'])->whereNumber('id')->middleware('can:compliance.discipline')->name('distributors.terminate');
    Route::post('/distributors/{id}/activate', [AdminDistributorController::class, 'activate'])->whereNumber('id')->name('distributors.activate');
    Route::post('/distributors/{id}/deactivate', [AdminDistributorController::class, 'deactivate'])->whereNumber('id')->name('distributors.deactivate');

    Route::get('/settings', [AdminSettingsController::class, 'index'])->name('settings');
    Route::post('/settings/age-rules', [AdminSettingsController::class, 'updateStateAgeMinimums'])->name('settings.age-rules');
    // Per-setting update from the friendly UI cards. The {key} param is the
    // dotted setting key (e.g. commerce.checkout.enabled). The controller
    // matches it against the registry and aborts 404 if not registered.
    Route::post('/settings/{key}', [AdminSettingsController::class, 'update'])
        ->where('key', '[a-z0-9_.-]+')
        ->name('settings.update');

    Route::get('/audit-log', [AdminAuditLogController::class, 'index'])->name('audit-log');

    // Help & Reference — in-admin rendering of curated docs/ markdown.
    Route::get('/help', [AdminHelpController::class, 'index'])->name('help.index');
    Route::get('/help/{slug}', [AdminHelpController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')->name('help.show');

    // Feature flags — admin-toggleable runtime switches (T-5.4). Includes the
    // registration killswitch; every toggle writes an audit_log entry.
    Route::get('/feature-flags', [AdminFeatureFlagController::class, 'index'])
        ->name('feature-flags.index');
    Route::post('/feature-flags/{key}', [AdminFeatureFlagController::class, 'toggle'])
        ->where('key', '[a-z0-9_.-]+')
        ->name('feature-flags.toggle');

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
    Route::post('/kyc/{id}/terminate', [AdminKycController::class, 'terminate'])->name('kyc.terminate');
    Route::post('/kyc/{id}/document', [AdminKycController::class, 'uploadDocument'])->whereNumber('id')->name('kyc.document.upload');
    Route::post('/kyc/{id}/documents/{docId}/flag', [AdminKycController::class, 'flagDocument'])
        ->whereNumber('id')->whereNumber('docId')->name('kyc.document.flag');

    // Line-change requests — review queue + approve/reject
    Route::get('/line-changes', [AdminLineChangeController::class, 'index'])->name('line-changes.index');
    Route::get('/line-changes/{id}', [AdminLineChangeController::class, 'show'])->whereNumber('id')->name('line-changes.show');
    // Line-change decisions — admin-operations (R-17).
    Route::post('/line-changes/{id}/approve', [AdminLineChangeController::class, 'approve'])->whereNumber('id')->middleware('can:placement.decide')->name('line-changes.approve');
    Route::post('/line-changes/{id}/reject', [AdminLineChangeController::class, 'reject'])->whereNumber('id')->middleware('can:placement.decide')->name('line-changes.reject');

    // Commerce — orders
    Route::get('/commerce/orders', [AdminOrderController::class, 'index'])->name('commerce.orders.index');
    Route::get('/commerce/orders/{order}', [AdminOrderController::class, 'show'])->name('commerce.orders.show');
    Route::post('/commerce/orders/{order}/ship', [AdminOrderController::class, 'markShipped'])->name('commerce.orders.ship');
    Route::post('/commerce/orders/{order}/deliver', [AdminOrderController::class, 'markDelivered'])->name('commerce.orders.deliver');
    // Recording money received — admin-finance (R-17 / R-20).
    Route::post('/commerce/orders/{order}/mark-cod-paid', [AdminOrderController::class, 'markCodPaid'])->middleware('can:finance.record')->name('commerce.orders.mark-cod-paid');
    Route::post('/commerce/orders/{order}/cancel', [AdminOrderController::class, 'cancel'])->name('commerce.orders.cancel');

    // Returns — admin inspection / approve / reject (finance.record, R-17; ADR-0009).
    Route::get('/returns', [AdminReturnController::class, 'index'])->name('returns.index');
    Route::get('/returns/{return}', [AdminReturnController::class, 'show'])->whereNumber('return')->name('returns.show');
    Route::post('/returns/{return}/inspect', [AdminReturnController::class, 'inspect'])->middleware('can:finance.record')->whereNumber('return')->name('returns.inspect');
    Route::post('/returns/{return}/approve', [AdminReturnController::class, 'approve'])->middleware('can:finance.record')->whereNumber('return')->name('returns.approve');
    Route::post('/returns/{return}/reject', [AdminReturnController::class, 'reject'])->middleware('can:finance.record')->whereNumber('return')->name('returns.reject');

    // Commerce — BV Ledger report (admin financial reporting; ADR-0006).
    // The static `export` path is declared before the {distributor} wildcard
    // so "export" is never captured as a distributor id.
    Route::get('/commerce/bv-ledger', [AdminBvLedgerController::class, 'index'])->name('commerce.bv-ledger.index');
    Route::get('/commerce/bv-ledger/export', [AdminBvLedgerController::class, 'export'])->name('commerce.bv-ledger.export');
    Route::get('/commerce/bv-ledger/{distributor}', [AdminBvLedgerController::class, 'show'])->whereNumber('distributor')->name('commerce.bv-ledger.show');
    Route::get('/commerce/bv-ledger/{distributor}/export', [AdminBvLedgerController::class, 'exportShow'])->whereNumber('distributor')->name('commerce.bv-ledger.show.export');

    // Commerce — coupons / discounts (Epic 3)
    Route::get('/commerce/coupons', [AdminCouponController::class, 'index'])->name('commerce.coupons.index');
    Route::get('/commerce/coupons/create', [AdminCouponController::class, 'create'])->name('commerce.coupons.create');
    Route::post('/commerce/coupons', [AdminCouponController::class, 'store'])->name('commerce.coupons.store');
    Route::get('/commerce/coupons/{coupon}/edit', [AdminCouponController::class, 'edit'])->name('commerce.coupons.edit');
    Route::put('/commerce/coupons/{coupon}', [AdminCouponController::class, 'update'])->name('commerce.coupons.update');
    Route::post('/commerce/coupons/{coupon}/archive', [AdminCouponController::class, 'archive'])->name('commerce.coupons.archive');

    // Catalog — products & categories (Epic 1)
    Route::get('/catalog/products', [AdminProductController::class, 'index'])->name('catalog.products.index');
    Route::get('/catalog/products/create', [AdminProductController::class, 'create'])->name('catalog.products.create');
    Route::post('/catalog/products', [AdminProductController::class, 'store'])->name('catalog.products.store');
    Route::get('/catalog/products/{product}/edit', [AdminProductController::class, 'edit'])->name('catalog.products.edit');
    Route::put('/catalog/products/{product}', [AdminProductController::class, 'update'])->name('catalog.products.update');
    Route::post('/catalog/products/{product}/archive', [AdminProductController::class, 'archive'])->name('catalog.products.archive');
    Route::delete('/catalog/images/{image}', [AdminProductController::class, 'deleteImage'])->name('catalog.images.destroy');
    // WYSIWYG inline-image upload target (Trix attachment add event).
    Route::post('/catalog/trix-upload', [AdminProductController::class, 'trixUpload'])->name('catalog.trix-upload');

    Route::get('/catalog/categories', [AdminCategoryController::class, 'index'])->name('catalog.categories.index');
    Route::get('/catalog/categories/create', [AdminCategoryController::class, 'create'])->name('catalog.categories.create');
    Route::post('/catalog/categories', [AdminCategoryController::class, 'store'])->name('catalog.categories.store');
    Route::get('/catalog/categories/{category}/edit', [AdminCategoryController::class, 'edit'])->name('catalog.categories.edit');
    Route::put('/catalog/categories/{category}', [AdminCategoryController::class, 'update'])->name('catalog.categories.update');
    Route::post('/catalog/categories/{category}/archive', [AdminCategoryController::class, 'archive'])->name('catalog.categories.archive');

    // Storefront shopping-mall carousel banners
    Route::get('/catalog/banners', [AdminBannerController::class, 'index'])->name('catalog.banners.index');
    Route::get('/catalog/banners/create', [AdminBannerController::class, 'create'])->name('catalog.banners.create');
    Route::post('/catalog/banners', [AdminBannerController::class, 'store'])->name('catalog.banners.store');
    Route::get('/catalog/banners/{banner}/edit', [AdminBannerController::class, 'edit'])->name('catalog.banners.edit');
    Route::put('/catalog/banners/{banner}', [AdminBannerController::class, 'update'])->name('catalog.banners.update');
    Route::delete('/catalog/banners/{banner}', [AdminBannerController::class, 'destroy'])->name('catalog.banners.destroy');

    // Content pages CRUD
    Route::get('/content', [AdminContentPageController::class, 'index'])->name('content.index');
    Route::get('/content/create', [AdminContentPageController::class, 'create'])->name('content.create');
    Route::post('/content', [AdminContentPageController::class, 'store'])->name('content.store');
    Route::get('/content/{page}/edit', [AdminContentPageController::class, 'edit'])->name('content.edit');
    Route::patch('/content/{page}', [AdminContentPageController::class, 'update'])->name('content.update');
    Route::delete('/content/{page}', [AdminContentPageController::class, 'destroy'])->name('content.destroy');

    // Compliance documents — admin upload/manage; published ones are listed
    // publicly at /compliance-documents.
    Route::get('/compliance-documents', [AdminComplianceDocumentController::class, 'index'])->name('compliance-documents.index');
    Route::post('/compliance-documents', [AdminComplianceDocumentController::class, 'store'])->name('compliance-documents.store');
    Route::patch('/compliance-documents/{document}/toggle', [AdminComplianceDocumentController::class, 'togglePublish'])->name('compliance-documents.toggle');
    Route::delete('/compliance-documents/{document}', [AdminComplianceDocumentController::class, 'destroy'])->name('compliance-documents.destroy');

    // Genealogy — admin can view the entire company tree, or any distributor's
    // subtree. The id-less route shows the company root (Distributor whose
    // sponsor_id == its own id); /admin/tree/{id} scopes to that subtree.
    // Global tree search — admin can locate anyone. Declared before the
    // /tree/{id?} numeric route; "search" isn't numeric so it wouldn't collide
    // regardless. Throttled to match the distributor search.
    Route::get('/tree/search', [AdminTreeController::class, 'search'])
        ->middleware('throttle:30,1')
        ->name('tree.search');
    // Live typeahead suggestions — declared before /tree/{id?} (non-numeric, so
    // it wouldn't collide anyway). Throttled higher than search to allow keystroke
    // fetches.
    Route::get('/tree/suggest', [AdminTreeController::class, 'suggest'])
        ->middleware('throttle:60,1')
        ->name('tree.suggest');
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

// KYC document re-upload (single-document, reached via signed email link).
// Sits OUTSIDE the rejected-resubmit middleware so a flagged pending user
// can land here without being redirected to the full resubmit flow.
Route::middleware('auth')->group(function (): void {
    Route::get('/kyc/reupload/{document}', [KycDocumentReuploadController::class, 'show'])
        ->whereNumber('document')
        ->middleware('signed')
        ->name('kyc.reupload.show');
    Route::post('/kyc/reupload/{document}', [KycDocumentReuploadController::class, 'store'])
        ->whereNumber('document')
        ->name('kyc.reupload.store');
});

// ── Public Content Pages ─────────────────────────────────────────────────────

Route::get('/p/{slug}', [PublicContentPageController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('content.show');

// Public compliance documents — listing + streamed download (published only).
Route::get('/compliance-documents', [PublicComplianceDocumentController::class, 'index'])
    ->name('compliance-documents.index');
Route::get('/compliance-documents/{document}/download', [PublicComplianceDocumentController::class, 'download'])
    ->whereNumber('document')
    ->name('compliance-documents.download');

// ── Contact Us ───────────────────────────────────────────────────────────────

Route::get('/contact-us', [ContactController::class, 'show'])->name('contact.show');
Route::post('/contact-us', [ContactController::class, 'submit'])->name('contact.submit');

// Find My ID — recover a forgotten ADN by registered name + PAN. Throttled at
// the route on top of the controller's per-IP limiter (anti-enumeration).
Route::get('/find-my-id', [FindMyIdController::class, 'show'])->name('find-my-id.show');
Route::post('/find-my-id', [FindMyIdController::class, 'lookup'])
    ->middleware('throttle:8,10')->name('find-my-id.lookup');

// ── Public Storefront (Commerce) ─────────────────────────────────────────────

Route::middleware('capture.attribution')->group(function (): void {
    Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
    Route::get('/shop/p/{slug}', [ShopController::class, 'show'])->name('shop.product');

    Route::get('/shop/cart', [CartController::class, 'show'])->name('shop.cart');
    Route::post('/shop/cart/add', [CartController::class, 'add'])->name('shop.cart.add');
    Route::patch('/shop/cart/items/{item}', [CartController::class, 'update'])->name('shop.cart.update');
    Route::delete('/shop/cart/items/{item}', [CartController::class, 'remove'])->name('shop.cart.remove');
    Route::delete('/shop/cart', [CartController::class, 'clearAll'])->name('shop.cart.clear');
    Route::post('/shop/cart/coupon', [CartController::class, 'applyCoupon'])->name('shop.cart.coupon.apply');
    Route::delete('/shop/cart/coupon', [CartController::class, 'removeCoupon'])->name('shop.cart.coupon.remove');

    // Multi-product "Easy Purchase": share the current cart, and open a shared one.
    Route::post('/shop/cart/share', [CartController::class, 'share'])->name('shop.cart.share');
    Route::get('/shop/easy-cart/{code}', [CartController::class, 'openShared'])->name('shop.easy-cart');

    Route::get('/shop/checkout', [CheckoutController::class, 'show'])->name('shop.checkout');
    Route::post('/shop/checkout', [CheckoutController::class, 'place'])->name('shop.checkout.place');
    Route::get('/shop/confirmation/{orderNo}', [CheckoutController::class, 'confirmation'])->name('shop.confirmation');
});

// ── Authenticated App ────────────────────────────────────────────────────────

// KYC re-upload page for a rejected distributor. Lives OUTSIDE the
// kyc.rejected.resubmit-protected group below because the middleware
// allowlists this exact path — without the explicit declaration here we'd
// either get an infinite redirect or block legitimate access.
Route::middleware(['auth'])->group(function (): void {
    Route::get('/kyc/resubmit', [KycResubmitController::class, 'show'])
        ->name('kyc.resubmit.show');
    Route::post('/kyc/resubmit', [KycResubmitController::class, 'submit'])
        ->name('kyc.resubmit.submit');
});

Route::middleware(['auth', 'kyc.rejected.resubmit'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Printable membership card (front + back, save-as-PDF / print).
    Route::get('/dashboard/membership-card', [MembershipCardController::class, 'show'])->name('membership-card.show');
    // Printable Profile Stats panel (save-as-PDF / print).
    Route::get('/dashboard/profile-stats', [ProfileStatsController::class, 'show'])->name('profile-stats.show');
    Route::get('/dashboard/direct-seller-application', [DirectSellerApplicationController::class, 'show'])->name('direct-seller-application.show');
    Route::get('/dashboard/tax-statements', [TaxStatementsController::class, 'show'])->name('tax-statements.show');

    // The distributor's own order history (BV accumulation + cooling-off status).
    Route::get('/orders', [MyOrdersController::class, 'index'])->name('orders.index');
    // Literal path before {orderNo} wildcard to prevent route collision.
    Route::get('/orders/sales', [MyOrdersController::class, 'mySales'])->name('orders.sales');
    Route::get('/orders/{orderNo}', [MyOrdersController::class, 'show'])->name('orders.show');
    Route::get('/orders/{orderNo}/invoice', [MyOrdersController::class, 'invoice'])->name('orders.invoice');
    Route::post('/orders/{orderNo}/cancel', [MyOrdersController::class, 'cancel'])->name('orders.cancel');

    // Returns — customer-initiated (cooling-off + buyback; ADR-0009).
    Route::get('/orders/{orderNo}/return', [ReturnController::class, 'create'])->name('orders.return.create');
    Route::post('/orders/{orderNo}/return', [ReturnController::class, 'store'])->name('orders.return.store');

    // Saved shipping-address book ("My Addresses") — reused at checkout.
    Route::get('/addresses', [AddressController::class, 'index'])->name('addresses.index');
    Route::post('/addresses', [AddressController::class, 'store'])->name('addresses.store');
    Route::patch('/addresses/{address}', [AddressController::class, 'update'])->whereNumber('address')->name('addresses.update');
    Route::post('/addresses/{address}/default', [AddressController::class, 'setDefault'])->whereNumber('address')->name('addresses.set-default');
    Route::delete('/addresses/{address}', [AddressController::class, 'destroy'])->whereNumber('address')->name('addresses.destroy');

    // Stat-card → roster modal (and downloadable CSV) for the four headline
    // team counts on the dashboard. Scope guard mirrors TeamStatsService::roster.
    Route::get('/dashboard/team-roster/{scope}', [TeamRosterController::class, 'index'])
        ->where('scope', 'total|direct|left|right')
        ->name('dashboard.team-roster');
    Route::get('/dashboard/team-roster/{scope}/download', [TeamRosterController::class, 'download'])
        ->where('scope', 'total|direct|left|right')
        ->name('dashboard.team-roster.download');

    Route::get('/cooling-off', [CoolingOffController::class, 'show'])->name('cooling-off.show');
    Route::post('/cooling-off/cancel', [CoolingOffController::class, 'cancel'])->name('cooling-off.cancel');

    // /tree           → tree rooted at the auth user (default)
    // /tree/{adn}     → re-rooted at any of their descendants (server-side
    //                   enforces "must be in my subtree"). The ADN appears
    //                   in the URL so each subtree-pivot creates a fresh
    //                   browser-history entry, and back/forward navigation
    //                   Just Works.
    // Sponsorship view declared BEFORE the binary catchall so a literal
    // "sponsorship" path segment is matched here rather than failing the
    // binary route's [0-9]{9}(-S)? guard.
    Route::get('/tree/sponsorship/{adn?}', [TreeController::class, 'sponsorship'])
        ->where('adn', '[0-9]{9}(-S)?')
        ->name('tree.sponsorship');
    // Search the auth user's OWN downline by ADN/name/email/phone. Declared
    // before the binary catchall; the literal "search" segment wouldn't match
    // the [0-9]{9} guard anyway, but ordering keeps intent explicit. Throttled
    // to blunt enumeration attempts against the name/phone LIKE predicates.
    Route::get('/tree/search', [TreeController::class, 'search'])
        ->middleware('throttle:30,1')
        ->name('tree.search');
    // Live typeahead suggestions for the same scoped downline search. Declared
    // before the binary catchall ("suggest" isn't a 9-digit ADN anyway).
    // Throttled higher than search to absorb per-keystroke fetches.
    Route::get('/tree/suggest', [TreeController::class, 'suggest'])
        ->middleware('throttle:60,1')
        ->name('tree.suggest');
    Route::get('/tree/{adn?}', [TreeController::class, 'binary'])
        ->where('adn', '[0-9]{9}(-S)?')
        ->name('tree.binary');

    Route::get('/line-change', [LineChangeController::class, 'show'])->name('line-change.show');
    Route::post('/line-change', [LineChangeController::class, 'submit'])->name('line-change.submit');

    // Profile + change-password (any logged-in user; admins included).
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    // Throttled: update() issues+emails an OTP, confirm() verifies it — cap
    // both so a code can't be email-spammed or its attempt counter reset.
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('throttle:6,10')->name('profile.update');
    // OTP confirmation for a mobile/email change.
    Route::post('/profile/contact-otp', [ProfileController::class, 'confirmOtp'])->middleware('throttle:10,10')->name('profile.otp.confirm');
    Route::post('/profile/contact-otp/resend', [ProfileController::class, 'resendOtp'])->middleware('throttle:6,10')->name('profile.otp.resend');
    Route::get('/profile/password', [ProfileController::class, 'showPasswordForm'])->name('profile.password.show');
    Route::post('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    // ID-card photo — self-uploaded, surfaced on the dashboard.
    Route::post('/profile/id-photo', [IdPhotoController::class, 'update'])->name('profile.id-photo.update');
    Route::delete('/profile/id-photo', [IdPhotoController::class, 'destroy'])->name('profile.id-photo.destroy');

    // KYC document self-service — the customer can add or replace the
    // optional cheque + address-proof docs that the wizard now skips.
    Route::get('/dashboard/documents', [KycDocumentSelfServiceController::class, 'index'])->name('dashboard.documents');
    Route::post('/dashboard/documents', [KycDocumentSelfServiceController::class, 'store'])->name('dashboard.documents.store');

    // Returns the Blade-rendered ID-card panel for any distributor the
    // requester is authorized to see (self, descendant, or admin).
    // Consumed by the tree-view "Details" modal — same source-of-truth
    // service as the dashboard panel.
    Route::get('/distributors/{distributor}/id-card-panel', [DistributorDetailsController::class, 'show'])
        ->name('distributor.id-card-panel');

    // Direct messages — auth-only, no further restriction beyond "you
    // can only read threads you're part of" inside the controller.
    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/{user}', [MessageController::class, 'show'])
        ->whereNumber('user')->name('messages.show');
    Route::post('/messages/{user}', [MessageController::class, 'store'])
        ->whereNumber('user')->name('messages.store');
});
