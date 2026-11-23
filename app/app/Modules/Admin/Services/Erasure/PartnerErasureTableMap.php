<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Erasure;

/**
 * How a partner erasure treats every table that can hold a person: rows
 * deleted, rows kept with personal data stripped, or rows kept untouched.
 * Every table with a person-reference column must be in exactly one list —
 * PartnerErasureTableMapCoverageTest fails on a new table that is in none, so
 * it cannot silently keep an erased partner's data.
 */
final class PartnerErasureTableMap
{
    /**
     * Rows removed for the erase set (distributors S, their users U, their
     * shopper identities C).
     *
     * @var list<string>
     */
    public const DELETE = [
        'distributor_profiles',
        'distributor_nominees',
        'distributor_family_members',
        'consents',
        'orientation_views',
        'cooling_off_events',
        'plan_change_notices',
        'attribution_touches',
        'shared_carts',
        'line_change_requests',
        'gsb_reversal_requests',
        'kyc_documents',
        'distributor_request_documents',
        'distributor_requests',
        'arete_center_application_documents',
        'arete_center_application_declarations',
        'arete_center_applications',
        'arete_center_members',
        'bv_ledger_entries',
        'bv_propagation_log',
        'group_bv_daily',
        'group_bv_debts',
        'gsb_carryforward',
        'gsb_cutoff_deferrals',
        'gsb_personal_bv_topups',
        'rank_qualifications',
        'rank_provisional_standings',
        'rank_aogo_grants',
        'lifetime_award_milestones',
        'repurchase_cycles',
        'purchase_offer_grants',
        'redeem_point_entries',
        'fortune_bonus_participants',
        'group_bv_credits',
        'group_bv_reversals',
        'genealogy_closure',
        'sponsorship',
        'cart_items',
        'carts',
        'customer_addresses',
        'ticket_attachments',
        'ticket_events',
        'tickets',
        'messages',
        'message_blocks',
        'message_reports',
        'notifications',
        'announcement_reads',
        'password_reset_tokens',
        'model_has_roles',
        'model_has_permissions',
    ];

    /**
     * Rows kept (money, tax, the tombstone itself) with personal data replaced.
     *
     * @var list<string>
     */
    public const ANONYMISE = [
        'users',
        'distributors',
        'customers',
        'orders',
        'offline_payments',
        'payment_events',
        'payment_intents',
        'shipment_events',
        'return_requests',
        'payout_line_items',
    ];

    /**
     * Rows kept untouched: money and tax records carrying no personal data of
     * their own, frozen pools, the audit trail, third-party retail records, and
     * tables whose person columns only ever name back-office staff.
     *
     * @var list<string>
     */
    public const KEEP = [
        'invoices',
        'invoice_lines',
        'order_items',
        'order_cooling_off',
        'coupon_redemptions',
        'refund_intents',
        'buyback_decisions',
        'wallet_ledger_entries',
        'ledger_tx',
        'ledger_entries',
        'payout_batches',
        'payout_bank_files',
        'payout_bank_file_rows',
        'payout_gateway_events',
        'payout_build_failures',
        'gsb_cutoff_results',
        'gbb_monthly_results',
        'rank_bonus_results',
        'fortune_bonus_results',
        'mentorship_bonus_results',
        'adc_bonus_results',
        'gsb_daily_pools',
        'msb_daily_pools',
        'gbb_monthly_pools',
        'rank_monthly_pools',
        'rank_monthly_passes',
        'fortune_monthly_pools',
        'fortune_monthly_pool_levels',
        'audit_log',
        'engine_runs',
        'shipments',
        // An ADC owner is a blocker, so no erased partner owns a centre.
        'arete_centers',
        'arete_center_declarations',
        // Back-office actor columns only.
        'action_center_snoozes',
        'announcements',
        'content_pages',
        'coupons',
        'landing_price_history',
        'monthly_offer_products',
        'products',
        'purchase_invoices',
        'purchase_order_items',
        'purchase_orders',
        'return_inspections',
        'stock_adjustments',
        'stock_movements',
        'stock_transfers',
    ];
}
