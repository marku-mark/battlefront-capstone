<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePendingWishlistIntentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class PendingWishlistIntentController extends Controller
{
    public function store(StorePendingWishlistIntentRequest $request): RedirectResponse
    {
        $request->session()->put('pending_wishlist_intent', [
            'product_id' => $request->integer('product_id'),
            'expires_at' => now()->addMinutes(15)->getTimestamp(),
            'token' => (string) Str::uuid(),
        ]);

        return to_route('login');
    }
}
