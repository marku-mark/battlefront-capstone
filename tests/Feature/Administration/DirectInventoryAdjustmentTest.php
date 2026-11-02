<?php

use App\Actions\Inventory\ApplyInventoryAdjustment;
use App\Events\OrderNotificationOccurred;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('owner and manager apply stock changes with linked audit and movement history', function (string $role, string $operation, int $target) {
    $this->freezeTime();
    $actor = directInventoryActor($role);
    $inventory = Inventory::factory()->create(['quantity' => 10, 'reorder_level' => 3, 'quarantined_quantity' => 2]);
    Event::fake([OrderNotificationOccurred::class]);

    $response = $this->actingAs($actor)->{ $operation === 'physical_count' ? 'post' : 'patch' }(
        route($operation === 'physical_count' ? 'administration.inventory.count' : 'administration.inventory.update', $inventory),
        directInventoryPayload(['quantity' => $target]),
    );

    $response->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', $operation === 'physical_count' ? 'Physical count adjustment applied.' : 'Stock adjusted successfully.');
    $this->assertDatabaseHas('inventories', ['id' => $inventory->id, 'quantity' => $target, 'reorder_level' => 3, 'quarantined_quantity' => 2]);
    $audit = DB::table('audit_logs')->sole();
    expect($audit->action)->toBe('inventory.adjusted')
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->subject_type)->toBe('inventory')
        ->and($audit->subject_id)->toBe($inventory->id)
        ->and($audit->reason)->toBe(($operation === 'physical_count' ? 'Physical sellable stock count: ' : '').'Shelf count checked today')
        ->and(json_decode($audit->changes, true))->toBe([
            'previous_quantity' => 10, 'new_quantity' => $target, 'quantity_difference' => $target - 10,
            'reason_code' => 'count_error', 'operation' => $operation,
        ])
        ->and($audit->created_at)->toBe(now()->toDateTimeString());
    $this->assertDatabaseHas('inventory_movements', [
        'product_id' => $inventory->product_id, 'actor_id' => $actor->id, 'kind' => 'direct_adjustment',
        'reference_type' => 'audit_log', 'reference_id' => $audit->id,
        'sellable_before' => 10, 'sellable_delta' => $target - 10, 'sellable_after' => $target,
        'quarantined_before' => 2, 'quarantined_delta' => 0, 'quarantined_after' => 2,
        'created_at' => now()->toDateTimeString(),
    ]);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseCount('inventory_adjustment_requests', 0);
    Event::assertNotDispatched(OrderNotificationOccurred::class);
})->with(['owner', 'manager'])->with(['stock_adjustment', 'physical_count'])->with([14, 0]);

test('owner and manager initialize opening stock immediately including zero stock', function (string $role, int $quantity) {
    $actor = directInventoryActor($role);
    $product = Product::factory()->create();
    Event::fake([OrderNotificationOccurred::class]);

    $this->actingAs($actor)->post(route('administration.products.inventory.store', $product), directInventoryPayload([
        'quantity' => $quantity, 'reason_code' => 'receiving_error', 'reason' => null,
    ]))->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Opening stock initialized.');

    $this->assertDatabaseHas('inventories', ['product_id' => $product->id, 'quantity' => $quantity, 'reorder_level' => 3, 'quarantined_quantity' => 0]);
    $audit = DB::table('audit_logs')->sole();
    expect($audit->reason)->toBe('Initial receipt')
        ->and(json_decode($audit->changes, true)['operation'])->toBe('opening_stock');
    $this->assertDatabaseHas('inventory_movements', [
        'product_id' => $product->id, 'actor_id' => $actor->id, 'kind' => 'direct_adjustment',
        'reference_type' => 'audit_log', 'reference_id' => $audit->id,
        'sellable_before' => 0, 'sellable_delta' => $quantity, 'sellable_after' => $quantity,
    ]);
    $this->assertDatabaseCount('inventory_adjustment_requests', 0);
    Event::assertNotDispatched(OrderNotificationOccurred::class);
})->with(['owner', 'manager'])->with([7, 0]);

