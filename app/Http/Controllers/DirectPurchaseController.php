<?php

namespace App\Http\Controllers;

use App\Services\Order\DirectPurchase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DirectPurchaseController extends Controller
{
    public function store(Request $request, DirectPurchase $purchase): RedirectResponse
    {
        abort_unless($request->user() === null || $request->user()->can('use-customer-cart'), 403);
        $data = $request->validate(['product_id' => ['required', 'integer', 'min:1'], 'quantity' => ['required', 'integer', 'min:1', 'max:4294967295']]);
        $key = $purchase->start((int) $data['product_id'], (int) $data['quantity']);
        $url = route('checkout.index', ['direct_purchase_key' => $key]);
        if ($request->user() === null) {
            $request->session()->put('url.intended', $url);

            return to_route('login');
        }

        return redirect($url);
    }
}
