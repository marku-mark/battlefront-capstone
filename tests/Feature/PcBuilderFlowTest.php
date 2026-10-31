<?php

use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
use App\Models\CartItem;
use App\Models\ComponentSpecificationRevision;
use App\Models\Inventory;
use App\Models\PcBuild;
use App\Models\PcBuildCartGroup;
use App\Models\PcBuildEvent;
use App\Models\Product;
use App\Models\ProductComponentProfile;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Order\OrderPlacementService;
use App\Services\PcBuilder\PcBuildTemplateService;
use App\Services\PcBuilder\PcBuildToCartService;
use App\Services\PcBuilder\SavedPcBuildService;
use App\Services\Wishlist\WishlistService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

test('specification index preserves current revision and missing attribute semantics', function (bool $missingRevision) {
    $product = Product::factory()->create();
    $profile = ProductComponentProfile::factory()->for($product)->create(['component_type' => 'processor', 'current_revision' => 2]);
    $specifications = ['socket' => '', 'generation' => null, 'supported_chipsets' => [], 'maximum_power_w' => 0, 'integrated_graphics' => false, 'included_cooler' => false];
    ComponentSpecificationRevision::factory()->for($profile, 'profile')->create(['revision' => 1, 'specifications' => ['socket' => 'old']]);
    if (! $missingRevision) {
        ComponentSpecificationRevision::factory()->for($profile, 'profile')->create(['revision' => 2, 'specifications' => $specifications, 'evidence' => []]);
    }

    $this->actingAs(User::factory()->administrator()->create())->get(route('administration.component-specifications.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profiles.0.product_name', $product->name)
            ->where('profiles.0.revision', $missingRevision ? null : 2)
            ->where('profiles.0.specifications', $missingRevision ? [] : $specifications)
            ->where('profiles.0.evidence', [])
            ->where('profiles.0.incomplete_attributes', $missingRevision
                ? ['socket', 'generation', 'supported_chipsets', 'maximum_power_w', 'integrated_graphics', 'included_cooler', 'required_power_connectors']
                : ['socket', 'generation', 'required_power_connectors']));
})->with([false, true]);

test('guests can browse stock status without making unavailable components purchasable', function () {
    $processor = createBuilderProduct('processor', [
        'socket' => 'AM5',
        'generation' => 'Zen 4',
        'supported_chipsets' => ['B650'],
        'maximum_power_w' => 120,
        'integrated_graphics' => true,
    ]);
    createBuilderProduct('motherboard', ['socket' => 'AM5']);
    $outOfStock = Product::factory()->create();
    Inventory::factory()->for($outOfStock)->create(['quantity' => 0]);
    ProductComponentProfile::factory()->for($outOfStock)->create(['component_type' => 'processor']);

    $this->get(route('pc-builder.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PcBuilder/Index')
            ->has('components.processor', 2)
            ->where('components.processor', fn ($products) => collect($products)->contains('id', $processor->id) && collect($products)->contains(fn ($product) => $product['id'] === $outOfStock->id && $product['stock_quantity'] === 0))
            ->missing('components.motherboard.1'));
});

