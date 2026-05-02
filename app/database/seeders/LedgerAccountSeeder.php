<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Ledger\Models\LedgerAccount;
use Illuminate\Database\Seeder;

final class LedgerAccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            // Assets
            ['code' => 'asset.cash.gateway.razorpay', 'name' => 'Cash held at Razorpay',            'type' => 'asset'],
            ['code' => 'asset.cash.bank.settlement',  'name' => 'Settlement bank account',         'type' => 'asset'],
            ['code' => 'asset.inventory',             'name' => 'Product inventory at cost',       'type' => 'asset'],
            ['code' => 'asset.gst_input_itc',         'name' => 'GST Input Tax Credit',            'type' => 'asset'],

            // Liabilities
            ['code' => 'liability.customer_prepayment', 'name' => 'Customer prepayment (paid, not delivered)', 'type' => 'liability'],
            ['code' => 'liability.commission_held',   'name' => 'Commissions held (cooling-off)',  'type' => 'liability'],
            ['code' => 'liability.commission_payable', 'name' => 'Commissions payable (unlocked)',   'type' => 'liability'],
            ['code' => 'liability.tds_payable',       'name' => 'TDS payable to Income Tax Dept',  'type' => 'liability'],
            ['code' => 'liability.gst_output',        'name' => 'GST output (collected)',          'type' => 'liability'],
            ['code' => 'liability.wallet_debt',       'name' => 'Distributor wallet debt',         'type' => 'liability'],

            // Revenue
            ['code' => 'revenue.sales',               'name' => 'Product sales revenue',           'type' => 'revenue'],
            ['code' => 'revenue.shipping',            'name' => 'Shipping revenue',                'type' => 'revenue'],
            ['code' => 'revenue.house_margin',        'name' => 'Un-attributed retail margin',     'type' => 'revenue'],
            ['code' => 'revenue.admin_charge',        'name' => 'Admin charge (3%) income',        'type' => 'revenue'],

            // Expenses
            ['code' => 'expense.cogs',                'name' => 'Cost of goods sold',              'type' => 'expense'],
            ['code' => 'expense.commission',          'name' => 'Commission expense',              'type' => 'expense'],

            // Equity
            ['code' => 'equity.retained',             'name' => 'Retained earnings',               'type' => 'equity'],
        ];

        foreach ($accounts as $a) {
            LedgerAccount::updateOrCreate(['code' => $a['code']], $a);
        }

        $this->command->info('Seeded '.count($accounts).' ledger accounts.');
    }
}
