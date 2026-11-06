<?php

use App\Http\Controllers\Administration\AdministrationEvidenceController;
use App\Http\Controllers\Administration\AuditLogController;
use App\Http\Controllers\Administration\BranchController as AdministrationBranchController;
use App\Http\Controllers\Administration\CategoryActivationController;
use App\Http\Controllers\Administration\CategoryController;
use App\Http\Controllers\Administration\ChatbotKnowledgeActivationController;
use App\Http\Controllers\Administration\ChatbotKnowledgeController;
use App\Http\Controllers\Administration\ChatbotKnowledgePreviewController;
use App\Http\Controllers\Administration\ComponentSpecificationController;
use App\Http\Controllers\Administration\CustomerController;
use App\Http\Controllers\Administration\CustomerReturnController;
use App\Http\Controllers\Administration\DamageReportController;
use App\Http\Controllers\Administration\ForecastingController;
use App\Http\Controllers\Administration\InventoryAdjustmentController;
use App\Http\Controllers\Administration\InventoryController;
use App\Http\Controllers\Administration\NotificationController as AdministrationNotificationController;
use App\Http\Controllers\Administration\OperationalExportController;
use App\Http\Controllers\Administration\OrderCodCollectionController;
use App\Http\Controllers\Administration\OrderCodRemittanceController;
use App\Http\Controllers\Administration\OrderController as AdministrationOrderController;
use App\Http\Controllers\Administration\OrderHandlerController;
use App\Http\Controllers\Administration\OrderLocalLbcBookingController;
use App\Http\Controllers\Administration\OrderManualShipmentController;
use App\Http\Controllers\Administration\OrderPaymentProofController;
use App\Http\Controllers\Administration\OrderPaymentReconciliationController;
use App\Http\Controllers\Administration\OrderPaymentStatusController;
use App\Http\Controllers\Administration\OrderPickupConfirmationController;
use App\Http\Controllers\Administration\OrderShipmentExportController;
use App\Http\Controllers\Administration\OrderShipmentOperationsController;
use App\Http\Controllers\Administration\OrderShipmentReferenceController;
use App\Http\Controllers\Administration\OrderShipmentStatusController;
use App\Http\Controllers\Administration\OrderStatusController;
use App\Http\Controllers\Administration\PcBuildAnalyticsController;
use App\Http\Controllers\Administration\PcBuildTemplateController;
use App\Http\Controllers\Administration\ProductActivationController;
use App\Http\Controllers\Administration\ProductController;
use App\Http\Controllers\Administration\RolePermissionController;
use App\Http\Controllers\Administration\SalesReportController;
use App\Http\Controllers\Administration\StaffPerformanceController;
use App\Http\Controllers\Administration\StockReconciliationController;
use App\Http\Controllers\Administration\SupplierController;
use App\Http\Controllers\Administration\SupplierReturnController;
use App\Http\Controllers\Administration\TeamController;
use App\Http\Controllers\Administration\TeamEmailVerificationController;
use App\Http\Controllers\Administration\WishlistReportController;
use App\Http\Controllers\Administration\WorkQueueController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CartItemController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CustomerRefundProofController;
use App\Http\Controllers\CustomerReturnRequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryLocationController;
use App\Http\Controllers\DeliveryQuoteController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NotificationController as CustomerNotificationController;
use App\Http\Controllers\OrderCancellationController;
use App\Http\Controllers\OrderCancellationRequestController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderPaymentProofController as CustomerOrderPaymentProofController;
use App\Http\Controllers\OrderPaymentRetryController;
use App\Http\Controllers\PcBuildController;
use App\Http\Controllers\PcBuilderController;
use App\Http\Controllers\PendingCartIntentController;
use App\Http\Controllers\PendingWishlistIntentController;
use App\Http\Controllers\ProductCatalogController;
use App\Http\Controllers\ProductDwellController;
use App\Http\Controllers\ProductViewController;
use App\Http\Controllers\RecommendationInteractionController;
use App\Http\Controllers\WishlistController;
use Illuminate\Support\Facades\Route;

