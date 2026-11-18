<?php

namespace App\Http\Requests;

use App\Services\Order\DirectPurchase;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CheckoutPreviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('use-customer-cart') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'direct_purchase_key' => ['sometimes', 'required', 'uuid'],
            'cart_item_ids' => ['bail', $this->filled('direct_purchase_key') ? 'sometimes' : 'required', 'array', 'list', 'min:1'],
            'cart_item_ids.*' => ['bail', 'required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /** @return list<int> */
    public function cartItemIds(): array
    {
        if ($this->filled('direct_purchase_key')) {
            app(DirectPurchase::class)->selection();

            return [];
        }
        $ids = [];

        foreach ($this->validated('cart_item_ids') as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cart_item_ids.required' => 'Select at least one cart item before checking out.',
            'cart_item_ids.min' => 'Select at least one cart item before checking out.',
            'cart_item_ids.array' => 'Submit selected cart items as a list of IDs.',
            'cart_item_ids.list' => 'Submit selected cart items as a list of IDs.',
            'cart_item_ids.*.required' => 'Select valid cart items.',
            'cart_item_ids.*.integer' => 'Select valid cart items.',
            'cart_item_ids.*.min' => 'Select valid cart items.',
            'cart_item_ids.*.distinct' => 'Select each cart item only once.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->routeIs('checkout.index') ? route('cart.index') : parent::getRedirectUrl();
    }
}
