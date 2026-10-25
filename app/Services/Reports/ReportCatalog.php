<?php

namespace App\Services\Reports;

use App\Enums\Role;
use App\Models\User;

/**
 * Every report: title, group, description, how many days a range may span (heavy detail
 * reports are limited; summary-backed ones may span years; snapshots ignore the range) and who
 * may open it. The Reports module itself is admin, manager and accountant (Navigation).
 */
final class ReportCatalog
{
    public const GROUPS = [
        'sales' => 'Sales',
        'control' => 'Discounts & returns',
        'stock' => 'Stock',
        'purchasing' => 'Purchasing',
        'finance' => 'Payments',
    ];

    /**
     * @return array<string, array{title: string, group: string, max_days: int, description: string, snapshot?: bool, profit?: bool}>
     */
    public static function all(): array
    {
        return [
            'sales' => ['title' => 'Sales summary', 'group' => 'sales', 'max_days' => 1100, 'description' => 'Invoiced sales, returns and collections by day, location, channel and salesperson, compared with the previous period.'],
            'products' => ['title' => 'Sales by product', 'group' => 'sales', 'max_days' => 1100, 'description' => 'Quantities and revenue per product and category, with returns.'],
            'profit' => ['title' => 'Gross profit', 'group' => 'sales', 'max_days' => 1100, 'profit' => true, 'description' => 'Revenue without VAT less average cost, by product, category and location.'],
            'quotations' => ['title' => 'Quotation conversion', 'group' => 'sales', 'max_days' => 370, 'description' => 'Quotations sent, won, lost and converted to orders; conversion rate by salesperson.'],
            'discounts' => ['title' => 'Discounts & approvals', 'group' => 'control', 'max_days' => 92, 'description' => 'Invoices with discounts and every manager approval for discounts or prices below the minimum.'],
            'returns' => ['title' => 'Returns', 'group' => 'control', 'max_days' => 370, 'description' => 'Every return with its outcome, condition and reason.'],
            'stock_valuation' => ['title' => 'Stock on hand & valuation', 'group' => 'stock', 'max_days' => 1100, 'snapshot' => true, 'description' => 'What is in stock now at each location and its value at average cost.'],
            'low_stock' => ['title' => 'Low stock', 'group' => 'stock', 'max_days' => 1100, 'snapshot' => true, 'description' => 'Items at or below their reorder level, per location.'],
            'dead_stock' => ['title' => 'Slow-moving & dead stock', 'group' => 'stock', 'max_days' => 1100, 'snapshot' => true, 'description' => 'Stock with no sale in the last N days, and the money tied up in it.'],
            'stock_movements' => ['title' => 'Stock movement summary', 'group' => 'stock', 'max_days' => 370, 'description' => 'Stock in and out by movement type, location and product.'],
            'transfers' => ['title' => 'Transfers', 'group' => 'stock', 'max_days' => 370, 'description' => 'Transfers between locations, what was sent and received, and what is still on the way.'],
            'purchases' => ['title' => 'Purchases by supplier', 'group' => 'purchasing', 'max_days' => 370, 'description' => 'Goods received per supplier and product, with average unit cost.'],
            'payments' => ['title' => 'Payments by method', 'group' => 'finance', 'max_days' => 1100, 'description' => 'Totals and counts per payment method, with refunds.'],
            'cheques' => ['title' => 'Cheques', 'group' => 'finance', 'max_days' => 370, 'description' => 'Pending cheques (and how late) and cheques bounced in the period.'],
        ];
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function canView(User $user, string $key): bool
    {
        if (! $user->role->canOverride() && $user->role !== Role::Accountant) {
            return false;
        }

        return empty(self::all()[$key]['profit']) || $user->can('view-profit');
    }

    /**
     * Reports this user may open, grouped for the landing page.
     *
     * @return array<int, array{label: string, reports: array<int, array<string, mixed>>}>
     */
    public static function forUser(User $user): array
    {
        $groups = [];

        foreach (self::all() as $key => $report) {
            if (self::canView($user, $key)) {
                $groups[$report['group']]['label'] = self::GROUPS[$report['group']];
                $groups[$report['group']]['reports'][] = ['key' => $key, ...$report];
            }
        }

        return array_values($groups);
    }
}
