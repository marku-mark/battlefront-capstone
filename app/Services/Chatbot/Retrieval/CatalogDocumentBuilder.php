<?php

namespace App\Services\Chatbot\Retrieval;

use App\Models\Product;

class CatalogDocumentBuilder
{
    /**
     * @return array<string,mixed> */
    public function build(Product $product): array
    {
        $product->loadMissing(['category', 'tags', 'componentProfile.currentApprovedRevision']);
        $profile = $product->componentProfile;
        $revision = $profile?->review_status === 'approved'
            ? $profile->currentApprovedRevision
            : null;
        $data = [
            'schema_version' => 1,
            'embedding_model' => (string) config('ai_integration.embedding.model'),
            'embedding_dimensions' => (int) config('ai_integration.embedding.dimensions'),
            'product_id' => (int) $product->id,
            'product_code' => $product->product_code,
            'name' => $product->name,
            'brand' => $product->brand,
            'category_id' => (int) $product->category_id,
            'category' => $product->category->name,
            'tags' => $product->tags->pluck('name')->sort()->values()->all(),
            'component_type' => $revision ? $profile->component_type : null,
            'specification_revision' => $revision?->revision,
            'specifications' => $revision->specifications ?? [],
        ];
        $text = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $description = strip_tags($product->description ?? '');
        $data['text'] = mb_strcut($text, 0, 1300, 'UTF-8')."\n".mb_strcut($description, 0, 500, 'UTF-8');
        $data['truncated'] = strlen($text) > 1300 || strlen($description) > 500;
        $data['hash'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return $data;
    }
}
