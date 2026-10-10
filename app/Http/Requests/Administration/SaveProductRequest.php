<?php

namespace App\Http\Requests\Administration;

use App\Actions\Catalog\CatalogName;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => CatalogName::normalize($this->input('name'))]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('access-administration') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $importedCodeRule = $product instanceof Product && $product->is_catalog_imported
            ? [Rule::in([$product->product_code])]
            : [];

        return [
            'product_code' => $product instanceof Product ? [
                'bail', 'required', 'string', 'regex:'.Product::CODE_PATTERN,
                Rule::unique(Product::class, 'product_code')->ignore($product),
                ...$importedCodeRule,
            ] : ['exclude'],
            'name' => [
                'bail', 'required', 'string', 'max:255',
                CatalogName::uniqueRule(new Product, $product instanceof Product ? $product : null, CatalogName::PRODUCT_MESSAGE),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists(Category::class, 'id'),
            ],
            'brand' => ['bail', 'nullable', 'string', 'max:255'],
            'price' => [
                'bail',
                'required',
                'numeric',
                'decimal:0,2',
                'min:0',
                'max:9999999999.99',
            ],
            'is_featured' => ['required', 'boolean'],
            'discount_price' => [
                'nullable',
                'numeric',
                'decimal:0,2',
                'min:0',
                'lt:price',
                'max:9999999999.99',
            ],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => [
                'integer',
                'distinct',
                Rule::exists(Tag::class, 'id'),
            ],
        ];
    }

    /**
     * Get the validation messages for product details.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_code.required' => 'Enter a product code.',
            'product_code.regex' => 'Use 1 to 64 letters or digits for the product code.',
            'product_code.unique' => 'This product code is already in use.',
            'product_code.in' => 'Imported product codes cannot be changed.',
            'name.required' => 'Enter a product name.',
            'category_id.required' => 'Select a category.',
            'category_id.exists' => 'Select a valid category.',
            'price.required' => 'Enter the regular price.',
            'price.numeric' => 'The regular price must be a number.',
            'price.decimal' => 'Use no more than two decimal places for the regular price.',
            'price.min' => 'The regular price must be zero or greater.',
            'is_featured.required' => 'Choose whether this is a featured product.',
            'discount_price.numeric' => 'The discount price must be a number.',
            'discount_price.decimal' => 'Use no more than two decimal places for the discount price.',
            'discount_price.min' => 'The discount price must be zero or greater.',
            'discount_price.lt' => 'The discount price must be lower than the regular price.',
            'image.image' => 'Upload a valid product image.',
            'image.mimes' => 'The product image must be a JPG, JPEG, PNG, or WebP file.',
            'image.max' => 'The product image may not be larger than 5 MB.',
            'tag_ids.array' => 'Select product tags as a list.',
            'tag_ids.*.distinct' => 'Each tag may only be selected once.',
            'tag_ids.*.exists' => 'Select valid product tags.',
        ];
    }
}
