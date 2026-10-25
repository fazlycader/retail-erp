<?php

use App\Http\Controllers\Admin\AttributeController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BankDepositController;
use App\Http\Controllers\Admin\CashSetupController;
use App\Http\Controllers\Admin\CatalogImportController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CompanySettingsController;
use App\Http\Controllers\Admin\CreditNoteController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomSpecController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DayCloseController;
use App\Http\Controllers\Admin\DeliveryController;
use App\Http\Controllers\Admin\GoodsReceiptController;
use App\Http\Controllers\Admin\InstallmentController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\OnlineStoreController;
use App\Http\Controllers\Admin\OpeningStockImportController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PosController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductImageController;
use App\Http\Controllers\Admin\ProductLabelController;
use App\Http\Controllers\Admin\PurchaseOrderController;
use App\Http\Controllers\Admin\QuotationController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SalesOrderController;
use App\Http\Controllers\Admin\SalesReturnController;
use App\Http\Controllers\Admin\ShiftController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StockAdjustmentController;
use App\Http\Controllers\Admin\StockController;
use App\Http\Controllers\Admin\StockCountController;
use App\Http\Controllers\Admin\StockUnitController;
use App\Http\Controllers\Admin\SupplierBillController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\SupplierReturnController;
use App\Http\Controllers\Admin\TransferController;
use App\Http\Controllers\Admin\Workshop\BoardController;
use App\Http\Controllers\Admin\Workshop\JobController;
use App\Http\Controllers\Admin\Workshop\MaterialController;
use App\Http\Controllers\Admin\Workshop\SupplierJobController;
use App\Http\Controllers\Admin\Workshop\WorkshopSetupController;
use App\Http\Controllers\Auth\TerminalController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\LocationSwitchController;
use App\Http\Controllers\ProfileController;
use App\Support\Navigation;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)
        ->middleware(Navigation::middleware('dashboard'))
        ->name('dashboard');

    // Top-bar location switcher.
    Route::post('/location', LocationSwitchController::class)->name('location.switch');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Two-factor login (step 9): set up, recovery codes, turn off.
    Route::get('/profile/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
    Route::post('/profile/two-factor', [TwoFactorController::class, 'start'])->middleware('throttle:6,1')->name('two-factor.start');
    Route::post('/profile/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:6,1')->name('two-factor.confirm');
    Route::post('/profile/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])->middleware('throttle:6,1')->name('two-factor.recovery');
    Route::delete('/profile/two-factor', [TwoFactorController::class, 'disable'])->middleware('throttle:6,1')->name('two-factor.disable');

    // Shared terminals: lock the screen; managers turn terminal mode on/off for this device.
    Route::post('/terminal/lock', [TerminalController::class, 'lock'])->name('terminal.lock');
    Route::post('/terminal/enable', [TerminalController::class, 'enable'])->name('terminal.enable');
    Route::post('/terminal/disable', [TerminalController::class, 'disable'])->name('terminal.disable');

    Route::middleware(Navigation::middleware('staff'))->group(function () {
        Route::resource('staff', StaffController::class)
            ->except(['show', 'destroy'])
            ->parameters(['staff' => 'user']);
        Route::patch('/staff/{user}/toggle-active', [StaffController::class, 'toggleActive'])
            ->name('staff.toggle-active');
        Route::patch('/staff/{user}/unlock-pin', [StaffController::class, 'unlockPin'])
            ->name('staff.unlock-pin');
    });

    Route::middleware(Navigation::middleware('locations'))->group(function () {
        Route::resource('locations', LocationController::class)->except(['show', 'destroy']);
        Route::patch('/locations/{location}/toggle-active', [LocationController::class, 'toggleActive'])
            ->name('locations.toggle-active');
    });

    Route::middleware(Navigation::middleware('settings'))->group(function () {
        Route::get('/settings', [CompanySettingsController::class, 'edit'])->name('settings.edit');
        // POST (not PUT) so the logo upload works as multipart form data.
        Route::post('/settings', [CompanySettingsController::class, 'update'])->name('settings.update');

        // Integrations (step 10): write-only credentials with one-step undo.
        Route::get('/settings/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
        Route::put('/settings/integrations/{key}', [IntegrationController::class, 'update'])->where('key', '[A-Z_]+')->name('integrations.update');
        Route::delete('/settings/integrations/{key}', [IntegrationController::class, 'clear'])->where('key', '[A-Z_]+')->name('integrations.clear');
        Route::post('/settings/integrations/{key}/undo', [IntegrationController::class, 'undo'])->where('key', '[A-Z_]+')->name('integrations.undo');
        Route::post('/settings/integrations/test/woocommerce', [IntegrationController::class, 'test'])->middleware('throttle:10,1')->name('integrations.test');
    });

    Route::get('/audit', AuditLogController::class)
        ->middleware(Navigation::middleware('audit'))
        ->name('audit.index');

    Route::middleware(Navigation::middleware('deliveries'))->group(function () {
        Route::get('/deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
        Route::post('/deliveries', [DeliveryController::class, 'store'])->name('deliveries.store');
        Route::post('/deliveries/zones', [DeliveryController::class, 'storeZone'])->name('deliveries.zones.store');
        Route::post('/deliveries/vehicles', [DeliveryController::class, 'storeVehicle'])->name('deliveries.vehicles.store');
        Route::post('/deliveries/{delivery}/reschedule', [DeliveryController::class, 'reschedule'])->whereNumber('delivery')->name('deliveries.reschedule');
        Route::post('/installations/{job}/complete', [DeliveryController::class, 'completeInstallation'])->whereNumber('job')->name('installations.complete');
        Route::post('/deliveries/collections/hand-over', [DeliveryController::class, 'handOver'])->name('deliveries.handover');
        Route::post('/delivery-runs', [DeliveryController::class, 'createRun'])->name('delivery-runs.store');
        Route::post('/delivery-runs/{run}/load', [DeliveryController::class, 'loadRun'])->whereNumber('run')->name('delivery-runs.load');
        Route::post('/delivery-runs/{run}/close', [DeliveryController::class, 'closeRun'])->whereNumber('run')->name('delivery-runs.close');
        Route::post('/delivery-runs/{run}/reorder', [DeliveryController::class, 'reorderStops'])->whereNumber('run')->name('delivery-runs.reorder');
    });

    Route::middleware(Navigation::middleware('driver'))->prefix('driver')->name('driver.')->group(function () {
        Route::get('/', [DriverController::class, 'index'])->name('index');
        Route::post('/unlock', [DriverController::class, 'unlock'])->middleware('throttle:pin-login')->name('unlock');
        Route::post('/runs/{run}/start', [DriverController::class, 'startRun'])->whereNumber('run')->name('runs.start');
        Route::post('/actions', [DriverController::class, 'action'])->middleware('throttle:60,1')->name('actions');
    });

    Route::middleware(Navigation::middleware('products'))->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::post('/products/generate-variants', [ProductController::class, 'generateVariants'])->name('products.generate-variants');
        Route::post('/products/bulk-price-preview', [ProductController::class, 'bulkPricePreview'])->name('products.bulk-price-preview');
        Route::post('/products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');

        Route::get('/products/labels', [ProductLabelController::class, 'index'])->name('products.labels');
        Route::get('/products/labels/search', [ProductLabelController::class, 'search'])->name('products.labels.search');

        Route::get('/products/import', [CatalogImportController::class, 'index'])->name('products.import.index');
        Route::get('/products/import/template/{format}', [CatalogImportController::class, 'template'])->name('products.import.template');
        Route::post('/products/import', [CatalogImportController::class, 'upload'])->name('products.import.upload');
        Route::get('/products/import/{import}', [CatalogImportController::class, 'show'])->whereNumber('import')->name('products.import.show');
        Route::post('/products/import/{import}/dry-run', [CatalogImportController::class, 'dryRun'])->whereNumber('import')->name('products.import.dry-run');
        Route::post('/products/import/{import}/commit', [CatalogImportController::class, 'commit'])->whereNumber('import')->name('products.import.commit');
        Route::get('/products/import/{import}/report', [CatalogImportController::class, 'report'])->whereNumber('import')->name('products.import.report');

        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->whereNumber('product')->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('products.update');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->whereNumber('product')->name('products.destroy');
        Route::post('/products/{product}/restore', [ProductController::class, 'restore'])->whereNumber('product')->name('products.restore');

        Route::post('/products/{product}/images', [ProductImageController::class, 'store'])->whereNumber('product')->name('products.images.store');
        Route::post('/products/{product}/images/reorder', [ProductImageController::class, 'reorder'])->whereNumber('product')->name('products.images.reorder');
        Route::patch('/products/{product}/images/{image}', [ProductImageController::class, 'update'])->whereNumber(['product', 'image'])->name('products.images.update');
        Route::delete('/products/{product}/images/{image}', [ProductImageController::class, 'destroy'])->whereNumber(['product', 'image'])->name('products.images.destroy');

        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::post('/categories/rearrange', [CategoryController::class, 'rearrange'])->name('categories.rearrange');
        Route::match(['post', 'put'], '/categories/{category}', [CategoryController::class, 'update'])->whereNumber('category')->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->whereNumber('category')->name('categories.destroy');

        Route::get('/attributes', [AttributeController::class, 'index'])->name('attributes.index');
        Route::post('/attributes', [AttributeController::class, 'store'])->name('attributes.store');
        Route::post('/attributes/reorder', [AttributeController::class, 'reorder'])->name('attributes.reorder');
        Route::patch('/attributes/{attribute}', [AttributeController::class, 'update'])->whereNumber('attribute')->name('attributes.update');
        Route::delete('/attributes/{attribute}', [AttributeController::class, 'destroy'])->whereNumber('attribute')->name('attributes.destroy');
        Route::post('/attributes/{attribute}/values', [AttributeController::class, 'storeValue'])->whereNumber('attribute')->name('attributes.values.store');
        Route::post('/attributes/{attribute}/values/reorder', [AttributeController::class, 'reorderValues'])->whereNumber('attribute')->name('attributes.values.reorder');
        Route::match(['post', 'patch'], '/attributes/{attribute}/values/{value}', [AttributeController::class, 'updateValue'])->whereNumber(['attribute', 'value'])->name('attributes.values.update');
        Route::delete('/attributes/{attribute}/values/{value}', [AttributeController::class, 'destroyValue'])->whereNumber(['attribute', 'value'])->name('attributes.values.destroy');
    });

    Route::middleware(Navigation::middleware('stock'))->group(function () {
        Route::get('/stock', [StockController::class, 'index'])->name('stock.index');
        Route::get('/stock/lookup', [StockController::class, 'lookup'])->name('stock.lookup');
        Route::get('/stock/variants/{variant}', [StockController::class, 'show'])->whereNumber('variant')->name('stock.show');

        Route::get('/stock/adjustments', [StockAdjustmentController::class, 'index'])->name('stock.adjustments.index');
        Route::post('/stock/adjustments', [StockAdjustmentController::class, 'store'])->name('stock.adjustments.store');

        Route::get('/stock/counts', [StockCountController::class, 'index'])->name('stock.counts.index');
        Route::post('/stock/counts', [StockCountController::class, 'store'])->name('stock.counts.store');
        Route::get('/stock/counts/{count}', [StockCountController::class, 'show'])->whereNumber('count')->name('stock.counts.show');
        Route::post('/stock/counts/{count}/scan', [StockCountController::class, 'scan'])->whereNumber('count')->name('stock.counts.scan');
        Route::post('/stock/counts/{count}/record', [StockCountController::class, 'record'])->whereNumber('count')->name('stock.counts.record');
        Route::post('/stock/counts/{count}/review', [StockCountController::class, 'review'])->whereNumber('count')->name('stock.counts.review');
        Route::post('/stock/counts/{count}/post', [StockCountController::class, 'post'])->whereNumber('count')->name('stock.counts.post');

        Route::get('/stock/units', [StockUnitController::class, 'index'])->name('stock.units.index');
        Route::patch('/stock/units/{unit}', [StockUnitController::class, 'updateCondition'])->whereNumber('unit')->name('stock.units.update');

        Route::get('/stock/opening', [OpeningStockImportController::class, 'index'])->name('stock.opening.index');
        Route::get('/stock/opening/template/{format}', [OpeningStockImportController::class, 'template'])->name('stock.opening.template');
        Route::post('/stock/opening/upload', [OpeningStockImportController::class, 'upload'])->name('stock.opening.upload');
        Route::post('/stock/opening/{import}/commit', [OpeningStockImportController::class, 'commit'])->whereNumber('import')->name('stock.opening.commit');
        Route::get('/stock/opening/{import}/report', [OpeningStockImportController::class, 'report'])->whereNumber('import')->name('stock.opening.report');
    });

    Route::middleware(Navigation::middleware('transfers'))->group(function () {
        Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::get('/transfers/create', [TransferController::class, 'create'])->name('transfers.create');
        Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
        Route::get('/transfers/{transfer}', [TransferController::class, 'show'])->whereNumber('transfer')->name('transfers.show');
        Route::post('/transfers/{transfer}/request', [TransferController::class, 'submitRequest'])->whereNumber('transfer')->name('transfers.request');
        Route::post('/transfers/{transfer}/dispatch', [TransferController::class, 'dispatch'])->whereNumber('transfer')->name('transfers.dispatch');
        Route::post('/transfers/{transfer}/receive', [TransferController::class, 'receive'])->whereNumber('transfer')->name('transfers.receive');
        Route::post('/transfers/{transfer}/resolve', [TransferController::class, 'resolve'])->whereNumber('transfer')->name('transfers.resolve');
        Route::post('/transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->whereNumber('transfer')->name('transfers.cancel');
    });

    Route::middleware(Navigation::middleware('suppliers'))->group(function () {
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('suppliers.update');
        Route::patch('/suppliers/{supplier}/toggle-active', [SupplierController::class, 'toggleActive'])->whereNumber('supplier')->name('suppliers.toggle-active');
    });

    Route::middleware(Navigation::middleware('purchasing'))->group(function () {
        Route::get('/purchasing', [PurchaseOrderController::class, 'index'])->name('purchasing.index');
        Route::get('/purchasing/lookup', [PurchaseOrderController::class, 'lookup'])->name('purchasing.lookup');
        Route::get('/purchasing/orders/create', [PurchaseOrderController::class, 'create'])->name('purchasing.orders.create');
        Route::post('/purchasing/orders', [PurchaseOrderController::class, 'store'])->name('purchasing.orders.store');
        Route::get('/purchasing/orders/{order}', [PurchaseOrderController::class, 'show'])->whereNumber('order')->name('purchasing.orders.show');
        Route::get('/purchasing/orders/{order}/edit', [PurchaseOrderController::class, 'edit'])->whereNumber('order')->name('purchasing.orders.edit');
        Route::put('/purchasing/orders/{order}', [PurchaseOrderController::class, 'update'])->whereNumber('order')->name('purchasing.orders.update');
        Route::post('/purchasing/orders/{order}/send', [PurchaseOrderController::class, 'send'])->whereNumber('order')->name('purchasing.orders.send');
        Route::post('/purchasing/orders/{order}/close', [PurchaseOrderController::class, 'close'])->whereNumber('order')->name('purchasing.orders.close');
        Route::post('/purchasing/orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->whereNumber('order')->name('purchasing.orders.cancel');
        Route::get('/purchasing/orders/{order}/pdf', [PurchaseOrderController::class, 'pdf'])->whereNumber('order')->name('purchasing.orders.pdf');

        Route::get('/purchasing/reorder', [PurchaseOrderController::class, 'reorder'])->name('purchasing.reorder');
        Route::post('/purchasing/reorder', [PurchaseOrderController::class, 'createFromReorder'])->name('purchasing.reorder.store');

        Route::get('/purchasing/receive', [GoodsReceiptController::class, 'create'])->name('purchasing.receive');
        Route::post('/purchasing/receive', [GoodsReceiptController::class, 'store'])->name('purchasing.receive.store');
        Route::get('/purchasing/receipts', [GoodsReceiptController::class, 'index'])->name('purchasing.receipts.index');

        Route::get('/purchasing/returns', [SupplierReturnController::class, 'index'])->name('purchasing.returns.index');
        Route::post('/purchasing/returns', [SupplierReturnController::class, 'store'])->name('purchasing.returns.store');

        Route::get('/purchasing/bills', [SupplierBillController::class, 'index'])->name('purchasing.bills.index');
        Route::post('/purchasing/bills', [SupplierBillController::class, 'storeBill'])->name('purchasing.bills.store');
        Route::post('/purchasing/payments', [SupplierBillController::class, 'storePayment'])->name('purchasing.payments.store');
    });

    Route::middleware(Navigation::middleware('customers'))->group(function () {
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/lookup', [CustomerController::class, 'lookup'])->name('customers.lookup');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');
        Route::put('/customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');
    });

    Route::middleware(Navigation::middleware('sales'))->group(function () {
        Route::get('/sales', [SalesOrderController::class, 'index'])->name('sales.index');
        Route::get('/sales/catalog-lookup', [SalesOrderController::class, 'catalogLookup'])->name('sales.catalog-lookup');

        Route::get('/sales/orders/create', [SalesOrderController::class, 'create'])->name('sales.orders.create');
        Route::post('/sales/orders', [SalesOrderController::class, 'store'])->name('sales.orders.store');
        Route::get('/sales/orders/{order}', [SalesOrderController::class, 'show'])->whereNumber('order')->name('sales.orders.show');
        Route::get('/sales/orders/{order}/edit', [SalesOrderController::class, 'edit'])->whereNumber('order')->name('sales.orders.edit');
        Route::put('/sales/orders/{order}', [SalesOrderController::class, 'update'])->whereNumber('order')->name('sales.orders.update');
        Route::post('/sales/orders/{order}/confirm', [SalesOrderController::class, 'confirm'])->whereNumber('order')->name('sales.orders.confirm');
        Route::post('/sales/orders/{order}/fulfil', [SalesOrderController::class, 'fulfil'])->whereNumber('order')->name('sales.orders.fulfil');
        Route::post('/sales/orders/{order}/cancel', [SalesOrderController::class, 'cancel'])->whereNumber('order')->name('sales.orders.cancel');
        Route::get('/sales/orders/{order}/delivery-note', [SalesOrderController::class, 'deliveryNotePdf'])->whereNumber('order')->name('sales.orders.delivery-note');
        Route::get('/sales/orders/{order}/invoice', [SalesOrderController::class, 'invoicePdf'])->whereNumber('order')->name('sales.orders.invoice');
        Route::delete('/sales/orders/{order}/lines/{line}', [SalesOrderController::class, 'removeLine'])->whereNumber(['order', 'line'])->name('sales.orders.lines.destroy');
        Route::post('/sales/orders/{order}/payments', [PaymentController::class, 'store'])->whereNumber('order')->name('sales.orders.payments.store');
        Route::post('/sales/orders/{order}/returns', [SalesReturnController::class, 'store'])->whereNumber('order')->name('sales.orders.returns.store');

        Route::get('/sales/payments', [PaymentController::class, 'index'])->name('sales.payments.index');
        Route::post('/sales/payments/{payment}/void', [PaymentController::class, 'void'])->whereNumber('payment')->name('sales.payments.void');
        Route::post('/sales/payments/{payment}/cheque', [PaymentController::class, 'cheque'])->whereNumber('payment')->name('sales.payments.cheque');
        Route::get('/sales/payments/{payment}/pdf', [PaymentController::class, 'pdf'])->whereNumber('payment')->name('sales.payments.pdf');

        Route::get('/sales/returns', [SalesReturnController::class, 'index'])->name('sales.returns.index');

        Route::get('/sales/credit-notes', [CreditNoteController::class, 'index'])->name('sales.credit-notes.index');
        Route::post('/sales/credit-notes/{creditNote}/refund', [CreditNoteController::class, 'refund'])->whereNumber('creditNote')->name('sales.credit-notes.refund');
        Route::get('/sales/credit-notes/{creditNote}/pdf', [CreditNoteController::class, 'pdf'])->whereNumber('creditNote')->name('sales.credit-notes.pdf');

        Route::get('/sales/quotations', [QuotationController::class, 'index'])->name('sales.quotations.index');
        Route::get('/sales/quotations/create', [QuotationController::class, 'create'])->name('sales.quotations.create');
        Route::post('/sales/quotations', [QuotationController::class, 'store'])->name('sales.quotations.store');
        Route::get('/sales/quotations/{quotation}', [QuotationController::class, 'show'])->whereNumber('quotation')->name('sales.quotations.show');
        Route::get('/sales/quotations/{quotation}/edit', [QuotationController::class, 'edit'])->whereNumber('quotation')->name('sales.quotations.edit');
        Route::put('/sales/quotations/{quotation}', [QuotationController::class, 'update'])->whereNumber('quotation')->name('sales.quotations.update');
        Route::post('/sales/quotations/{quotation}/send', [QuotationController::class, 'send'])->whereNumber('quotation')->name('sales.quotations.send');
        Route::post('/sales/quotations/{quotation}/accept', [QuotationController::class, 'accept'])->whereNumber('quotation')->name('sales.quotations.accept');
        Route::post('/sales/quotations/{quotation}/lose', [QuotationController::class, 'lose'])->whereNumber('quotation')->name('sales.quotations.lose');
        Route::post('/sales/quotations/{quotation}/convert', [QuotationController::class, 'convert'])->whereNumber('quotation')->name('sales.quotations.convert');
        Route::get('/sales/quotations/{quotation}/pdf', [QuotationController::class, 'pdf'])->whereNumber('quotation')->name('sales.quotations.pdf');
    });

    Route::middleware(Navigation::middleware('installments'))->group(function () {
        Route::get('/installments', [InstallmentController::class, 'index'])->name('installments.index');
        Route::get('/installments/create', [InstallmentController::class, 'create'])->name('installments.create');
        Route::post('/installments/preview', [InstallmentController::class, 'preview'])->name('installments.preview');
        Route::get('/installments/draft-pdf', [InstallmentController::class, 'draftPdf'])->name('installments.draft-pdf');
        Route::post('/installments', [InstallmentController::class, 'store'])->name('installments.store');
        Route::get('/installments/{agreement}', [InstallmentController::class, 'show'])->whereNumber('agreement')->name('installments.show');
        Route::post('/installments/{agreement}/collect', [InstallmentController::class, 'collect'])->whereNumber('agreement')->name('installments.collect');
        Route::post('/installments/{agreement}/settle', [InstallmentController::class, 'settleEarly'])->whereNumber('agreement')->name('installments.settle');
        Route::post('/installments/{agreement}/contacts', [InstallmentController::class, 'contact'])->whereNumber('agreement')->name('installments.contacts.store');
        Route::get('/installments/{agreement}/pdf', [InstallmentController::class, 'agreementPdf'])->whereNumber('agreement')->name('installments.pdf');
        Route::get('/installments/{agreement}/signed-document', [InstallmentController::class, 'signedDocument'])->whereNumber('agreement')->name('installments.signed');
        Route::get('/installments/customers/{customer}/statement', [InstallmentController::class, 'customerStatement'])->whereNumber('customer')->name('installments.customer-statement');
        Route::post('/installments/{agreement}/status', [InstallmentController::class, 'updateStatus'])->whereNumber('agreement')->name('installments.status');

        Route::middleware('role:admin,manager')->group(function () {
            Route::get('/installments/setup', [InstallmentController::class, 'setup'])->name('installments.setup');
            Route::post('/installments/plans', [InstallmentController::class, 'storePlan'])->name('installments.plans.store');
            Route::put('/installments/plans/{plan}', [InstallmentController::class, 'updatePlan'])->whereNumber('plan')->name('installments.plans.update');
            Route::put('/installments/policy', [InstallmentController::class, 'updateSettings'])->name('installments.policy.update');
        });
    });

    Route::middleware(Navigation::middleware('pos'))->group(function () {
        Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
        Route::get('/pos/products', [PosController::class, 'products'])->name('pos.products');
        Route::post('/pos/price', [PosController::class, 'price'])->name('pos.price');
        Route::post('/pos/checkout', [PosController::class, 'checkout'])->name('pos.checkout');
        Route::get('/pos/credit-note', [PosController::class, 'creditNote'])->name('pos.credit-note');
        Route::get('/pos/orders/{order}/receipt', [PosController::class, 'receipt'])->whereNumber('order')->name('pos.receipt');
        Route::post('/pos/held', [PosController::class, 'hold'])->name('pos.held.store');
        Route::get('/pos/held/{cart}', [PosController::class, 'resume'])->whereNumber('cart')->name('pos.held.show');
        Route::delete('/pos/held/{cart}', [PosController::class, 'discard'])->whereNumber('cart')->name('pos.held.destroy');
    });

    // Shifts & cash (step 7). Cashiers: own shift; managers: the location's shifts, approvals, deposits, end of day.
    Route::middleware(Navigation::middleware('cash'))->group(function () {
        Route::get('/cash', [ShiftController::class, 'current'])->name('cash.index');
        Route::post('/cash/open', [ShiftController::class, 'open'])->name('cash.open');
        Route::post('/cash/movements', [ShiftController::class, 'movement'])->name('cash.movement');
        Route::post('/cash/close', [ShiftController::class, 'close'])->name('cash.close');
        Route::post('/cash/registers/{register}/use', [ShiftController::class, 'useRegister'])->whereNumber('register')->name('cash.register');
        Route::get('/cash/shifts/{shift}', [ShiftController::class, 'show'])->whereNumber('shift')->name('cash.shifts.show');
        Route::get('/cash/shifts/{shift}/print', [ShiftController::class, 'print'])->whereNumber('shift')->name('cash.shifts.print');
        Route::get('/cash/movements/{movement}/slip', [ShiftController::class, 'slip'])->whereNumber('movement')->name('cash.movements.slip');

        Route::get('/cash/shifts', [ShiftController::class, 'index'])->name('cash.shifts.index');
        Route::post('/cash/shifts/{shift}/approve', [ShiftController::class, 'approve'])->whereNumber('shift')->name('cash.shifts.approve');
        Route::post('/cash/shifts/{shift}/force-close', [ShiftController::class, 'forceClose'])->whereNumber('shift')->name('cash.shifts.force-close');

        Route::get('/cash/setup', [CashSetupController::class, 'index'])->name('cash.setup');
        Route::put('/cash/setup/settings', [CashSetupController::class, 'updateSettings'])->name('cash.setup.settings');
        Route::post('/cash/setup/registers', [CashSetupController::class, 'storeRegister'])->name('cash.setup.registers.store');
        Route::put('/cash/setup/registers/{register}', [CashSetupController::class, 'updateRegister'])->whereNumber('register')->name('cash.setup.registers.update');
        Route::post('/cash/setup/categories', [CashSetupController::class, 'storeCategory'])->name('cash.setup.categories.store');
        Route::put('/cash/setup/categories/{category}', [CashSetupController::class, 'updateCategory'])->whereNumber('category')->name('cash.setup.categories.update');

        Route::get('/cash/deposits', [BankDepositController::class, 'index'])->name('cash.deposits.index');
        Route::post('/cash/deposits', [BankDepositController::class, 'store'])->name('cash.deposits.store');

        Route::get('/cash/end-of-day', [DayCloseController::class, 'show'])->name('cash.eod');
        Route::post('/cash/end-of-day', [DayCloseController::class, 'close'])->name('cash.eod.close');
        Route::get('/cash/end-of-day/print', [DayCloseController::class, 'print'])->name('cash.eod.print');
    });

    // Online store / WooCommerce (step 10): admins and managers; settings admin-only (checked in the controller).
    Route::middleware(Navigation::middleware('online'))->group(function () {
        Route::get('/online-store', [OnlineStoreController::class, 'index'])->name('online.index');
        Route::put('/online-store/settings', [OnlineStoreController::class, 'updateSettings'])->name('online.settings');
        Route::put('/online-store/variants/{variant}/buffer', [OnlineStoreController::class, 'updateBuffer'])->whereNumber('variant')->name('online.buffer');
        Route::post('/online-store/push', [OnlineStoreController::class, 'pushAll'])->middleware('throttle:6,1')->name('online.push');
        Route::post('/online-store/reconcile', [OnlineStoreController::class, 'reconcile'])->middleware('throttle:6,1')->name('online.reconcile');
        Route::post('/online-store/queue/{item}/retry', [OnlineStoreController::class, 'retryItem'])->whereNumber('item')->name('online.retry-item');
        Route::post('/online-store/webhooks/{event}/retry', [OnlineStoreController::class, 'retryWebhook'])->whereNumber('event')->name('online.retry-webhook');
        Route::post('/online-store/orders/{order}/resolve', [OnlineStoreController::class, 'resolve'])->whereNumber('order')->name('online.resolve');
        Route::put('/online-store/enquiries/{enquiry}', [OnlineStoreController::class, 'enquiry'])->whereNumber('enquiry')->name('online.enquiry');
        Route::get('/online-store/plugin', [OnlineStoreController::class, 'plugin'])->name('online.plugin');
    });

    // Reports (step 8): admin, manager, accountant; locations via ?location= (ReportScope).
    Route::middleware(Navigation::middleware('reports'))->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/exports', [ReportController::class, 'exportsStatus'])->name('reports.exports');
        Route::get('/reports/exports/{export}', [ReportController::class, 'download'])->whereNumber('export')->name('reports.exports.download');
        Route::get('/reports/{report}', [ReportController::class, 'show'])->where('report', '[a-z_]+')->name('reports.show');
        Route::get('/reports/{report}/print', [ReportController::class, 'print'])->where('report', '[a-z_]+')->name('reports.print');
        Route::get('/reports/{report}/export', [ReportController::class, 'export'])->where('report', '[a-z_]+')->name('reports.export');
    });

    // Private files (sketches, reference photos, signed quotes, stage photos) for sales & workshop staff.
    Route::get('/private-files', [CustomSpecController::class, 'privateFile'])->name('private-files.show');
    Route::post('/custom-specs', [CustomSpecController::class, 'save'])->name('custom-specs.save');
    Route::post('/custom-specs/{spec}/attachments', [CustomSpecController::class, 'uploadAttachment'])->whereNumber('spec')->name('custom-specs.attachments.store');
    Route::delete('/custom-specs/{spec}/attachments/{index}', [CustomSpecController::class, 'removeAttachment'])->whereNumber(['spec', 'index'])->name('custom-specs.attachments.destroy');
    Route::post('/custom-specs/{spec}/approve', [CustomSpecController::class, 'approve'])->whereNumber('spec')->name('custom-specs.approve');

    // Custom orders & workshop (step 11): admin, manager, storekeeper, workshop.
    Route::middleware(Navigation::middleware('workshop'))->group(function () {
        Route::get('/workshop', [BoardController::class, 'index'])->name('workshop.board');
        Route::get('/workshop/sync', [BoardController::class, 'sync'])->name('workshop.board.sync');
        Route::post('/workshop/jobs/{job}/move', [BoardController::class, 'move'])->whereNumber('job')->name('workshop.board.move');

        Route::get('/workshop/jobs/{job}', [JobController::class, 'show'])->whereNumber('job')->name('workshop.jobs.show');
        Route::put('/workshop/jobs/{job}', [JobController::class, 'update'])->whereNumber('job')->name('workshop.jobs.update');
        Route::post('/workshop/jobs/{job}/stage', [JobController::class, 'moveStage'])->whereNumber('job')->name('workshop.jobs.stage');
        Route::post('/workshop/jobs/{job}/notes', [JobController::class, 'addNote'])->whereNumber('job')->name('workshop.jobs.notes');
        Route::post('/workshop/jobs/{job}/materials/issue', [JobController::class, 'issueMaterial'])->whereNumber('job')->name('workshop.jobs.materials.issue');
        Route::post('/workshop/jobs/{job}/materials/return', [JobController::class, 'returnMaterial'])->whereNumber('job')->name('workshop.jobs.materials.return');
        Route::post('/workshop/jobs/{job}/labour', [JobController::class, 'addLabour'])->whereNumber('job')->name('workshop.jobs.labour.store');
        Route::delete('/workshop/jobs/{job}/labour/{labour}', [JobController::class, 'removeLabour'])->whereNumber(['job', 'labour'])->name('workshop.jobs.labour.destroy');
        Route::post('/workshop/jobs/{job}/complete', [JobController::class, 'complete'])->whereNumber('job')->name('workshop.jobs.complete');
        Route::post('/workshop/jobs/{job}/send', [JobController::class, 'sendToLocation'])->whereNumber('job')->name('workshop.jobs.send');
        Route::post('/workshop/jobs/{job}/outsource', [JobController::class, 'outsource'])->whereNumber('job')->name('workshop.jobs.outsource');

        Route::get('/workshop/materials', [MaterialController::class, 'index'])->name('workshop.materials.index');
        Route::get('/workshop/materials/lookup', [MaterialController::class, 'lookup'])->name('workshop.materials.lookup');

        Route::get('/workshop/suppliers', [SupplierJobController::class, 'index'])->name('workshop.suppliers.index');
        Route::post('/workshop/suppliers/{job}/progress', [SupplierJobController::class, 'updateProgress'])->whereNumber('job')->name('workshop.suppliers.progress');

        Route::get('/workshop/setup', [WorkshopSetupController::class, 'index'])->name('workshop.setup.index');
        Route::put('/workshop/setup/settings', [WorkshopSetupController::class, 'updateSettings'])->name('workshop.setup.settings');
        Route::post('/workshop/setup/stages', [WorkshopSetupController::class, 'storeStage'])->name('workshop.setup.stages.store');
        Route::put('/workshop/setup/stages/{stage}', [WorkshopSetupController::class, 'updateStage'])->whereNumber('stage')->name('workshop.setup.stages.update');
        Route::post('/workshop/setup/stages/reorder', [WorkshopSetupController::class, 'reorderStages'])->name('workshop.setup.stages.reorder');
    });
});

// Public digital copies of invoices, receipts and credit notes (QR code on the printout, SMS/WhatsApp links).
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/doc/{token}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('/doc/{token}/pdf', [DocumentController::class, 'pdf'])->name('documents.pdf');
});

// Public share link for Quotation PDF (used in WhatsApp wa.me links).
Route::get('/q/{token}/pdf', [QuotationController::class, 'publicPdf'])->name('quotations.public-pdf');

// Shared terminal lock screen and PIN switch (works without being signed in, only on terminal devices).
Route::get('/terminal', [TerminalController::class, 'show'])->name('terminal.show');
Route::post('/terminal/unlock', [TerminalController::class, 'unlock'])->middleware('throttle:pin-login')->name('terminal.unlock');

// Two-factor challenge after the password (not signed in yet).
Route::middleware('guest')->group(function () {
    Route::get('/two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorController::class, 'verify'])->middleware('throttle:10,1')->name('two-factor.verify');
});

require __DIR__.'/auth.php';