// guest landing page
Route::get('/', [HomeController::class, 'index'])->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('email/verify', [EmailVerificationController::class, 'show'])->name('verification.notice');
    Route::post('email/verify', [EmailVerificationController::class, 'store'])
        ->middleware('throttle:30,1')->name('verification.verify');
    Route::post('email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:30,1')->name('verification.send');
});

// public/customer branches
Route::get('branches', [BranchController::class, 'index'])->name('branches.index');

// customer PC Builder
Route::post('pc-builder/options', [PcBuilderController::class, 'options'])->middleware('throttle:60,1')->name('pc-builder.options');
Route::get('pc-builder', [PcBuilderController::class, 'index'])->name('pc-builder.index');
Route::post('pc-builder/validate', [PcBuilderController::class, 'validate'])
    ->middleware('throttle:60,1')
    ->name('pc-builder.validate');
Route::get('pc-builds/shared/{token}', [PcBuildController::class, 'showShared'])
    ->middleware('throttle:60,1')
    ->whereUuid('token')
    ->name('pc-builds.shared.show');
Route::middleware(['auth', 'can:use-customer-cart'])
    ->prefix('pc-builds')
    ->name('pc-builds.')
    ->group(function (): void {
        Route::get('/', [PcBuildController::class, 'index'])->name('index');
        Route::post('/', [PcBuildController::class, 'store'])->middleware('throttle:30,1')->name('store');
        Route::patch('{uuid}', [PcBuildController::class, 'update'])->whereUuid('uuid')->middleware('throttle:30,1')->name('update');
        Route::post('{uuid}/duplicate', [PcBuildController::class, 'duplicate'])->whereUuid('uuid')->middleware('throttle:30,1')->name('duplicate');
        Route::post('{uuid}/cart', [PcBuildController::class, 'addToCart'])->whereUuid('uuid')->middleware('throttle:20,1')->name('cart.store');
        Route::delete('{uuid}', [PcBuildController::class, 'destroy'])->whereUuid('uuid')->middleware('throttle:30,1')->name('destroy');
        Route::post('{uuid}/share', [PcBuildController::class, 'share'])->whereUuid('uuid')->middleware('throttle:30,1')->name('share');
        Route::delete('{uuid}/share', [PcBuildController::class, 'stopSharing'])->whereUuid('uuid')->middleware('throttle:30,1')->name('share.destroy');
    });

// public/customer product catalog
Route::resource('products', ProductCatalogController::class)
    ->only(['index', 'show'])
    ->where(['product' => '[0-9]+']);
Route::post('products/{product}/dwell', [ProductDwellController::class, 'store'])
    ->whereNumber('product')
    ->middleware('throttle:60,1')
    ->name('products.dwell.store');
Route::post('products/{product}/view', [ProductViewController::class, 'store'])
    ->whereNumber('product')
    ->middleware('throttle:60,1')
    ->name('products.view.store');

// public/customer recommendations
Route::middleware('can:use-recommendations')->group(function () {
    Route::post('recommendations/interactions', RecommendationInteractionController::class)
        ->middleware('throttle:60,1')
        ->name('recommendations.interactions.store');
});

// dynamic dashboard for customer and admin
Route::middleware('auth')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

// customer cart
Route::post('cart/intent', [PendingCartIntentController::class, 'store'])
    ->middleware(['guest', 'throttle:60,1'])
    ->name('cart.intent.store');

Route::post('wishlist/intent', [PendingWishlistIntentController::class, 'store'])
    ->middleware(['guest', 'throttle:60,1'])
    ->name('wishlist.intent.store');

