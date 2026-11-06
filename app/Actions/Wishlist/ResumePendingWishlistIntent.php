<?php

namespace App\Actions\Wishlist;

use App\Models\User;
use App\Services\Wishlist\WishlistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ResumePendingWishlistIntent
{
    public function __construct(private WishlistService $wishlistService) {}

    public function execute(Request $request): ?RedirectResponse
    {
        $intent = $request->session()->pull('pending_wishlist_intent');

        if (! is_array($intent)
            || ! is_int($intent['product_id'] ?? null) || $intent['product_id'] <= 0
            || ! is_int($intent['expires_at'] ?? null) || $intent['expires_at'] <= now()->getTimestamp()
            || ! is_string($intent['token'] ?? null) || ! Str::isUuid($intent['token'])) {
            return null;
        }

        if (! Cache::add('pending-wishlist-intent:'.$intent['token'], true, max(1, $intent['expires_at'] - now()->getTimestamp()))) {
            return null;
        }

        $customer = $request->user();

        if (! $customer instanceof User || ! $customer->can('use-customer-cart')) {
            return null;
        }

        $request->session()->forget('url.intended');

        try {
            $added = $this->wishlistService->add($customer, $intent['product_id']);
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => $added ? __('Product saved to your wishlist.') : __('Product is already in your wishlist.'),
            ]);
        } catch (ValidationException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->errors()['product_id'][0] ?? __('This product cannot be saved right now.'),
            ]);
        }

        return to_route('wishlist.index');
    }
}
