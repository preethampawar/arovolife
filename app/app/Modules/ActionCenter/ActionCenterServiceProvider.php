<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter;

use App\Modules\ActionCenter\Contracts\ActionProvider;
use App\Modules\ActionCenter\Providers\Compliance\ContentRequiredPageUnpublishedProvider;
use App\Modules\ActionCenter\Providers\Compliance\GrievanceSlaDueOrBreachedProvider;
use App\Modules\ActionCenter\Providers\Compliance\GrievanceThirdPartyOverdueProvider;
use App\Modules\ActionCenter\Providers\Compliance\MessagingReportedPendingProvider;
use App\Modules\ActionCenter\Providers\Money\PaymentsUnreconciledProvider;
use App\Modules\ActionCenter\Providers\Money\PayoutBatchAwaitingApprovalProvider;
use App\Modules\ActionCenter\Providers\Money\PayoutBatchPartiallyFailedProvider;
use App\Modules\ActionCenter\Providers\Money\PayoutsBankDetailsMissingProvider;
use App\Modules\ActionCenter\Providers\Money\RefundsFailedProvider;
use App\Modules\ActionCenter\Providers\Money\RefundsManualOwedProvider;
use App\Modules\ActionCenter\Providers\Money\RefundsPastPromiseProvider;
use App\Modules\ActionCenter\Providers\Orders\InvoiceMissingProvider;
use App\Modules\ActionCenter\Providers\Orders\PackedNotShippedProvider;
use App\Modules\ActionCenter\Providers\Orders\PaidNotPackedProvider;
use App\Modules\ActionCenter\Providers\Orders\RestockNotReconciledProvider;
use App\Modules\ActionCenter\Providers\Orders\ShippedNotDeliveredProvider;
use App\Modules\ActionCenter\Providers\Orders\UnpaidExpiringProvider;
use App\Modules\ActionCenter\Providers\People\AdcApplicationsPendingProvider;
use App\Modules\ActionCenter\Providers\People\CoolingOffExpiringProvider;
use App\Modules\ActionCenter\Providers\People\DistributorRequestsOpenProvider;
use App\Modules\ActionCenter\Providers\People\FrozenStaleProvider;
use App\Modules\ActionCenter\Providers\People\KycPendingReviewProvider;
use App\Modules\ActionCenter\Providers\People\LineChangePendingProvider;
use App\Modules\ActionCenter\Providers\Platform\EngineRunsFailedProvider;
use App\Modules\ActionCenter\Providers\Platform\FailedJobsProvider;
use App\Modules\ActionCenter\Providers\Returns\AwaitingInspectionProvider;
use App\Modules\ActionCenter\Providers\Returns\AwaitingReceiptProvider;
use App\Modules\ActionCenter\Providers\Stock\ExpiredOnHandProvider;
use App\Modules\ActionCenter\Providers\Stock\ExpiringProvider;
use App\Modules\ActionCenter\Providers\Stock\GrnDraftStaleProvider;
use App\Modules\ActionCenter\Providers\Stock\LedgerDriftProvider;
use App\Modules\ActionCenter\Providers\Stock\LowStockProvider;
use App\Modules\ActionCenter\Providers\Stock\PoOverdueProvider;
use App\Modules\ActionCenter\Providers\Stock\TransferInTransitProvider;
use App\Modules\ActionCenter\Services\ActionCenterRegistry;
use App\Modules\ActionCenter\Services\ActionCenterService;
use App\Modules\ActionCenter\Services\ActionCenterSettings;
use Illuminate\Support\ServiceProvider;

/**
 * Providers are registered here in catalogue order (plan §4) and tagged, so a
 * later slice adds an action type by writing the provider class and adding one
 * line to `PROVIDERS` — nothing else in the module changes.
 */
final class ActionCenterServiceProvider extends ServiceProvider
{
    /**
     * In catalogue order. Later slices append their types here.
     *
     * @var array<int, class-string<ActionProvider>>
     */
    private const PROVIDERS = [
        PaidNotPackedProvider::class,
        PackedNotShippedProvider::class,
        ShippedNotDeliveredProvider::class,
        UnpaidExpiringProvider::class,
        RestockNotReconciledProvider::class,
        AwaitingInspectionProvider::class,
        AwaitingReceiptProvider::class,
        InvoiceMissingProvider::class,
        LowStockProvider::class,
        ExpiringProvider::class,
        ExpiredOnHandProvider::class,
        LedgerDriftProvider::class,
        TransferInTransitProvider::class,
        GrnDraftStaleProvider::class,
        PoOverdueProvider::class,
        RefundsFailedProvider::class,
        RefundsManualOwedProvider::class,
        RefundsPastPromiseProvider::class,
        PayoutBatchAwaitingApprovalProvider::class,
        PayoutBatchPartiallyFailedProvider::class,
        PayoutsBankDetailsMissingProvider::class,
        PaymentsUnreconciledProvider::class,
        KycPendingReviewProvider::class,
        DistributorRequestsOpenProvider::class,
        LineChangePendingProvider::class,
        AdcApplicationsPendingProvider::class,
        CoolingOffExpiringProvider::class,
        FrozenStaleProvider::class,
        GrievanceSlaDueOrBreachedProvider::class,
        GrievanceThirdPartyOverdueProvider::class,
        MessagingReportedPendingProvider::class,
        ContentRequiredPageUnpublishedProvider::class,
        EngineRunsFailedProvider::class,
        FailedJobsProvider::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ActionCenterSettings::class);

        foreach (self::PROVIDERS as $provider) {
            $this->app->singleton($provider);
        }

        $this->app->tag(self::PROVIDERS, 'action-center.providers');

        $this->app->singleton(
            ActionCenterRegistry::class,
            fn ($app): ActionCenterRegistry => new ActionCenterRegistry($app->tagged('action-center.providers')),
        );

        $this->app->singleton(ActionCenterService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