test('invalid direct changes fail without inventory or history side effects', function (array $changes, string $field) {
    $actor = directInventoryActor('manager');
    $inventory = Inventory::factory()->create(['quantity' => 10, 'reorder_level' => 3]);

    $this->actingAs($actor)->patch(route('administration.inventory.update', $inventory), directInventoryPayload($changes))
        ->assertSessionHasErrors($field);

    expect($inventory->fresh()->quantity)->toBe(10);
    $this->assertDatabaseCount('inventory_adjustment_requests', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    'negative' => [['quantity' => -1], 'quantity'],
    'fractional' => [['quantity' => 1.5], 'quantity'],
    'too large' => [['quantity' => 4294967296], 'quantity'],
    'movement overflow' => [['quantity' => 4294967295], 'quantity'],
    'no change' => [['quantity' => 10], 'quantity'],
    'stale' => [['observed_quantity' => 9], 'quantity'],
    'missing snapshot' => [['observed_quantity' => null], 'observed_quantity'],
    'not confirmed' => [['stock_adjustment_confirmed' => false], 'stock_adjustment_confirmed'],
    'missing confirmation' => [['stock_adjustment_confirmed' => null], 'stock_adjustment_confirmed'],
    'damage' => [['reason_code' => 'damage'], 'reason_code'],
    'invalid reason' => [['reason_code' => 'invented'], 'reason_code'],
    'missing reason' => [['reason_code' => null], 'reason_code'],
    'found decrease' => [['reason_code' => 'found_stock', 'quantity' => 8], 'quantity'],
    'loss increase' => [['reason_code' => 'theft_loss'], 'quantity'],
    'loss notes' => [['reason_code' => 'theft_loss', 'quantity' => 8, 'reason' => '   '], 'reason'],
    'loss short notes' => [['reason_code' => 'theft_loss', 'quantity' => 8, 'reason' => 'lost'], 'reason'],
    'reorder change' => [['reorder_level' => 4], 'reorder_level'],
]);

test('direct physical counts enforce stale validation and explanatory loss notes', function (array $changes, string $field) {
    $actor = directInventoryActor('owner');
    $inventory = Inventory::factory()->create(['quantity' => 10, 'reorder_level' => 3]);

    $this->actingAs($actor)->post(route('administration.inventory.count', $inventory), directInventoryPayload($changes))
        ->assertSessionHasErrors($field);

    expect($inventory->fresh()->quantity)->toBe(10);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    'stale count' => [['observed_quantity' => 8], 'quantity'],
    'loss notes' => [['quantity' => 8, 'reason_code' => 'theft_loss', 'reason' => null], 'reason'],
    'damage count' => [['quantity' => 8, 'reason_code' => 'damage'], 'reason_code'],
    'unconfirmed' => [['stock_adjustment_confirmed' => false], 'stock_adjustment_confirmed'],
]);

test('direct theft adjustments retain the explanatory notes', function () {
    $actor = directInventoryActor('manager');
    $inventory = Inventory::factory()->create(['quantity' => 10, 'reorder_level' => 3]);
    $this->actingAs($actor)->patch(route('administration.inventory.update', $inventory), directInventoryPayload([
        'quantity' => 8, 'reason_code' => 'theft_loss', 'reason' => 'Two units missing after shelf inspection',
    ]))->assertSessionHasNoErrors();
    expect($inventory->fresh()->quantity)->toBe(8);
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $actor->id, 'reason' => 'Two units missing after shelf inspection']);
});

