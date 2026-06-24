<?php

use App\Modules\Admin\AdminServiceProvider;
use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Commerce\CommerceServiceProvider;
use App\Modules\Compensation\CompensationServiceProvider;
use App\Modules\Compliance\ComplianceServiceProvider;
use App\Modules\Consent\ConsentServiceProvider;
use App\Modules\Content\ContentServiceProvider;
use App\Modules\Fulfilment\FulfilmentServiceProvider;
use App\Modules\Genealogy\GenealogyServiceProvider;
use App\Modules\Grievance\GrievanceServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Kyc\KycServiceProvider;
use App\Modules\Ledger\LedgerServiceProvider;
use App\Modules\Messaging\MessagingServiceProvider;
use App\Modules\Orientation\OrientationServiceProvider;
use App\Modules\Payments\PaymentsServiceProvider;
use App\Modules\Public\PublicServiceProvider;
use App\Modules\Returns\ReturnsServiceProvider;
use App\Modules\Shared\SharedServiceProvider;
use App\Modules\Tax\TaxServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    GenealogyServiceProvider::class,
    KycServiceProvider::class,
    ConsentServiceProvider::class,
    OrientationServiceProvider::class,
    ComplianceServiceProvider::class,
    AdminServiceProvider::class,
    SharedServiceProvider::class,
    ContentServiceProvider::class,
    LedgerServiceProvider::class,
    CatalogServiceProvider::class,
    CommerceServiceProvider::class,
    CompensationServiceProvider::class,
    TaxServiceProvider::class,
    PaymentsServiceProvider::class,
    FulfilmentServiceProvider::class,
    ReturnsServiceProvider::class,
    GrievanceServiceProvider::class,
    PublicServiceProvider::class,
    MessagingServiceProvider::class,
];
