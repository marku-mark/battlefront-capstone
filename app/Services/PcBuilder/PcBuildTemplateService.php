<?php

namespace App\Services\PcBuilder;

use App\Models\PcBuildTemplate;
use App\Models\PcBuildTemplateItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PcBuildTemplateService
{
    public function __construct(private readonly CompatibilityEngine $compatibilityEngine) {}

    /** @return Collection<int, PcBuildTemplate> */
    public function adminList(): Collection
    {
        return PcBuildTemplate::query()->with(['items.product'])->orderBy('name')->get();
    }

    /** @param array{name: string, description?: string|null, budget?: string|null, is_active: bool, edit_version?: int, components: list<array{key: string, product_id: int, quantity: int, configuration?: array<string, mixed>}>} $data */
    public function save(User $administrator, array $data, ?PcBuildTemplate $template = null): PcBuildTemplate
    {
        return DB::transaction(function () use ($administrator, $data, $template): PcBuildTemplate {
            if ($template !== null) {
                $template = PcBuildTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
                if ($template->edit_version !== ($data['edit_version'] ?? null)) {
                    throw ValidationException::withMessages(['edit_version' => 'This template changed in another session. Reload before saving.']);
                }
            }

            $productIds = array_values(array_unique(array_column($data['components'], 'product_id')));
            $products = Product::query()->whereKey($productIds)->lockForUpdate()->with('componentProfile.revisions')->get()->keyBy('id');
            if ($products->count() !== count($productIds)) {
                throw ValidationException::withMessages(['components' => 'A template component no longer exists.']);
            }

            $validationComponents = [];
            foreach ($data['components'] as $component) {
                $product = $products->get($component['product_id']);
                $profile = $product?->componentProfile;
                $revision = $profile?->revisions->firstWhere('revision', $profile->current_revision);
                if (! $product?->is_active || $profile?->review_status !== 'approved' || $revision?->review_status !== 'approved') {
                    throw ValidationException::withMessages(['components' => 'Templates may use only active products with reviewed specifications.']);
                }

                $validationComponents[] = [
                    'key' => $component['key'],
                    'product_id' => $product->id,
                    'component_type' => $profile->component_type,
                    'quantity' => $component['quantity'],
                    'configuration' => $component['configuration'] ?? [],
                    'specifications' => $revision->specifications,
                ];
            }

            $validation = $this->compatibilityEngine->evaluate($validationComponents);
            if ($data['is_active'] && ! $validation['complete']) {
                throw ValidationException::withMessages(['components' => 'A published template must have verified compatibility for every required component.']);
            }

            $template ??= new PcBuildTemplate;
            $template->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'budget' => $data['budget'] ?? null,
                'is_active' => $data['is_active'],
                'edit_version' => ($template->edit_version ?? 0) + 1,
                'created_by_id' => $template->exists ? $template->created_by_id : $administrator->id,
            ])->save();
            $template->items()->delete();

            foreach ($data['components'] as $position => $component) {
                $product = $products->get($component['product_id']);
                $template->items()->create([
                    'product_id' => $product->id,
                    'component_type' => $product->componentProfile->component_type,
                    'slot_key' => $component['key'],
                    'quantity' => $component['quantity'],
                    'position' => $position,
                    'configuration' => $component['configuration'] ?? [],
                ]);
            }

            return $template->load(['items.product']);
        }, attempts: 3);
    }

    public function delete(PcBuildTemplate $template): void
    {
        $template->delete();
    }

    /** @param array<string, list<array<string, mixed>>> $catalog
     * @return list<array<string, mixed>>
     */
    public function forCatalog(array $catalog): array
    {
        $available = collect($catalog)->flatten(1)->keyBy('id');

        return array_values(PcBuildTemplate::query()->where('is_active', true)->with(['items.product'])->orderBy('name')->get()
            ->filter(fn (PcBuildTemplate $template): bool => $template->items->isNotEmpty()
                && $template->items->every(fn ($item): bool => $available->has($item->product_id)
                    && ($available->get($item->product_id)['specifications_reviewed'] ?? false)
                    && ($available->get($item->product_id)['stock_quantity'] ?? 0) >= $item->quantity)
                && $this->compatibilityEngine->evaluate(array_values($template->items->map(fn ($item): array => [
                    'key' => $item->slot_key,
                    'product_id' => $item->product_id,
                    'component_type' => $item->component_type,
                    'quantity' => $item->quantity,
                    'configuration' => $item->configuration ?? [],
                    'specifications' => $available->get($item->product_id)['specifications'],
                ])->all()))['complete'])
            ->map(fn (PcBuildTemplate $template): array => [
                'uuid' => $template->uuid,
                'name' => $template->name,
                'description' => $template->description,
                'budget' => $template->budget,
                'components' => $template->items->map(fn (PcBuildTemplateItem $item): array => [
                    'key' => $item->slot_key,
                    'product_id' => $item->product_id,
                    'component_type' => $item->component_type,
                    'quantity' => $item->quantity,
                    'configuration' => $item->configuration ?? [],
                ])->values()->all(),
            ])->all());
    }

    /** @return array<int, array<string, mixed>> */
    public function administrationProducts(): array
    {
        $products = Product::query()->where('is_active', true)
            ->whereHas('componentProfile', fn ($query) => $query->where('review_status', 'approved'))
            ->with('componentProfile.revisions')->orderBy('name')->get(['id', 'name', 'product_code', 'brand']);

        return $products->map(fn (Product $product): array => [
            'id' => $product->id,
            'name' => $product->name,
            'product_code' => $product->product_code,
            'component_type' => $product->componentProfile->component_type,
            'fan_mounting_positions' => $product->componentProfile->revisions
                ->firstWhere('revision', $product->componentProfile->current_revision)
                ?->specifications['fan_mounting_positions'] ?? [],
        ])->all();
    }

    /** @return array<string, mixed> */
    public function presentForAdministration(PcBuildTemplate $template): array
    {
        return [
            'id' => $template->id,
            'uuid' => $template->uuid,
            'name' => $template->name,
            'description' => $template->description,
            'budget' => $template->budget,
            'is_active' => $template->is_active,
            'edit_version' => $template->edit_version,
            'components' => $template->items->map(fn ($item): array => [
                'key' => $item->slot_key,
                'product_id' => $item->product_id,
                'component_type' => $item->component_type,
                'quantity' => $item->quantity,
                'configuration' => $item->configuration ?? [],
                'product_name' => $item->product?->name,
                'product_code' => $item->product?->product_code,
            ])->all(),
        ];
    }
}
