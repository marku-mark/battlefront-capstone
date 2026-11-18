<?php

namespace App\Http\Controllers;

use App\Actions\Cart\CheckoutUnavailableException;
use App\Actions\Checkout\PrepareCheckout;
use App\Http\Requests\CheckoutPreviewRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    /**
     * Display checkout for the authenticated customer's ready cart.
     */
    public function index(
        CheckoutPreviewRequest $request,
        PrepareCheckout $prepareCheckout,
    ): Response|RedirectResponse {
        /** @var User $customer */
        $customer = $request->user();

        try {
            $checkout = $prepareCheckout->execute($customer, $request->cartItemIds());
        } catch (ValidationException $exception) {
            if (! $request->filled('direct_purchase_key') || $request->expectsJson()) {
                throw $exception;
            }
            $selection = $request->session()->pull('direct_purchase');
            Inertia::flash('toast', ['type' => 'error', 'message' => collect($exception->errors())->flatten()->first() ?? 'Please select the product again.']);

            return isset($selection['product_id'])
                ? to_route('products.show', $selection['product_id'])
                : to_route('products.index');
        } catch (CheckoutUnavailableException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);

            return to_route('cart.index');
        }

        return Inertia::render('Checkout/Index', $checkout);
    }
}
