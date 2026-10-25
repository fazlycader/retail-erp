<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Single source of truth for sidebar modules and which roles may open them.
 * Routes use self::middleware($key) so the sidebar and server rules never drift.
 * 'all_locations' = admins may pick "All locations" in the switcher on this module's pages.
 * 'hidden_for' = roles that may open the route but don't see it in the sidebar (workshop staff land
 * on the jobs board instead of the dashboard).
 */
final class Navigation
{
    /**
     * @return array<string, array{label: string, route: string, match: string|array<int, string>, icon: string, roles: array<int, Role>, all_locations?: bool, hidden_for?: array<int, Role>, description: string}>
     */
    public static function modules(): array
    {
        $all = Role::cases();
        $management = [Role::Admin, Role::Manager];

        return [
            'dashboard' => [
                'label' => 'Dashboard',
                'route' => 'dashboard',
                'match' => 'dashboard',
                'icon' => 'home',
                'roles' => $all,
                'hidden_for' => [Role::Workshop, Role::Driver],
                'all_locations' => true,
                'description' => 'Overview of today at this location.',
            ],
            'pos' => [
                'label' => 'POS',
                'route' => 'pos.index',
                'match' => 'pos.*',
                'icon' => 'register',
                'roles' => [Role::Admin, Role::Manager, Role::Cashier],
                'description' => 'Ring up sales at the counter: scan, take payment, print the receipt.',
            ],
            'sales' => [
                'label' => 'Sales',
                'route' => 'sales.index',
                'match' => 'sales.*',
                'icon' => 'receipt',
                'roles' => [Role::Admin, Role::Manager, Role::Cashier, Role::Accountant],
                'all_locations' => true,
                'description' => 'Invoices, quotations, sales returns and payment receipts.',
            ],
            'installments' => [
                'label' => 'Installments',
                'route' => 'installments.index',
                'match' => 'installments.*',
                'icon' => 'receipt',
                'roles' => [Role::Admin, Role::Manager, Role::Cashier, Role::Accountant],
                'all_locations' => true,
                'description' => 'Installment agreements, collections, reminders and arrears follow-up.',
            ],
            'customers' => [
                'label' => 'Customers',
                'route' => 'customers.index',
                'match' => 'customers.*',
                'icon' => 'user',
                'roles' => [Role::Admin, Role::Manager, Role::Cashier, Role::Accountant],
                'description' => 'Customer accounts, credit limits, balances and statements.',
            ],
            'products' => [
                'label' => 'Products',
                'route' => 'products.index',
                'match' => ['products.*', 'categories.*', 'attributes.*'],
                'icon' => 'tag',
                'roles' => [Role::Admin, Role::Manager, Role::Cashier, Role::Storekeeper, Role::Accountant],
                'all_locations' => true,
                'description' => 'Product catalogue: categories, options, barcodes, prices and labels.',
            ],
            'stock' => [
                'label' => 'Stock',
                'route' => 'stock.index',
                'match' => 'stock.*',
                'icon' => 'box',
                'roles' => [Role::Admin, Role::Manager, Role::Storekeeper],
                'all_locations' => true,
                'description' => 'Stock on hand per location, movements, adjustments and stock takes.',
            ],
            'transfers' => [
                'label' => 'Transfers',
                'route' => 'transfers.index',
                'match' => 'transfers.*',
                'icon' => 'arrows',
                'roles' => [Role::Admin, Role::Manager, Role::Storekeeper],
                'description' => 'Move stock between the warehouse and showrooms: send, receive, track.',
            ],
            'workshop' => [
                'label' => 'Workshop',
                'route' => 'workshop.board',
                'match' => 'workshop.*',
                'icon' => 'wrench',
                'roles' => [Role::Admin, Role::Manager, Role::Storekeeper, Role::Workshop],
                'description' => 'Custom orders: the jobs board, materials, labour, costs and outsourced pieces.',
            ],
            'deliveries' => [
                'label' => 'Deliveries',
                'route' => 'deliveries.index',
                'match' => ['deliveries.*', 'delivery-runs.*', 'installations.*'],
                'icon' => 'truck',
                'roles' => [Role::Admin, Role::Manager, Role::Storekeeper, Role::Cashier],
                'description' => 'Plan delivery runs, track proof of delivery and reconcile driver collections.',
            ],
            'driver' => [
                'label' => 'My Route',
                'route' => 'driver.index',
                'match' => 'driver.*',
                'icon' => 'truck',
                'roles' => [Role::Driver],
                'description' => 'Today’s delivery stops and proof of delivery.',
            ],
            'purchasing' => [
                'label' => 'Purchasing',
                'route' => 'purchasing.index',
                'match' => 'purchasing.*',
                'icon' => 'clipboard',
                'roles' => [Role::Admin, Role::Manager, Role::Storekeeper, Role::Accountant],
                'all_locations' => true,
                'description' => 'Purchase orders, goods received notes and supplier returns.',
            ],
            'suppliers' => [
                'label' => 'Suppliers',
                'route' => 'suppliers.index',
                'match' => 'suppliers.*',
                'icon' => 'truck',
                'roles' => [Role::Admin, Role::Manager, Role::Storekeeper, Role::Accountant],
                'all_locations' => true,
                'description' => 'Suppliers, payment terms, payments and statements.',
            ],
            'cash' => [
                'label' => 'Shift & cash',
                'route' => 'cash.index',
                'match' => 'cash.*',
                'icon' => 'cash',
                'roles' => [Role::Admin, Role::Manager, Role::Cashier],
                'description' => 'Open and close your shift, pay-ins, pay-outs and cash counts.',
            ],
            'reports' => [
                'label' => 'Reports',
                'route' => 'reports.index',
                'match' => 'reports.*',
                'icon' => 'chart',
                'roles' => [Role::Admin, Role::Manager, Role::Accountant],
                'all_locations' => true,
                'description' => 'Sales, stock valuation, VAT, purchases and more, with exports.',
            ],
            'audit' => [
                'label' => 'Audit log',
                'route' => 'audit.index',
                'match' => 'audit.*',
                'icon' => 'shield',
                'roles' => $management,
                'all_locations' => true,
                'description' => 'Who changed what: staff, settings, PIN lockouts and manager overrides.',
            ],
            'staff' => [
                'label' => 'Staff',
                'route' => 'staff.index',
                'match' => 'staff.*',
                'icon' => 'users',
                'roles' => $management,
                'all_locations' => true,
                'description' => 'Staff accounts, roles, locations and PINs.',
            ],
            'locations' => [
                'label' => 'Locations',
                'route' => 'locations.index',
                'match' => 'locations.*',
                'icon' => 'storefront',
                'roles' => $management,
                'all_locations' => true,
                'description' => 'Warehouses and showrooms, receipt texts and business-day cutoff.',
            ],
            'online' => [
                'label' => 'Online store',
                'route' => 'online.index',
                'match' => 'online.*',
                'icon' => 'link',
                'roles' => $management,
                'description' => 'WooCommerce: sync status, web orders needing attention, showroom visit requests.',
            ],
            'settings' => [
                'label' => 'Settings',
                'route' => 'settings.edit',
                'match' => ['settings.*', 'integrations.*'],
                'icon' => 'settings',
                'roles' => [Role::Admin],
                'all_locations' => true,
                'description' => 'Company details, logo, VAT, document prefixes and stock rules.',
            ],
        ];
    }

    public static function module(string $key): array
    {
        return self::modules()[$key];
    }

    /**
     * Route middleware for a module, e.g. ['role:admin,manager', 'all-locations'].
     *
     * @return array<int, string>
     */
    public static function middleware(string $key): array
    {
        $module = self::module($key);

        return array_values(array_filter([
            'role:'.implode(',', array_map(fn (Role $role) => $role->value, $module['roles'])),
            ($module['all_locations'] ?? false) ? 'all-locations' : null,
        ]));
    }

    /**
     * Sidebar items visible to the given user.
     *
     * @return array<int, array{key: string, label: string, href: string, icon: string, active: bool}>
     */
    public static function for(User $user): array
    {
        $items = [];

        foreach (self::modules() as $key => $module) {
            if (! in_array($user->role, $module['roles'], true) || in_array($user->role, $module['hidden_for'] ?? [], true) || ! Route::has($module['route'])) {
                continue;
            }

            $items[] = [
                'key' => $key,
                'label' => $module['label'],
                'href' => route($module['route']),
                'icon' => $module['icon'],
                'active' => request()->routeIs(...(array) $module['match']),
            ];
        }

        return $items;
    }
}