test('the builder reuses customer wishlist state for existing catalog products', function () {
    $customer = User::factory()->customer()->create();
    $processor = createBuilderProduct('processor', ['socket' => 'AM5']);
    app(WishlistService::class)->add($customer, $processor->id);

    $this->actingAs($customer)
        ->get(route('pc-builder.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('components.processor.0.is_wishlisted', true));
});

test('product managers create and approve immutable hardware specification revisions', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    $payload = [
        'product_id' => (string) $product->id,
        'component_type' => 'processor',
        'manufacturer_part_number' => 'BF-CPU-9000',
        'hardware_revision' => '1.0',
        'specifications' => ['socket' => 'AM5', 'generation' => 'Zen 5'],
        'evidence' => [[
            'url' => 'https://manufacturer.example/specs',
            'title' => 'Processor specifications',
            'section' => 'Specifications',
            'verified_at' => '2026-10-27',
            'variant' => 'BF-CPU-9000',
        ]],
    ];

    $this->actingAs($administrator)
        ->postJson(route('administration.component-specifications.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('review_status', 'draft')
        ->assertJsonPath('revision', 1);

    $profile = ProductComponentProfile::query()->where('product_id', $product->id)->firstOrFail();
    $this->postJson(route('administration.component-specifications.approve', $profile), [
        'edit_version' => $profile->edit_version,
    ])->assertOk()->assertJsonPath('review_status', 'approved')->assertJsonPath('revision', 2);

    expect($profile->refresh()->review_status)->toBe('approved')
        ->and($profile->revisions()->count())->toBe(2)
        ->and($profile->revisions()->where('revision', 1)->firstOrFail()->review_status)->toBe('draft')
        ->and($profile->revisions()->where('revision', 2)->firstOrFail()->reviewed_by_id)->toBe($administrator->id);

    expect(fn () => $profile->revisions()->where('revision', 1)->firstOrFail()->update(['review_status' => 'approved']))
        ->toThrow(DomainException::class);
});

test('component specification approval requires exact variant evidence', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    $payload = [
        'product_id' => $product->id,
        'component_type' => 'processor',
        'manufacturer_part_number' => 'BF-CPU-9000',
        'specifications' => ['socket' => 'AM5'],
        'evidence' => [[
            'url' => 'https://manufacturer.example/specs',
            'title' => 'Product specifications',
            'section' => 'Specifications',
            'verified_at' => '2026-10-27',
            'variant' => 'BF-CPU-OTHER',
        ]],
    ];

    $this->actingAs($administrator)
        ->postJson(route('administration.component-specifications.store'), $payload)
        ->assertCreated();
    $profile = ProductComponentProfile::query()->where('product_id', $product->id)->firstOrFail();

    $this->postJson(route('administration.component-specifications.approve', $profile), [
        'edit_version' => $profile->edit_version,
    ])->assertUnprocessable()->assertJsonValidationErrors('review');

    expect($profile->refresh()->review_status)->toBe('draft');
});

test('published templates reuse reviewed catalog products in the customer builder', function () {
    $administrator = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();
    [$build] = createCartReadyBuild($customer);
    $template = app(PcBuildTemplateService::class)->save($administrator, [
        'name' => 'Student PC',
        'description' => 'An affordable general-purpose starting point.',
        'budget' => '45000.00',
        'is_active' => true,
        'components' => $build->items->map(fn ($item): array => [
            'key' => $item->slot_key,
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
            'configuration' => $item->configuration ?? [],
        ])->all(),
    ]);

    $this->get(route('pc-builder.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('templates', 1)
            ->where('templates.0.uuid', $template->uuid)
            ->where('templates.0.components.0.product_id', $build->items->first()->product_id));
});

test('published templates disappear when stock or reviewed compatibility changes', function () {
    $customer = User::factory()->customer()->create();
    [$build] = createCartReadyBuild($customer);
    $template = app(PcBuildTemplateService::class)->save(User::factory()->administrator()->create(), [
        'name' => 'Current stock template', 'is_active' => true,
        'components' => $build->items->map(fn ($item): array => [
            'key' => $item->slot_key, 'product_id' => $item->product_id,
            'quantity' => $item->quantity, 'configuration' => $item->configuration ?? [],
        ])->all(),
    ]);
    $product = $build->items->first()->product->load(['inventory', 'componentProfile.revisions']);
    $quantity = $product->inventory->quantity;
    $product->inventory->update(['quantity' => 0]);
    $this->get(route('pc-builder.index'))->assertInertia(fn (Assert $page) => $page->has('templates', 0));
    $product->inventory->update(['quantity' => $quantity]);
    $profile = $product->componentProfile;
    $revision = $profile->revisions->firstWhere('revision', $profile->current_revision);
    $newRevision = $profile->revisions()->create([
        'revision' => $profile->current_revision + 1, 'schema_version' => 1,
        'specifications' => [], 'evidence' => $revision->evidence, 'review_status' => 'approved',
    ]);
    $profile->update(['current_revision' => $newRevision->revision]);
    $this->get(route('pc-builder.index'))->assertInertia(fn (Assert $page) => $page->has('templates', 0));
    expect($template->refresh()->is_active)->toBeTrue();
});

test('templates cannot publish products with unreviewed specifications', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    ProductComponentProfile::factory()->for($product)->create(['component_type' => 'processor', 'review_status' => 'draft']);

    expect(fn () => app(PcBuildTemplateService::class)->save($administrator, [
        'name' => 'Unverified template',
        'is_active' => true,
        'components' => [['key' => 'processor-main', 'product_id' => $product->id, 'quantity' => 1]],
    ]))->toThrow(ValidationException::class);
});

test('PC build template management is limited to product managers', function () {
    $administrator = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();

    $this->actingAs($administrator)
        ->get(route('administration.pc-build-templates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Administration/PcBuildTemplates/Index')->has('templates')->has('products'));

    $this->actingAs($customer)->get(route('administration.pc-build-templates.index'))->assertForbidden();
});

test('admin template editing round trips component configuration and product identity', function () {
    $administrator = User::factory()->administrator()->create();
    $fan = createBuilderProduct('fan', ['size_mm' => 120, 'power_draw_w' => 3, 'header_type' => '4pin']);
    $case = createBuilderProduct('case', ['fan_mounting_positions' => ['front', 'rear']]);
    $configuration = ['mounting_position' => 'front', 'future_setting' => 'preserved'];
    $template = app(PcBuildTemplateService::class)->save($administrator, [
        'name' => 'Fan template', 'is_active' => false,
        'components' => [['key' => 'fan-1', 'product_id' => $fan->id, 'quantity' => 2, 'configuration' => $configuration]],
    ]);

    $this->actingAs($administrator)->get(route('administration.pc-build-templates.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('templates.0.components.0.configuration', $configuration)
            ->where('templates.0.components.0.product_name', $fan->name)
            ->where('templates.0.components.0.product_code', $fan->product_code)
            ->where('templates.0.components.0.quantity', 2)
            ->where('templates.0.is_active', false)
            ->where('products', fn ($products) => collect($products)->firstWhere('id', $case->id)['fan_mounting_positions'] === ['front', 'rear']));

    $this->putJson(route('administration.pc-build-templates.update', $template), [
        'name' => 'Updated fan template', 'is_active' => '0', 'edit_version' => (string) $template->edit_version,
        'components' => [['key' => 'fan-1', 'product_id' => (string) $fan->id, 'quantity' => '1', 'configuration' => $configuration]],
    ])->assertOk()->assertJsonPath('components.0.configuration', $configuration);

    expect($template->refresh()->items->first()->configuration)->toBe($configuration)
        ->and($template->items->first()->quantity)->toBe(1);

    $fan->update(['is_active' => false]);
    $this->get(route('administration.pc-build-templates.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('templates.0.components.0.product_name', $fan->name)
            ->where('templates.0.components.0.configuration', $configuration)
            ->where('products', fn ($products) => ! collect($products)->contains('id', $fan->id)));
});

test('PC Builder analytics counts saved and carted configurations after intentional actions', function () {
    $administrator = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();
    createCartReadyBuild($customer);

    $this->actingAs($administrator)
        ->get(route('administration.pc-build-analytics.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.saved_builds', 1)
            ->where('summary.added_to_cart', 1)
            ->where('summary.conversion_percent', 100)
            ->where('summary.abandoned_builds', 0)
            ->has('popular_components', 6));
});

test('compatibility endpoint uses reviewed product specifications from the server', function () {
    $processor = createBuilderProduct('processor', [
        'socket' => 'AM5',
        'generation' => 'Zen 4',
        'supported_chipsets' => ['B650'],
        'maximum_power_w' => 120,
        'integrated_graphics' => false,
    ]);
    $motherboard = createBuilderProduct('motherboard', [
        'socket' => 'AM4',
        'chipset' => 'B650',
        'supported_cpu_generations' => ['Zen 4'],
        'bios_version' => '1.0',
    ]);

    $this->postJson(route('pc-builder.validate'), [
        'components' => [
            ['key' => 'cpu', 'product_id' => $processor->id, 'quantity' => 1, 'specifications' => ['socket' => 'AM4']],
            ['key' => 'board', 'product_id' => $motherboard->id, 'quantity' => 1],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('status', 'incompatible')
        ->assertJsonFragment(['rule' => 'cpu_motherboard.socket', 'status' => 'incompatible']);
});

test('compatibility endpoint rejects products that are out of stock', function () {
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 0]);
    ProductComponentProfile::factory()->for($product)->create(['component_type' => 'processor']);

    $this->postJson(route('pc-builder.validate'), [
        'components' => [['key' => 'cpu', 'product_id' => $product->id, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('components');
});

test('customers can save, duplicate, share, and delete owned builds', function () {
    $customer = User::factory()->customer()->create();
    $processor = createBuilderProduct('processor', ['integrated_graphics' => true]);
    $payload = [
        'name' => 'Office build',
        'mode' => 'simple',
        'budget' => '50000.00',
        'components' => [['key' => 'processor-'.$processor->id, 'product_id' => $processor->id, 'quantity' => 1]],
    ];

    $this->actingAs($customer)
        ->postJson(route('pc-builds.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('name', 'Office build')
        ->assertJsonPath('items.0.product_id', $processor->id);

    $build = PcBuild::query()->firstOrFail();
    expect($build->items)->toHaveCount(1)
        ->and(PcBuildEvent::query()->where('pc_build_id', $build->id)->where('event_type', 'saved')->exists())->toBeTrue();

    $this->actingAs($customer)
        ->patchJson(route('pc-builds.update', $build->uuid), [...$payload, 'name' => 'Renamed build', 'edit_version' => 1])
        ->assertOk()
        ->assertJsonPath('name', 'Renamed build')
        ->assertJsonPath('edit_version', 2);

    $this->actingAs($customer)
        ->postJson(route('pc-builds.duplicate', $build->uuid))
        ->assertCreated()
        ->assertJsonPath('name', 'Renamed build (copy)');

    $this->actingAs($customer)
        ->postJson(route('pc-builds.share', $build->uuid))
        ->assertOk()
        ->assertJsonStructure(['url']);
    $build->refresh();
    $this->get(route('pc-builds.shared.show', ['token' => $build->share_token]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PcBuilder/Shared')
            ->where('build.name', 'Renamed build'));

    $this->actingAs($customer)->deleteJson(route('pc-builds.destroy', $build->uuid))->assertOk();
    expect(PcBuild::query()->where('uuid', $build->uuid)->exists())->toBeFalse();
});

test('customers cannot read another customers saved builds', function () {
    $owner = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $build = PcBuild::factory()->for($owner)->create();

    $this->actingAs($otherCustomer)
        ->patchJson(route('pc-builds.update', $build->uuid), [
            'name' => 'Stolen build',
            'mode' => 'simple',
            'edit_version' => 1,
            'components' => [],
        ])
        ->assertNotFound();
});

test('a complete compatible saved build enters the cart as one grouped configuration', function () {
    $customer = User::factory()->customer()->create();
    $specifications = [
        'processor' => ['socket' => 'AM5', 'generation' => 'Zen 4', 'supported_chipsets' => ['B650'], 'maximum_power_w' => 65, 'integrated_graphics' => true, 'included_cooler' => true, 'required_power_connectors' => []],
        'motherboard' => ['socket' => 'AM5', 'chipset' => 'B650', 'supported_cpu_generations' => ['Zen 4'], 'bios_version' => '1.0', 'display_outputs' => ['HDMI'], 'memory_generations' => ['DDR5'], 'dimm_slots' => 2, 'maximum_memory_gb' => 64, 'maximum_memory_speed_mts' => 6000, 'ecc_supported' => false, 'registered_memory_supported' => false, 'form_factor' => 'ATX', 'storage_slots' => [['interfaces' => ['NVMe'], 'form_factors' => ['M.2 2280'], 'maximum_length_mm' => 80], ['interfaces' => ['NVMe'], 'form_factors' => ['M.2 2280'], 'maximum_length_mm' => 80]], 'fan_headers' => [], 'power_draw_w' => 30],
        'memory' => ['memory_generation' => 'DDR5', 'capacity_gb' => 16, 'modules_per_package' => 2, 'speed_mts' => 5600, 'ecc' => false, 'registered' => false, 'power_draw_w' => 5],
        'storage' => ['interface' => 'NVMe', 'form_factor' => 'M.2 2280', 'length_mm' => 80, 'power_draw_w' => 5],
        'power_supply' => ['continuous_power_w' => 550, 'connectors' => [], 'form_factor' => 'ATX', 'length_mm' => 140, 'power_draw_w' => 0],
        'case' => ['motherboard_form_factors' => ['ATX'], 'supported_psu_form_factors' => ['ATX'], 'maximum_psu_length_mm' => 180, 'power_draw_w' => 0],
    ];
    $items = [];
    foreach ($specifications as $type => $facts) {
        $product = createBuilderProduct($type, $facts);
        $items[] = ['key' => $type.'-'.$product->id, 'product_id' => $product->id, 'quantity' => 1];
    }
    $this->actingAs($customer)->postJson(route('pc-builds.store'), [
        'name' => 'Compatible PC', 'mode' => 'advanced', 'components' => $items,
    ])->assertCreated();
    $build = PcBuild::query()->firstOrFail();

    $this->actingAs($customer)->postJson(route('pc-builds.cart.store', $build->uuid))
        ->assertCreated()
        ->assertJsonPath('name', 'Compatible PC')
        ->assertJsonPath('item_count', 6);

    expect(PcBuildCartGroup::query()->count())->toBe(1)
        ->and($customer->cart->items)->toHaveCount(6)
        ->and($customer->cart->items->every(fn ($item) => $item->pc_build_cart_group_id !== null))->toBeTrue();
});

test('repeated products in a build share a cart line while retaining component snapshots', function () {
    $customer = User::factory()->customer()->create();
    [$build, $originalGroup] = createCartReadyBuild($customer);
    addSecondStorageSlot($build);
    $storageProduct = $build->items()->where('component_type', 'storage')->firstOrFail()->product;
    $storageProduct->inventory()->update(['quantity' => 3]);

    $group = app(PcBuildToCartService::class)->add($customer, $build->uuid);

    expect($group->items)->toHaveCount(6)
        ->and($group->items->firstWhere('product_id', $storageProduct->id)->quantity)->toBe(2)
        ->and($group->configuration_snapshot['components'])->toHaveCount(7)
        ->and($originalGroup->items()->count())->toBe(6);
});

test('stock validation aggregates repeated products across build slots', function () {
    $customer = User::factory()->customer()->create();
    [$build] = createCartReadyBuild($customer);
    addSecondStorageSlot($build);
    $storageProduct = $build->items()->where('component_type', 'storage')->firstOrFail()->product;
    $storageProduct->inventory()->update(['quantity' => 2]);

    $this->actingAs($customer)->postJson(route('pc-builds.cart.store', $build->uuid))->assertUnprocessable();

    expect(PcBuildCartGroup::query()->count())->toBe(1)
        ->and($customer->cart->items)->toHaveCount(6);
});

test('checkout keeps each PC configuration grouped in immutable order snapshots', function () {
    $customer = User::factory()->customer()->create();
    [$build, $group, $cartItems] = createCartReadyBuild($customer);

    $order = app(OrderPlacementService::class)->execute($customer, [
        'cart_item_ids' => $cartItems->pluck('id')->all(),
        'recipient_name' => 'Build Customer',
        'contact_number' => '+639171234567',
        'fulfillment_method' => FulfillmentMethod::Pickup,
        'delivery_address' => null,
        'payment_method' => PaymentMethod::Cash,
        'payment_proof_path' => null,
    ]);

    expect($order->items)->toHaveCount(6)
        ->and($order->items->pluck('pc_build_group_uuid')->unique()->all())->toBe([$group->uuid])
        ->and($order->items->pluck('pc_build_name_at_time')->unique()->all())->toBe([$build->name])
        ->and($order->items->first()->pc_build_component_snapshot)->toHaveKeys(['component_type', 'specifications', 'ruleset_version', 'compatibility_status'])
        ->and($customer->refresh()->cart)->toBeNull();
});

test('checkout rejects a partial PC configuration before creating an order or deducting stock', function () {
    $customer = User::factory()->customer()->create();
    [, , $cartItems] = createCartReadyBuild($customer);
    $stocksBefore = Inventory::query()->orderBy('product_id')->pluck('quantity')->all();

    expect(fn () => app(OrderPlacementService::class)->execute($customer, [
        'cart_item_ids' => [$cartItems->first()->id],
        'recipient_name' => 'Build Customer',
        'contact_number' => '+639171234567',
        'fulfillment_method' => FulfillmentMethod::Pickup,
        'delivery_address' => null,
        'payment_method' => PaymentMethod::Cash,
        'payment_proof_path' => null,
    ]))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('orders', 0);
    expect(Inventory::query()->orderBy('product_id')->pluck('quantity')->all())->toBe($stocksBefore)
        ->and($customer->cart->items)->toHaveCount(6);
});

test('checkout preview requires all components in a selected PC configuration', function () {
    $customer = User::factory()->customer()->create();
    [, , $cartItems] = createCartReadyBuild($customer);
    $this->actingAs($customer)
        ->get(route('checkout.index', ['cart_item_ids' => [$cartItems->first()->id]]))
        ->assertRedirectToRoute('cart.index');
});

test('removing a grouped cart component removes the entire PC configuration', function () {
    $customer = User::factory()->customer()->create();
    [, , $cartItems] = createCartReadyBuild($customer);

    app(CartService::class)->remove($customer, $cartItems->first()->id);

    expect($customer->cart->items)->toBeEmpty()
        ->and(PcBuildCartGroup::query()->exists())->toBeFalse();
});

/** @param array<string, mixed> $specifications */
function createBuilderProduct(string $componentType, array $specifications): Product
{
    $product = Product::factory()->available()->create();
    $profile = ProductComponentProfile::factory()->for($product)->create([
        'component_type' => $componentType,
        'review_status' => 'approved',
    ]);
    $revision = ComponentSpecificationRevision::factory()->for($profile, 'profile')->create([
        'revision' => 1,
        'specifications' => $specifications,
        'review_status' => 'approved',
    ]);
    $profile->update(['current_revision' => $revision->revision]);

    return $product;
}

/** @return array{PcBuild, PcBuildCartGroup, Collection<int, CartItem>} */
function createCartReadyBuild(User $customer): array
{
    $specifications = [
        'processor' => ['socket' => 'AM5', 'generation' => 'Zen 4', 'supported_chipsets' => ['B650'], 'maximum_power_w' => 65, 'integrated_graphics' => true, 'included_cooler' => true, 'required_power_connectors' => []],
        'motherboard' => ['socket' => 'AM5', 'chipset' => 'B650', 'supported_cpu_generations' => ['Zen 4'], 'bios_version' => '1.0', 'display_outputs' => ['HDMI'], 'memory_generations' => ['DDR5'], 'dimm_slots' => 2, 'maximum_memory_gb' => 64, 'maximum_memory_speed_mts' => 6000, 'ecc_supported' => false, 'registered_memory_supported' => false, 'form_factor' => 'ATX', 'storage_slots' => [['interfaces' => ['NVMe'], 'form_factors' => ['M.2 2280'], 'maximum_length_mm' => 80], ['interfaces' => ['NVMe'], 'form_factors' => ['M.2 2280'], 'maximum_length_mm' => 80]], 'fan_headers' => [], 'power_draw_w' => 30],
        'memory' => ['memory_generation' => 'DDR5', 'capacity_gb' => 16, 'modules_per_package' => 2, 'speed_mts' => 5600, 'ecc' => false, 'registered' => false, 'power_draw_w' => 5],
        'storage' => ['interface' => 'NVMe', 'form_factor' => 'M.2 2280', 'length_mm' => 80, 'power_draw_w' => 5],
        'power_supply' => ['continuous_power_w' => 550, 'connectors' => [], 'form_factor' => 'ATX', 'length_mm' => 140, 'power_draw_w' => 0],
        'case' => ['motherboard_form_factors' => ['ATX'], 'supported_psu_form_factors' => ['ATX'], 'maximum_psu_length_mm' => 180, 'power_draw_w' => 0],
    ];
    $components = [];
    foreach ($specifications as $type => $facts) {
        $product = createBuilderProduct($type, $facts);
        $components[] = ['key' => $type.'-'.$product->id, 'product_id' => $product->id, 'quantity' => 1];
    }
    $build = app(SavedPcBuildService::class)->create($customer, [
        'name' => 'Order test build', 'mode' => 'simple', 'components' => $components,
    ]);
    $group = app(PcBuildToCartService::class)->add($customer, $build->uuid);

    return [$build, $group, $customer->cart->items()->orderBy('id')->get()];
}

function addSecondStorageSlot(PcBuild $build): void
{
    $storageItem = $build->items()->where('component_type', 'storage')->firstOrFail();
    $build->items()->create([
        'product_id' => $storageItem->product_id,
        'component_specification_revision_id' => $storageItem->component_specification_revision_id,
        'component_type' => 'storage',
        'slot_key' => 'storage-secondary',
        'quantity' => 1,
        'position' => 6,
    ]);
}
