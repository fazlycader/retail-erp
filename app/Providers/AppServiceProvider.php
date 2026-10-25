<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\Attribute;
use App\Models\BankDeposit;
use App\Models\CatalogImport;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\CustomSpec;
use App\Models\DayClose;
use App\Models\Delivery;
use App\Models\DeliveryRun;
use App\Models\GoodsReceipt;
use App\Models\InstallationJob;
use App\Models\Location;
use App\Models\OpeningStockImport;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductionJob;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Refund;
use App\Models\Register;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesReturn;
use App\Models\Shift;
use App\Models\StockAdjustment;
use App\Models\StockCount;
use App\Models\StockReservation;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\SupplierReturn;
use App\Models\Transfer;
use App\Models\TransferLine;
use App\Models\User;
use App\Services\Integrations\IntegrationStore;
use App\Services\Sms\NotifyLkGateway;
use App\Services\Sms\SmsGateway;
use App\Services\Woo\WooDirty;
use App\Support\CurrentLocation;
use App\Support\ProductionGuard;
use App\Support\Terminal;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(IntegrationStore::class);
        $this->app->bind(SmsGateway::class, NotifyLkGateway::class);
        // One per request (set by ResolveCurrentLocation).
        $this->app->scoped(CurrentLocation::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Production: refuse unsafe settings, build https:// URLs, trust the configured proxy
        // (Hostinger CDN) for the client IP and HTTPS.
        if ($this->app->isProduction()) {
            ProductionGuard::check();
            URL::forceScheme('https');
        }

        if ($proxies = config('security.trusted_proxies')) {
            TrustProxies::at($proxies === '*' || $proxies === 'REMOTE_ADDR' ? $proxies : array_map('trim', explode(',', $proxies)));
        }

        Model::preventLazyLoading(! $this->app->isProduction());

        // WooCommerce: changes to products, prices and stock are queued for woo:push after commit.
        WooDirty::register();

        // Queue workers live for minutes: re-read integration credentials before every job, so an
        // admin's edit at Integrations applies to the very next job without a worker restart.
        Queue::before(function () {
            $this->app->make(IntegrationStore::class)->flush();
            Category::forget();
        });

        Gate::define('manage-staff', fn (User $user) => $user->role->canManageStaff());
        Gate::define('manage-settings', fn (User $user) => $user->role === Role::Admin);
        // Create and deactivate locations: admins. Managers edit their own location's settings (LocationPolicy).
        Gate::define('manage-locations', fn (User $user) => $user->role === Role::Admin);
        Gate::define('override', fn (User $user) => $user->role->canOverride());
        Gate::define('view-audit-log', fn (User $user) => $user->role->canOverride());
        Gate::define('edit-catalog', fn (User $user) => $user->role === Role::Admin
            || ($user->role === Role::Manager && CompanySetting::current()->managers_edit_catalog));
        Gate::define('view-cost', fn (User $user) => in_array($user->role, [Role::Admin, Role::Manager, Role::Accountant], true));
        Gate::define('manage-suppliers', fn (User $user) => in_array($user->role, [Role::Admin, Role::Manager], true));
        Gate::define('manage-purchase-orders', fn (User $user) => in_array($user->role, [Role::Admin, Role::Manager], true));
        Gate::define('receive-stock', fn (User $user) => in_array($user->role, [Role::Admin, Role::Manager, Role::Storekeeper], true));
        Gate::define('manage-bills', fn (User $user) => in_array($user->role, [Role::Admin, Role::Manager, Role::Accountant], true));
        Gate::define('manage-customers', fn (User $user) => in_array($user->role, [Role::Admin, Role::Manager, Role::Cashier], true));
        Gate::define('manage-sales', fn (User $user) => in_array($user->role, [Role::Admin, Role::Manager, Role::Cashier], true));
        Gate::define('view-profit', fn (User $user) => in_array($user->role, [Role::Admin, Role::Accountant], true)
            || ($user->role === Role::Manager && CompanySetting::current()->managers_see_profit));
        // Workshop (Step 11): workshop staff and storekeepers work on jobs; managers run the workshop and see costs.
        Gate::define('work-on-jobs', fn (User $user) => in_array($user->role, [Role::Admin, Role::Manager, Role::Storekeeper, Role::Workshop], true));
        Gate::define('manage-workshop', fn (User $user) => in_array($user->role, [Role::Admin, Role::Manager], true));
        Gate::define('view-job-costs', fn (User $user) => in_array($user->role, [Role::Admin, Role::Manager], true));
        Gate::define('manage-cheques', fn (User $user) => in_array($user->role, [Role::Admin, Role::Manager, Role::Accountant], true));

        // Short, stable names for polymorphic rows (audit log subjects) instead of class names.
        Relation::enforceMorphMap([
            'user' => User::class,
            'location' => Location::class,
            'category' => Category::class,
            'attribute' => Attribute::class,
            'product' => Product::class,
            'variant' => ProductVariant::class,
            'catalog_import' => CatalogImport::class,
            'stock_adjustment' => StockAdjustment::class,
            'transfer' => Transfer::class,
            'transfer_line' => TransferLine::class,
            'stock_count' => StockCount::class,
            'stock_unit' => StockUnit::class,
            'stock_reservation' => StockReservation::class,
            'opening_stock_import' => OpeningStockImport::class,
            'supplier' => Supplier::class,
            'purchase_order' => PurchaseOrder::class,
            'purchase_order_line' => PurchaseOrderLine::class,
            'goods_receipt' => GoodsReceipt::class,
            'supplier_return' => SupplierReturn::class,
            'supplier_bill' => SupplierBill::class,
            'supplier_payment' => SupplierPayment::class,
            'customer' => Customer::class,
            'quotation' => Quotation::class,
            'quotation_line' => QuotationLine::class,
            'sales_order' => SalesOrder::class,
            'sales_order_line' => SalesOrderLine::class,
            'payment' => Payment::class,
            'credit_note' => CreditNote::class,
            'refund' => Refund::class,
            'sales_return' => SalesReturn::class,
            'shift' => Shift::class,
            'register' => Register::class,
            'bank_deposit' => BankDeposit::class,
            'day_close' => DayClose::class,
            'custom_spec' => CustomSpec::class,
            'production_job' => ProductionJob::class,
            'delivery' => Delivery::class,
            'delivery_run' => DeliveryRun::class,
            'installation_job' => InstallationJob::class,
        ]);

        // Shared-terminal PIN switch: per device + person, and per IP.
        RateLimiter::for('pin-login', fn (Request $request) => [
            Limit::perMinute(5)->by('pin:'.Terminal::id($request).':'.$request->input('user_id')),
            Limit::perMinute(30)->by('pin-ip:'.$request->ip()),
        ]);
    }
}