Route::middleware(['auth', 'can:use-customer-cart'])
    ->prefix('cart')
    ->name('cart.')
    ->group(function () {
        Route::get('/', [CartController::class, 'index'])
            ->name('index');
        Route::post('items', [CartItemController::class, 'store'])
            ->name('items.store');
        Route::patch('items/{cartItem}', [CartItemController::class, 'update'])
            ->whereNumber('cartItem')
            ->name('items.update');
        Route::delete('items/{cartItem}', [CartItemController::class, 'destroy'])
            ->whereNumber('cartItem')
            ->name('items.destroy');
    });

Route::middleware(['auth', 'can:use-customer-cart'])->prefix('wishlist')->name('wishlist.')->group(function () {
    Route::get('/', [WishlistController::class, 'index'])->name('index');
    Route::post('/', [WishlistController::class, 'store'])->middleware('throttle:60,1')->name('store');
    Route::delete('/{product}', [WishlistController::class, 'destroy'])->whereNumber('product')->name('destroy');
});

// customer checkout preview
Route::middleware(['auth', 'can:use-customer-cart'])
    ->prefix('checkout')
    ->name('checkout.')
    ->group(function () {
        Route::get('/', [CheckoutController::class, 'index'])
            ->name('index');
        Route::get('delivery-quote', [DeliveryQuoteController::class, 'show'])->name('delivery-quote.show');
    });

Route::get('delivery-locations', [DeliveryLocationController::class, 'index'])
    ->middleware(['auth', 'can:use-customer-cart', 'throttle:120,1'])->name('delivery-locations.index');

