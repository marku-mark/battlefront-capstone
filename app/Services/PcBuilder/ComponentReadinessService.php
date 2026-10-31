<?php

namespace App\Services\PcBuilder;

use App\Models\Product;
use App\Models\ProductComponentProfile;
use Illuminate\Support\Collection;

class ComponentReadinessService
{
    /** @return list<array<string, mixed>> */
    public function candidates(): array
    {
        return array_values(Product::query()->customerEligible()
            ->with(['category', 'inventory', 'componentProfile.revisions'])
            ->orderBy('name')->orderBy('id')->get()
            ->map(fn (Product $product): array => $this->present($product))->all());
    }

    /** @return array<string, mixed> */
    private function present(Product $product): array
    {
        $types = [
            'Processor' => 'processor', 'Motherboard' => 'motherboard', 'RAM' => 'memory',
            'Storage' => 'storage', 'Graphics Card' => 'graphics_card', 'Power Supply' => 'power_supply',
            'PC Case' => 'case', 'Monitor' => 'monitor',
        ];
        $profile = $product->componentProfile;
        $revision = $profile?->revisions->firstWhere('revision', $profile->current_revision);
        $type = $profile->component_type ?? ($types[$product->category->name] ?? null);
        $excluded = preg_match('/laptop ram|sodimm|flashdrive|flash drive|enclosure|external case|card reader/i', $product->name) === 1;
        $status = 'needs_identity';
        $reason = 'Confirm exact model, hardware revision, included accessories and manufacturer evidence.';
        if ($profile !== null) {
            $status = $profile->review_status === 'approved' && $revision?->review_status === 'approved' ? 'reviewed' : 'needs_review';
            $reason = $status === 'reviewed' ? 'Specifications reviewed; complete-build compatibility must still be checked.' : 'Review the draft and complete missing technical evidence.';
        } elseif ($excluded || $type === null) {
            $status = 'not_core_component';
            $reason = 'Not automatically eligible for a core desktop slot. Manually review peripherals and ambiguous products.';
            $type = null;
        }

        return [
            'product_id' => $product->id, 'name' => $product->name, 'product_code' => $product->product_code,
            'category' => $product->category->name, 'suggested_type' => $type,
            'status' => $status, 'reason' => $reason, 'stock_quantity' => $product->inventory->quantity ?? 0,
            'profile_id' => $profile?->id,
        ];
    }

    /** @return Collection<int, covariant array<string, mixed>> */
    public function specificationProfiles(): Collection
    {
        return ProductComponentProfile::query()->with(['product', 'revisions'])->orderBy('component_type')->get()
            ->map(function (ProductComponentProfile $profile): array {
                $revision = $profile->revisions->firstWhere('revision', $profile->current_revision);

                return [
                    'id' => $profile->id,
                    'product_id' => $profile->product_id,
                    'product_name' => $profile->product->name,
                    'product_code' => $profile->product->product_code,
                    'component_type' => $profile->component_type,
                    'manufacturer_part_number' => $profile->manufacturer_part_number,
                    'hardware_revision' => $profile->hardware_revision,
                    'review_status' => $profile->review_status,
                    'edit_version' => $profile->edit_version,
                    'specifications' => $revision->specifications ?? [],
                    'evidence' => $revision->evidence ?? [],
                    'revision' => $revision?->revision,
                    'incomplete_attributes' => $this->incompleteAttributes($profile->component_type, $revision->specifications ?? []),
                ];
            });
    }

    /** @param array<string, mixed> $specifications
     * @return list<string>
     */
    private function incompleteAttributes(string $componentType, array $specifications): array
    {
        $requiredByType = [
            'processor' => ['socket', 'generation', 'supported_chipsets', 'maximum_power_w', 'integrated_graphics', 'included_cooler', 'required_power_connectors'],
            'motherboard' => ['socket', 'chipset', 'supported_cpu_generations', 'bios_version', 'memory_generations', 'dimm_slots', 'maximum_memory_gb', 'maximum_memory_speed_mts', 'ecc_supported', 'registered_memory_supported', 'form_factor', 'pcie_x16_slots', 'display_outputs', 'storage_slots', 'fan_headers'],
            'memory' => ['memory_generation', 'capacity_gb', 'modules_per_package', 'speed_mts', 'ecc', 'registered'],
            'storage' => ['interface', 'form_factor'],
            'power_supply' => ['continuous_power_w', 'connectors', 'form_factor', 'length_mm'],
            'case' => ['motherboard_form_factors', 'maximum_gpu_length_mm', 'maximum_cpu_cooler_height_mm', 'supported_psu_form_factors', 'maximum_psu_length_mm'],
            'graphics_card' => ['length_mm', 'thickness_mm', 'board_power_w', 'transient_power_w', 'required_power_connectors', 'pcie_slots_required'],
            'cooler' => ['mounting_sockets', 'height_mm', 'radiator_size_mm'],
            'fan' => ['size_mm', 'power_draw_w', 'header_type'],
            'monitor' => ['input_ports', 'resolution', 'refresh_rate_hz'],
        ];

        return array_values(array_filter(
            $requiredByType[$componentType] ?? [],
            fn (string $attribute): bool => ! array_key_exists($attribute, $specifications) || $specifications[$attribute] === null || $specifications[$attribute] === '',
        ));
    }
}
