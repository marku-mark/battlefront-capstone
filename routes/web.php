<?php

use App\Http\Controllers\Administration\CategoryActivationController;
use App\Http\Controllers\Administration\CategoryController;
use App\Http\Controllers\Administration\ChatbotKnowledgeActivationController;
use App\Http\Controllers\Administration\ChatbotKnowledgeController;
use App\Http\Controllers\Administration\ChatbotKnowledgePreviewController;
use App\Http\Controllers\Administration\CustomerController;
use App\Http\Controllers\Administration\ForecastingController;
use App\Http\Controllers\Administration\InventoryController;
use App\Http\Controllers\Administration\NotificationController as AdministrationNotificationController;
use App\Http\Controllers\Administration\OrderController as AdministrationOrderController;
use App\Http\Controllers\Administration\OrderPaymentProofController;
use App\Http\Controllers\Administration\OrderPaymentStatusController;
use App\Http\Controllers\Administration\OrderShipmentReferenceController;
use App\Http\Controllers\Administration\OrderShipmentStatusController;
use App\Http\Controllers\Administration\OrderStatusController;
use App\Http\Controllers\Administration\ProductActivationController;
use App\Http\Controllers\Administration\ProductController;
use App\Http\Controllers\Administration\SalesReportController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CartItemController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NotificationController as CustomerNotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderPaymentProofController as CustomerOrderPaymentProofController;
use App\Http\Controllers\ProductCatalogController;
use App\Http\Controllers\ProductDwellController;
use App\Http\Controllers\ProductViewController;
use App\Http\Controllers\RecommendationInteractionController;
use Illuminate\Support\Facades\Route;

// guest landing page
Route::get('/', [HomeController::class, 'index'])->name('home');

// public/customer branches
Route::get('branches', [BranchController::class, 'index'])->name('branches.index');

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

// customer checkout preview
Route::middleware(['auth', 'can:use-customer-cart'])
    ->prefix('checkout')
    ->name('checkout.')
    ->group(function () {
        Route::get('/', [CheckoutController::class, 'index'])
            ->name('index');
    });

// customer orders
Route::middleware(['auth', 'can:use-customer-cart'])->group(function () {
    Route::get('notifications', [CustomerNotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/summary', [CustomerNotificationController::class, 'summary'])->name('notifications.summary');
    Route::patch('notifications/read-all', [CustomerNotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::patch('notifications/{notification}/read', [CustomerNotificationController::class, 'update'])->whereUuid('notification')->name('notifications.update');
    Route::get('orders', [OrderController::class, 'index'])
        ->name('orders.index');
    Route::post('orders', [OrderController::class, 'store'])
        ->name('orders.store');
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
        // notifications
        Route::get('notifications', [AdministrationNotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/summary', [AdministrationNotificationController::class, 'summary'])->name('notifications.summary');
        Route::patch('notifications/read-all', [AdministrationNotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::patch('notifications/{notification}/read', [AdministrationNotificationController::class, 'update'])->whereUuid('notification')->name('notifications.update');

        // products
        Route::resource('products', ProductController::class);
        Route::patch('products/{product}/activation', ProductActivationController::class)
            ->name('products.activation.update');

        // product inventory
        Route::post('products/{product}/inventory', [InventoryController::class, 'store'])
            ->name('products.inventory.store');

        // categories
        Route::resource('categories', CategoryController::class)
            ->except(['show']);
        Route::patch('categories/{category}/activation', CategoryActivationController::class)
            ->name('categories.activation.update');

        // inventories
        Route::get('inventory', [InventoryController::class, 'index'])
            ->name('inventory.index');
        Route::patch('inventory/{inventory}', [InventoryController::class, 'update'])
            ->name('inventory.update');

        // chatbots
        Route::post('chatbot-knowledge/preview', ChatbotKnowledgePreviewController::class)
            ->name('chatbot-knowledge.preview');
        Route::resource('chatbot-knowledge', ChatbotKnowledgeController::class)
            ->parameters(['chatbot-knowledge' => 'chatbotKnowledge'])
            ->except('destroy');
        Route::patch(
            'chatbot-knowledge/{chatbotKnowledge}/activation',
            ChatbotKnowledgeActivationController::class,
        )->name('chatbot-knowledge.activation.update');

        // preview customer orders
        Route::resource('customers', CustomerController::class)
            ->only(['index', 'show']);

        // handling customer orders
        Route::resource('orders', AdministrationOrderController::class)
            ->only(['index', 'show']);
        Route::patch('orders/{order}/status', [OrderStatusController::class, 'update'])
            ->name('orders.status.update');
        Route::patch('orders/{order}/shipment/status', [OrderShipmentStatusController::class, 'update'])
            ->name('orders.shipment.status.update');
        Route::patch('orders/{order}/shipment/reference', [OrderShipmentReferenceController::class, 'update'])
            ->name('orders.shipment.reference.update');
        Route::patch('orders/{order}/payment-status', [OrderPaymentStatusController::class, 'update'])
            ->name('orders.payment-status.update');
        Route::get('orders/{order}/payment-proof', OrderPaymentProofController::class)
            ->name('orders.payment-proof.show');

        // sales report
        Route::get('reports/sales', [SalesReportController::class, 'index'])
            ->name('reports.sales');

        // predictive analytics
        Route::get('forecasting', [ForecastingController::class, 'index'])
            ->name('forecasting.index');
        Route::post('forecasting', [ForecastingController::class, 'store'])
            ->name('forecasting.store');
    });

// profile settings
require __DIR__.'/settings.php';
