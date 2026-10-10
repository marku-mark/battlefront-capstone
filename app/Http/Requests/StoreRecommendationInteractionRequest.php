<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecommendationInteractionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'uuid'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)],
            'event_type' => ['required', Rule::in(['impression', 'click', 'dismiss', 'report_wrong'])],
            'placement' => ['required', Rule::in(['home', 'product', 'cart', 'recommendations', 'dashboard', 'catalog'])],
            'position' => ['required', 'integer', 'min:1', 'max:12'],
            'reason_code' => ['nullable', Rule::in([
                'matched_recent_searches', 'similar_to_viewed_product', 'spent_time_viewing_product',
                'bought_with_cart_products', 'bought_with_viewed_products', 'bought_with_purchase_history',
                'bought_by_similar_customers', 'popular_with_customers', 'featured_fallback',
            ])],
        ];
    }

    /**
     * Return the validated interaction fields with stable scalar types.
     *
     * @return array{event_id: string, product_id: int, event_type: string, placement: string, position: int, reason_code: string|null}
     */
    public function validatedInteraction(): array
    {
        $validated = $this->validated();

        return [
            'event_id' => (string) $validated['event_id'],
            'product_id' => (int) $validated['product_id'],
            'event_type' => (string) $validated['event_type'],
            'placement' => (string) $validated['placement'],
            'position' => (int) $validated['position'],
            'reason_code' => isset($validated['reason_code']) ? (string) $validated['reason_code'] : null,
        ];
    }
}
