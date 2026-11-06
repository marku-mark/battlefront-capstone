<?php

namespace App\Http\Requests;

class StorePendingWishlistIntentRequest extends StoreWishlistItemRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() === null;
    }
}