// customer orders
Route::middleware(['auth', 'can:use-customer-cart'])->group(function () {
    Route::get('notifications', [CustomerNotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/summary', [CustomerNotificationController::class, 'summary'])->name('notifications.summary');
    Route::patch('notifications/read-all', [CustomerNotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::patch('notifications/{notification}/read', [CustomerNotificationController::class, 'update'])->whereUuid('notification')->name('notifications.update');
    Route::get('orders', [OrderController::class, 'index'])
        ->name('orders.index');
    Route::post('orders/{order}/return-requests', [CustomerReturnRequestController::class, 'store'])
        ->whereNumber('order')->name('orders.return-requests.store');
    Route::get('orders/{order}/refund-payments/{payment}/proof/{evidence}', CustomerRefundProofController::class)
        ->whereNumber(['order', 'payment', 'evidence'])->name('orders.refund-payments.proof.show');
    Route::post('orders/{order}/return-requests/{returnRequest}/withdraw', [CustomerReturnRequestController::class, 'withdraw'])
        ->whereNumber(['order', 'returnRequest'])->name('orders.return-requests.withdraw');
    Route::post('orders', [OrderController::class, 'store'])
        ->name('orders.store');
    Route::post('orders/{order}/cancellation-requests', OrderCancellationRequestController::class)
        ->whereNumber('order')->name('orders.cancellation-requests.store');
    Route::post('orders/{order}/cancel', OrderCancellationController::class)
        ->whereNumber('order')
        ->name('orders.cancel.store');
    Route::post('orders/{order}/paymongo/retry', OrderPaymentRetryController::class)
        ->middleware('throttle:10,1')
        ->whereNumber('order')
        ->name('orders.paymongo.retry');
    Route::post('orders/{order}/payment-proof', CustomerOrderPaymentProofController::class)
        ->whereNumber('order')
        ->name('orders.payment-proof.store');
    Route::get('orders/{order}', [OrderController::class, 'show'])
        ->whereNumber('order')
        ->name('orders.show');
});

// storefront chatbot
Route::post('chatbot', [ChatbotController::class, 'store'])
    ->middleware(['can:use-chatbot', 'throttle:chatbot'])
    ->name('chatbot.store');

// administration authority
Route::middleware(['auth', 'can:access-administration'])
    ->prefix('administration')
    ->name('administration.')
    ->group(function () {
        Route::get('work-queue', [WorkQueueController::class, 'index'])->name('work-queue.index');
        Route::resource('branches', AdministrationBranchController::class)
            ->middleware('can:branches.manage')
            ->except('destroy')
            ->where(['branch' => '[0-9]+']);
        // notifications
        Route::get('notifications', [AdministrationNotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/summary', [AdministrationNotificationController::class, 'summary'])->name('notifications.summary');
        Route::patch('notifications/read-all', [AdministrationNotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::patch('notifications/{notification}/read', [AdministrationNotificationController::class, 'update'])->whereUuid('notification')->name('notifications.update');

        // products
        Route::get('component-specifications', [ComponentSpecificationController::class, 'index'])
            ->middleware('can:products.manage')->name('component-specifications.index');
        Route::post('component-specifications', [ComponentSpecificationController::class, 'store'])
            ->middleware(['can:products.manage', 'throttle:30,1'])->name('component-specifications.store');
        Route::post('component-specifications/{componentSpecification}/approve', [ComponentSpecificationController::class, 'approve'])
            ->middleware(['can:products.manage', 'throttle:30,1'])->whereNumber('componentSpecification')->name('component-specifications.approve');
        Route::get('pc-build-templates', [PcBuildTemplateController::class, 'index'])
            ->middleware('can:products.manage')->name('pc-build-templates.index');
        Route::post('pc-build-templates', [PcBuildTemplateController::class, 'store'])
            ->middleware(['can:products.manage', 'throttle:30,1'])->name('pc-build-templates.store');
        Route::put('pc-build-templates/{pcBuildTemplate}', [PcBuildTemplateController::class, 'update'])
            ->middleware(['can:products.manage', 'throttle:30,1'])->whereNumber('pcBuildTemplate')->name('pc-build-templates.update');
        Route::delete('pc-build-templates/{pcBuildTemplate}', [PcBuildTemplateController::class, 'destroy'])
            ->middleware(['can:products.manage', 'throttle:30,1'])->whereNumber('pcBuildTemplate')->name('pc-build-templates.destroy');
        Route::get('pc-build-analytics', [PcBuildAnalyticsController::class, 'index'])
            ->middleware('can:products.manage')->name('pc-build-analytics.index');
        Route::post('products/{product}/restore', [ProductController::class, 'restore'])
            ->middleware('can:catalog.delete')
            ->name('products.restore');
        Route::resource('products', ProductController::class)
            ->middleware('can:products.manage');
        Route::patch('products/{product}/activation', ProductActivationController::class)
            ->middleware('can:products.manage')
            ->name('products.activation.update');

        // product inventory
        Route::post('products/{product}/inventory', [InventoryController::class, 'store'])
            ->middleware('can:inventory.request')
            ->name('products.inventory.store');

        // categories
        Route::post('categories/{category}/restore', [CategoryController::class, 'restore'])
            ->middleware('can:catalog.delete')
            ->name('categories.restore');
        Route::resource('categories', CategoryController::class)
            ->middleware('can:products.manage')
            ->except(['show']);
        Route::patch('categories/{category}/activation', CategoryActivationController::class)
            ->middleware('can:products.manage')
            ->name('categories.activation.update');

        // inventories
        Route::get('inventory', [InventoryController::class, 'index'])
            ->middleware('can:inventory.view')
            ->name('inventory.index');
        Route::patch('inventory/{inventory}', [InventoryController::class, 'update'])
            ->middleware('can:inventory.request')
            ->name('inventory.update');
        Route::post('inventory/{inventory}/physical-count', [InventoryController::class, 'count'])
            ->middleware('can:inventory.request')->name('inventory.count');
        Route::get('inventory-adjustments', [InventoryAdjustmentController::class, 'index'])
            ->middleware('can:inventory.view')
            ->name('inventory-adjustments.index');
        Route::patch('inventory/{inventory}/reorder-level', [InventoryController::class, 'updateReorderLevel'])
            ->middleware('can:inventory.reorder')->name('inventory.reorder-level.update');
        Route::post('inventory-adjustments/{adjustment}/decision', [InventoryAdjustmentController::class, 'decide'])
            ->middleware('can:inventory.approve')
            ->name('inventory-adjustments.decide');
        Route::post('inventory-adjustments/{adjustment}/withdrawal', [InventoryAdjustmentController::class, 'withdraw'])
            ->middleware('can:inventory.request')->name('inventory-adjustments.withdraw');
        Route::post('inventory-adjustments/{adjustment}/replacement', [InventoryAdjustmentController::class, 'replace'])
            ->middleware('can:inventory.request')->name('inventory-adjustments.replace');
        Route::get('damage-reports', [DamageReportController::class, 'index'])->middleware('can:inventory.view')->name('damage-reports.index');
        Route::post('damage-reports', [DamageReportController::class, 'store'])->middleware('can:returns.request')->name('damage-reports.store');
        Route::post('damage-reports/{damageReport}/decision', [DamageReportController::class, 'decide'])
            ->middleware('can:returns.approve')->name('damage-reports.decide');
        Route::get('suppliers', [SupplierController::class, 'index'])->middleware('can:suppliers.manage')->name('suppliers.index');
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('can:suppliers.manage')->name('suppliers.store');
        Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('can:suppliers.manage')->name('suppliers.update');
        Route::get('operations/{feature}/export/{format}', OperationalExportController::class)
            ->whereIn('feature', ['stock-requests', 'supplier-returns', 'customer-returns', 'customer-requests', 'damage-reports'])
            ->whereIn('format', ['csv', 'pdf', 'print'])->middleware('can:reports.export')->name('operations.export');
        Route::get('supplier-returns', [SupplierReturnController::class, 'index'])->middleware('can:inventory.view')->name('supplier-returns.index');
        Route::post('supplier-returns', [SupplierReturnController::class, 'store'])->middleware('can:returns.request')->name('supplier-returns.store');
        Route::post('supplier-returns/{supplierReturn}/decision', [SupplierReturnController::class, 'decide'])
            ->middleware('can:returns.approve')->name('supplier-returns.decide');
        Route::post('supplier-returns/{supplierReturn}/dispatch', [SupplierReturnController::class, 'dispatch'])
            ->middleware('can:returns.dispatch')->name('supplier-returns.dispatch');
        Route::post('supplier-returns/{supplierReturn}/acknowledgement', [SupplierReturnController::class, 'acknowledge'])
            ->middleware('can:returns.approve')->name('supplier-returns.acknowledge');
        Route::post('supplier-returns/{supplierReturn}/follow-up', [SupplierReturnController::class, 'followUp'])
            ->middleware('can:returns.approve')->name('supplier-returns.follow-up');
        Route::post('supplier-returns/{supplierReturn}/resolution', [SupplierReturnController::class, 'resolve'])
            ->middleware('can:returns.resolve')->name('supplier-returns.resolve');
        Route::get('customer-returns', [CustomerReturnController::class, 'index'])->middleware('can:orders.view')->name('customer-returns.index');
        Route::post('customer-return-requests/{returnRequest}/receive', [CustomerReturnController::class, 'receiveRequest'])
            ->whereNumber('returnRequest')->middleware(['can:orders.view', 'can:returns.request'])->name('customer-return-requests.receive');
        Route::post('customer-returns', [CustomerReturnController::class, 'store'])->middleware('can:returns.request')->name('customer-returns.store');
        Route::post('customer-returns/{customerReturn}/decision', [CustomerReturnController::class, 'decide'])
            ->middleware('can:returns.approve')->name('customer-returns.decide');
        Route::post('customer-returns/{customerReturn}/handover', [CustomerReturnController::class, 'handBack'])
            ->middleware('can:returns.dispatch')->name('customer-returns.handover');
        Route::post('customer-returns/{customerReturn}/resolution-completion', [CustomerReturnController::class, 'completeResolution'])
            ->whereNumber('customerReturn')->middleware(['can:orders.view', 'can:returns.dispatch'])->name('customer-returns.resolution-completion');
        Route::patch('orders/{order}/cancellation-request', [App\Http\Controllers\Administration\OrderCancellationRequestController::class, 'update'])
            ->whereNumber('order')->middleware(['can:orders.view', 'can:returns.approve'])->name('orders.cancellation-request.update');
        Route::post('customer-refunds/{refund}/payments', [CustomerReturnController::class, 'recordPayment'])
            ->middleware('can:returns.record_payment')->name('customer-refunds.payments.store');
        Route::post('customer-refund-payments/{payment}/review', [CustomerReturnController::class, 'reviewPayment'])
            ->middleware('can:returns.reconcile')->name('customer-refund-payments.review');
        Route::post('evidence/{subjectType}/{subjectId}', [AdministrationEvidenceController::class, 'store'])
            ->whereIn('subjectType', ['damage_report', 'customer_return', 'refund_payment'])
            ->whereNumber('subjectId')->name('evidence.store');
        Route::get('evidence/{evidence}', [AdministrationEvidenceController::class, 'show'])
            ->whereNumber('evidence')->name('evidence.show');

        // chatbots
        Route::post('chatbot-knowledge/preview', ChatbotKnowledgePreviewController::class)
            ->middleware('can:products.manage')
            ->name('chatbot-knowledge.preview');
        Route::resource('chatbot-knowledge', ChatbotKnowledgeController::class)
            ->middleware('can:products.manage')
            ->parameters(['chatbot-knowledge' => 'chatbotKnowledge'])
            ->except('destroy');
        Route::patch(
            'chatbot-knowledge/{chatbotKnowledge}/activation',
            ChatbotKnowledgeActivationController::class,
        )->middleware('can:products.manage')->name('chatbot-knowledge.activation.update');

        // preview customer orders
        Route::resource('customers', CustomerController::class)
            ->middleware('can:customers.view')
            ->only(['index', 'show']);

        // handling customer orders
        Route::get('orders/shipments/export/{format}', OrderShipmentExportController::class)
            ->whereIn('format', ['csv', 'pdf', 'print'])
            ->middleware(['can:orders.view', 'can:reports.export'])->name('orders.shipments.export');
        Route::resource('orders', AdministrationOrderController::class)
            ->middleware('can:orders.view')
            ->only(['index', 'show']);
        Route::post('orders/{order}/claim', [OrderHandlerController::class, 'claim'])
            ->middleware('can:orders.process')->name('orders.claim');
        Route::patch('orders/{order}/handler', [OrderHandlerController::class, 'assign'])
            ->middleware('can:orders.assign')->name('orders.handler.update');
        Route::patch('orders/{order}/status', [OrderStatusController::class, 'update'])
            ->middleware('can:orders.process')
            ->name('orders.status.update');
        Route::post('orders/{order}/shipment/manual', [OrderManualShipmentController::class, 'store'])
            ->middleware('can:orders.process')
            ->name('orders.shipment.manual.store');
        Route::post('orders/{order}/shipment/local-lbc-booking', [OrderLocalLbcBookingController::class, 'store'])
            ->middleware('can:orders.process')
            ->name('orders.shipment.local-lbc-booking.store');
        Route::patch('orders/{order}/shipment/status', [OrderShipmentStatusController::class, 'update'])
            ->middleware('can:orders.process')
            ->name('orders.shipment.status.update');
        Route::patch('orders/{order}/shipment/reference', [OrderShipmentReferenceController::class, 'update'])
            ->middleware('can:orders.process')
            ->name('orders.shipment.reference.update');
        Route::patch('orders/{order}/shipment/operations', [OrderShipmentOperationsController::class, 'update'])
            ->middleware('can:orders.process')
            ->name('orders.shipment.operations.update');
        Route::post('orders/{order}/payment-reconciliation', OrderPaymentReconciliationController::class)
            ->middleware(['can:orders.view', 'can:orders.process', 'throttle:10,1'])
            ->name('orders.payment-reconciliation.store');
        Route::patch('orders/{order}/payment-status', [OrderPaymentStatusController::class, 'update'])
            ->middleware('can:orders.process')
            ->name('orders.payment-status.update');
        Route::post('orders/{order}/cod-collection', [OrderCodCollectionController::class, 'store'])
            ->middleware('can:orders.process')
            ->whereNumber('order')
            ->name('orders.cod-collection.store');
        Route::post('orders/{order}/cod-remittance', [OrderCodRemittanceController::class, 'store'])
            ->middleware('can:orders.process')
            ->whereNumber('order')
            ->name('orders.cod-remittance.store');
        Route::post('orders/{order}/pickup-confirmation', [OrderPickupConfirmationController::class, 'store'])
            ->middleware('can:orders.process')
            ->whereNumber('order')
            ->name('orders.pickup-confirmation.store');
        Route::get('orders/{order}/payment-proof', OrderPaymentProofController::class)
            ->middleware('can:orders.view')
            ->name('orders.payment-proof.show');

        // sales report
        Route::get('reports/sales', [SalesReportController::class, 'index'])
            ->middleware('can:reports.view')
            ->name('reports.sales');
        Route::get('reports/wishlist', [WishlistReportController::class, 'index'])->middleware('can:reports.view')->name('reports.wishlist');
        Route::get('reports/wishlist/csv', [WishlistReportController::class, 'csv'])->middleware('can:reports.export')->name('reports.wishlist.csv');
        Route::get('reports/staff', [StaffPerformanceController::class, 'index'])
            ->middleware('can:orders.view')->name('reports.staff');
        Route::get('reports/stock-reconciliation', [StockReconciliationController::class, 'index'])
            ->middleware('can:inventory.view')->name('reports.stock-reconciliation');
        Route::get('reports/audit', [AuditLogController::class, 'index'])
            ->middleware('can:audit.view')->name('reports.audit');
        Route::get('reports/audit/export', [AuditLogController::class, 'export'])
            ->middleware('can:audit.export')->name('reports.audit.export');
        Route::post('reports/sales/reset', [SalesReportController::class, 'reset'])
            ->middleware('can:reports.view')
            ->name('reports.sales.reset');
        Route::get('reports/sales/print', [SalesReportController::class, 'printReport'])
            ->middleware('can:reports.export')
            ->name('reports.sales.print');
        Route::get('reports/sales/pdf', [SalesReportController::class, 'pdf'])
            ->middleware('can:reports.export')
            ->name('reports.sales.pdf');
        Route::get('reports/sales/csv', [SalesReportController::class, 'csv'])
            ->middleware('can:reports.export')
            ->name('reports.sales.csv');

        // predictive analytics
        Route::get('forecasting', [ForecastingController::class, 'index'])
            ->middleware('can:reports.view')
            ->name('forecasting.index');
        Route::post('forecasting', [ForecastingController::class, 'store'])
            ->middleware('can:forecast.manage')
            ->name('forecasting.store');

        Route::get('team', [TeamController::class, 'index'])->middleware('can:users.manage')->name('team.index');
        Route::middleware(['can:users.manage', 'throttle:team-email-verification'])->group(function (): void {
            Route::post('team/email-verification', [TeamEmailVerificationController::class, 'store'])->name('team.email-verification.store');
            Route::post('team/email-verification/verify', [TeamEmailVerificationController::class, 'verify'])->name('team.email-verification.verify');
            Route::delete('team/email-verification', [TeamEmailVerificationController::class, 'destroy'])->name('team.email-verification.destroy');
        });
        Route::post('team', [TeamController::class, 'store'])->middleware('can:users.manage')->name('team.store');
        Route::patch('team/{user}', [TeamController::class, 'update'])->middleware('can:users.manage')->name('team.update');
        Route::put('team/roles/{role}/permissions', [RolePermissionController::class, 'update'])
            ->middleware('can:permissions.manage')->name('team.permissions.update');
    });

// profile settings
require __DIR__.'/settings.php';