test('staff confirmation fields cannot bypass approval and legacy IT assignments cannot submit stock changes', function (string $role, string $operation) {
    $actor = directInventoryActor($role);
    $product = $operation === 'opening' ? Product::factory()->create() : Product::factory()->available()->create();
    $inventory = $product->inventory;
    $before = $inventory?->quantity ?? 0;
    $route = match ($operation) {
        'opening' => route('administration.products.inventory.store', $product),
        'count' => route('administration.inventory.count', $inventory),
        default => route('administration.inventory.update', $inventory),
    };
    $response = $this->actingAs($actor)->{ $operation === 'update' ? 'patch' : 'post' }($route, directInventoryPayload([
        'quantity' => $before + 2, 'observed_quantity' => $before, 'reorder_level' => $inventory?->reorder_level ?? 3,
        'direct' => true, 'approved' => true,
    ]));

    if ($role === 'it') {
        $response->assertForbidden();
        $this->assertDatabaseCount('inventory_adjustment_requests', 0);
    } else {
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('inventory_adjustment_requests', ['product_id' => $product->id, 'requester_id' => $actor->id, 'status' => 'pending', 'target_quantity' => $before + 2]);
    }
    expect($product->fresh()->inventory?->quantity ?? 0)->toBe($before);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with(['staff', 'it'])->with(['opening', 'update', 'count']);

test('the direct action independently rejects staff and legacy IT assignments', function (string $role) {
    $actor = directInventoryActor($role);
    $inventory = Inventory::factory()->create(['quantity' => 10]);
    expect(fn () => app(ApplyInventoryAdjustment::class)->execute($inventory->product, $actor, 12, 'count_error', expectedQuantity: 10))
        ->toThrow(HttpException::class);
    expect($inventory->fresh()->quantity)->toBe(10);
    $this->assertDatabaseCount('audit_logs', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with(['staff', 'it']);

test('direct adjustment permissions respect revoked permissions and inactive actors', function (string $role, string $restriction) {
    $actor = directInventoryActor($role);
    if ($restriction === 'inactive') {
        $actor->forceFill(['is_active' => false])->save();
    } else {
        DB::table('administration_role_permissions')->where('administration_role_id', $actor->administration_role_id)
            ->where('administration_permission_id', DB::table('administration_permissions')->where('code', $restriction)->value('id'))->delete();
    }
    $inventory = Inventory::factory()->create(['quantity' => 10, 'reorder_level' => 3]);
    $this->actingAs($actor)->patch(route('administration.inventory.update', $inventory), directInventoryPayload())->assertForbidden();
    expect($inventory->fresh()->quantity)->toBe(10);
    $this->assertDatabaseCount('inventory_adjustment_requests', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with(['owner', 'manager'])->with(['inventory.approve', 'inventory.request', 'inactive']);

test('direct changes preserve pending requests and stale approval rejects them without rewriting history', function () {
    $staff = directInventoryActor('staff');
    $manager = directInventoryActor('manager');
    $inventory = Inventory::factory()->create(['quantity' => 10, 'reorder_level' => 3]);
    $this->actingAs($staff)->patch(route('administration.inventory.update', $inventory), directInventoryPayload())->assertSessionHasNoErrors();
    $pending = DB::table('inventory_adjustment_requests')->sole();
    $requestAudit = DB::table('audit_logs')->sole();

    $this->actingAs($manager)->patch(route('administration.inventory.update', $inventory), directInventoryPayload(['quantity' => 15]))->assertSessionHasNoErrors();
    expect(DB::table('inventory_adjustment_requests')->sole())->toEqual($pending)
        ->and(DB::table('audit_logs')->where('id', $requestAudit->id)->first())->toEqual($requestAudit);
    $this->post(route('administration.inventory-adjustments.decide', $pending->id), ['decision' => 'approve', 'reason' => 'Checked count on site'])
        ->assertSessionHasErrors('decision');
    expect(DB::table('inventory_adjustment_requests')->sole())->toEqual($pending)
        ->and($inventory->fresh()->quantity)->toBe(15);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseCount('audit_logs', 2);
});

test('opening stock cannot be initialized twice even through the direct action', function () {
    $actor = directInventoryActor('manager');
    $inventory = Inventory::factory()->create(['quantity' => 10, 'reorder_level' => 3]);
    $this->actingAs($actor)->post(route('administration.products.inventory.store', $inventory->product), directInventoryPayload())
        ->assertSessionHasErrors('quantity');
    expect(fn () => app(ApplyInventoryAdjustment::class)->execute($inventory->product, $actor, 12, 'receiving_error', initialReorderLevel: 4))
        ->toThrow(ValidationException::class, 'Inventory is already initialized.');
    expect($inventory->fresh()->quantity)->toBe(10);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('stock forms receive role accurate direct and request capabilities', function (string $role, bool $direct, bool $request) {
    $this->actingAs(directInventoryActor($role))->get(route('administration.inventory.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.can.adjustInventory', $direct)
            ->where('auth.can.requestInventoryAdjustment', $request));
})->with([['owner', true, false], ['manager', true, false], ['staff', false, true]]);

test('reorder updates retain their separate authorization audit and stock neutral behavior', function (string $role, bool $allowed) {
    $actor = directInventoryActor($role);
    $inventory = Inventory::factory()->create(['quantity' => 10, 'reorder_level' => 3]);
    $response = $this->actingAs($actor)->patch(route('administration.inventory.reorder-level.update', $inventory), [
        'reorder_level' => 5, 'reason' => 'Supplier lead time changed',
    ]);
    if ($allowed) {
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $actor->id, 'action' => 'inventory.reorder_level.updated', 'subject_id' => $inventory->id]);
    } else {
        $response->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);
    }
    expect($inventory->fresh()->reorder_level)->toBe($allowed ? 5 : 3)
        ->and($inventory->quantity)->toBe(10);
    $this->assertDatabaseCount('inventory_adjustment_requests', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with([['owner', true], ['manager', true], ['staff', false], ['it', false]]);

function directInventoryActor(string $role): User
{
    return User::factory()->administrator()->create([
        'administration_role_id' => ($role === 'it' ? legacyItRoleId() : DB::table('administration_roles')->where('code', $role)->value('id')),
    ]);
}

/** @param array<string, mixed> $changes
 * @return array<string, mixed>
 */
function directInventoryPayload(array $changes = []): array
{
    return [...[
        'quantity' => 12, 'observed_quantity' => 10, 'reorder_level' => 3,
        'reason_code' => 'count_error', 'reason' => 'Shelf count checked today', 'stock_adjustment_confirmed' => true,
    ], ...$changes];
}
