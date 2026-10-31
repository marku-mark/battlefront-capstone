<?php

use App\Actions\Inventory\RequestInventoryAdjustment;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('stock request entry points enforce reason direction and damage routing without side effects', function (string $kind, string $code, int $quantity, string $field, string $message) {
    $actor = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $case = operationalNotesRequest($kind, $actor);
    $beforeRequests = DB::table('inventory_adjustment_requests')->count();
    $beforeAudit = DB::table('audit_logs')->count();
    $beforeInventory = $case['product']->inventory?->getAttributes();

    $this->actingAs($actor)->{$case['method']}($case['url'], [
        ...$case['payload'], 'reason_code' => $code, 'quantity' => $quantity, 'reason' => 'Checked by staff on site',
    ])->assertSessionHasErrors([$field => $message]);

    $this->assertDatabaseCount('inventory_adjustment_requests', $beforeRequests);
    $this->assertDatabaseCount('audit_logs', $beforeAudit);
    $this->assertDatabaseCount('inventory_movements', 0);
    expect($case['product']->fresh()->inventory?->getAttributes())->toBe($beforeInventory);
})->with(['stock', 'count', 'replacement'])->with([
    'found stock decrease' => ['found_stock', 8, 'quantity', 'Found stock must increase the quantity.'],
    'found stock unchanged' => ['found_stock', 10, 'quantity', 'Found stock must increase the quantity.'],
    'loss increase' => ['theft_loss', 12, 'quantity', 'Theft or loss must decrease the quantity.'],
    'loss unchanged' => ['theft_loss', 10, 'quantity', 'Theft or loss must decrease the quantity.'],
    'damage' => ['damage', 8, 'reason_code', 'Use Damage Reports for damaged stock so it can be quarantined and inspected.'],
]);

test('stock requests accept blank notes and audit the selected reason', function (string $kind, string $code, string $label, array $notes) {
    $actor = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $case = operationalNotesRequest($kind, $actor);
    $beforeStock = $case['product']->fresh()->inventory?->getAttributes();

    $this->actingAs($actor)->{$case['method']}($case['url'], [
        ...$case['payload'], 'quantity' => $code === 'theft_loss' ? 8 : 12, 'reason_code' => $code, ...$notes,
    ])->assertSessionHasNoErrors();

    $record = DB::table('inventory_adjustment_requests')->orderByDesc('id')->first();
    $explanation = ($kind === 'count' ? 'Physical sellable stock count: ' : '').$label;
    $this->assertDatabaseHas('inventory_adjustment_requests', ['id' => $record->id, 'status' => 'pending', 'reason_code' => $code, 'reason' => $explanation]);
    $this->assertDatabaseHas('audit_logs', ['subject_id' => $record->id, 'action' => 'inventory.adjustment.requested', 'reason' => $explanation]);
    expect($case['product']->fresh()->inventory?->getAttributes())->toBe($beforeStock);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with([
    ['stock', 'theft_loss', 'Theft or loss'],
    ['count', 'theft_loss', 'Theft or loss'],
    ['replacement', 'theft_loss', 'Theft or loss'],
    ['stock', 'other', 'Other'],
    ['count', 'other', 'Other'],
    ['replacement', 'other', 'Other'],
    ['opening', 'other', 'Other'],
    ['opening replacement', 'other', 'Other'],
])->with([
    'omitted' => [[]], 'null' => [['reason' => null]], 'empty' => [['reason' => '']], 'whitespace' => [['reason' => '   ']],
]);

test('opening stock cannot use loss or damage reasons', function (string $kind, string $code, string $field) {
    $actor = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $case = operationalNotesRequest($kind, $actor);
    $beforeRequests = DB::table('inventory_adjustment_requests')->count();

    $this->actingAs($actor)->{$case['method']}($case['url'], [
        ...$case['payload'], 'reason_code' => $code, 'reason' => 'Checked by staff on site',
    ])->assertSessionHasErrors($field);

    $this->assertDatabaseCount('inventory_adjustment_requests', $beforeRequests);
    $this->assertDatabaseMissing('inventories', ['product_id' => $case['product']->id]);
})->with(['opening', 'opening replacement'])->with([
    ['theft_loss', 'quantity'], ['damage', 'reason_code'],
]);

test('shared stock request action rejects reason direction violations', function () {
    $actor = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $product = Product::factory()->available()->create();

    expect(fn () => app(RequestInventoryAdjustment::class)->execute(
        $product, $actor, $product->inventory->quantity - 1, 'Shelf checked on site', reasonCode: 'found_stock',
    ))->toThrow(ValidationException::class, 'Found stock must increase the quantity.');

    $this->assertDatabaseCount('inventory_adjustment_requests', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
});

/**
 * @return array{method: string, url: string, payload: array<string, mixed>, table: string, subject: string, action: string, explanation: string, product: Product}
 */
function operationalNotesRequest(string $kind, User $actor): array
{
    $opening = in_array($kind, ['opening', 'opening replacement'], true);
    $product = $opening ? Product::factory()->create() : Product::factory()->available()->create();
    if (! $opening) {
        $product->inventory->update(['quantity' => 10, 'quarantined_quantity' => 0, 'reorder_level' => 2]);
    }
    $case = [
        'method' => 'post', 'payload' => ['quantity' => 12, 'reorder_level' => 2, 'reason_code' => 'count_error'],
        'table' => 'inventory_adjustment_requests', 'subject' => 'inventory_adjustment_request',
        'action' => 'inventory.adjustment.requested', 'explanation' => 'Count correction', 'product' => $product,
    ];
    if ($kind === 'opening') {
        $case['url'] = route('administration.products.inventory.store', $product);
        $case['payload']['reason_code'] = 'receiving_error';
        $case['explanation'] = 'Initial receipt';
    } elseif ($kind === 'stock') {
        $case['method'] = 'patch';
        $case['url'] = route('administration.inventory.update', $product->inventory);
    } elseif ($kind === 'count') {
        $case['url'] = route('administration.inventory.count', $product->inventory);
        $case['payload']['observed_quantity'] = 10;
        $case['explanation'] = 'Physical sellable stock count: Count correction';
    } elseif (in_array($kind, ['replacement', 'opening replacement'], true)) {
        $previousId = DB::table('inventory_adjustment_requests')->insertGetId([
            'product_id' => $product->id, 'requester_id' => $actor->id,
            'observed_quantity' => $opening ? 0 : 10, 'target_quantity' => 11,
            'initial_reorder_level' => $opening ? 2 : null,
            'status' => 'withdrawn', 'reason_code' => 'other', 'reason' => 'Original explanation retained',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $case['url'] = route('administration.inventory-adjustments.replace', $previousId);
        $case['payload']['reason_code'] = 'receiving_error';
        $case['explanation'] = $opening ? 'Initial receipt' : 'Receiving error';
    } elseif ($kind === 'damage') {
        $case['url'] = route('administration.damage-reports.store');
        $case['payload'] = ['product_id' => $product->id, 'quantity' => 1, 'reason_code' => 'handling_damage'];
        $case['table'] = 'damage_reports';
        $case['subject'] = 'damage_report';
        $case['action'] = 'damage.reported';
        $case['explanation'] = 'Warehouse handling';
    } elseif ($kind === 'supplier') {
        $supplierId = DB::table('suppliers')->insertGetId(['name' => 'Notes supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $case['url'] = route('administration.supplier-returns.store');
        $case['payload'] = ['supplier_id' => $supplierId, 'product_id' => $product->id, 'quantity' => 1, 'reason_code' => 'wrong_item'];
        $case['table'] = 'supplier_returns';
        $case['subject'] = 'supplier_return';
        $case['action'] = 'supplier_return.requested';
        $case['explanation'] = 'Wrong item';
    } elseif ($kind === 'customer') {
        Storage::fake('local');
        $actor->administration_role_id = DB::table('administration_roles')->where('code', 'staff')->value('id');
        $actor->save();
        $order = Order::factory()->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified, 'product_subtotal' => '200.00', 'total_amount' => '200.00']);
        $item = OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2, 'price_at_time' => '100.00']);
        $order->sale()->create(['amount' => '200.00', 'sale_date' => today()]);
        $case['url'] = route('administration.customer-returns.store');
        $case['payload'] = ['order_item_id' => $item->id, 'quantity' => 1, 'condition' => 'opened', 'reason_code' => 'defective', 'files' => [UploadedFile::fake()->image('receipt.jpg')]];
        $case['table'] = 'customer_returns';
        $case['subject'] = 'customer_return';
        $case['action'] = 'customer_return.received';
        $case['explanation'] = 'Defective';
    }

    return $case;
}

dataset('operational request kinds', ['stock', 'opening', 'count', 'replacement', 'opening replacement', 'damage', 'supplier', 'customer']);

test('predefined request reasons accept absent or blank notes and retain meaningful audit explanations', function (string $kind, array $notes) {
    $actor = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $case = operationalNotesRequest($kind, $actor);

    $this->actingAs($actor)->{$case['method']}($case['url'], [...$case['payload'], ...$notes])->assertSessionHasNoErrors();

    $record = DB::table($case['table'])->orderByDesc('id')->first();
    expect($record->reason)->toBe($case['explanation']);
    expect($record->reason_code)->toBe($case['payload']['reason_code']);
    expect($record->status)->toBe('pending');
    $this->assertDatabaseHas('audit_logs', [
        'subject_type' => $case['subject'], 'subject_id' => $record->id,
        'action' => $case['action'], 'reason' => $case['explanation'],
    ]);
    if (in_array($kind, ['replacement', 'opening replacement'], true)) {
        expect($record->replaces_request_id)->not->toBeNull();
        $this->assertDatabaseHas($case['table'], ['id' => $record->replaces_request_id, 'status' => 'withdrawn', 'reason' => 'Original explanation retained']);
    }
    if (in_array($kind, ['opening', 'opening replacement'], true)) {
        $this->assertDatabaseMissing('inventories', ['product_id' => $case['product']->id]);
    } else {
        $inventory = $case['product']->inventory->fresh();
        expect($inventory->quantity)->toBe(in_array($kind, ['damage', 'supplier'], true) ? 9 : 10);
        expect($inventory->quarantined_quantity)->toBe(in_array($kind, ['damage', 'supplier', 'customer'], true) ? 1 : 0);
    }
})->with('operational request kinds')->with([
    'omitted' => [[]], 'null' => [['reason' => null]], 'empty' => [['reason' => '']], 'whitespace' => [['reason' => '   ']],
]);

test('authorized operational roles can submit predefined reasons alone and Other reasons with notes', function (string $kind, string $role, bool $other) {
    $actor = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', $role)->value('id'),
    ]);
    $case = operationalNotesRequest($kind, $actor);
    $payload = $case['payload'];
    $direct = $role !== 'staff' && in_array($kind, ['stock', 'opening', 'count'], true);
    if ($direct) {
        $payload['stock_adjustment_confirmed'] = true;
        $payload['observed_quantity'] = $case['product']->inventory?->quantity ?? 0;
    }
    if ($other) {
        $payload['reason_code'] = 'other';
        $payload['reason'] = 'Shelf checked by the requester';
    }

    $this->actingAs($actor)->{$case['method']}($case['url'], $payload)->assertSessionHasNoErrors();

    $explanation = $other
        ? ($kind === 'count' ? 'Physical sellable stock count: ' : '').'Shelf checked by the requester'
        : $case['explanation'];
    if ($direct) {
        $this->assertDatabaseCount('inventory_adjustment_requests', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.adjusted', 'reason' => $explanation]);
        expect($case['product']->fresh()->inventory->quantity)->toBe(12);

        return;
    }
    $record = DB::table($case['table'])->orderByDesc('id')->first();
    expect($record->reason)->toBe($explanation);
    expect($record->reason_code)->toBe($payload['reason_code']);
    $this->assertDatabaseHas('audit_logs', ['action' => $case['action'], 'subject_id' => $record->id, 'reason' => $explanation]);
})->with('operational request kinds')->with(['staff', 'manager', 'owner'])->with([false, true]);

test('supplied request notes retain the existing inclusive length limits', function (string $kind, int $length, bool $other) {
    $actor = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $case = operationalNotesRequest($kind, $actor);
    $notes = str_repeat('x', $length);
    $payload = [...$case['payload'], 'reason' => $notes];
    if ($other) {
        $payload['reason_code'] = 'other';
    }

    $this->actingAs($actor)->{$case['method']}($case['url'], $payload)->assertSessionHasNoErrors();

    $explanation = ($kind === 'count' ? 'Physical sellable stock count: ' : '').$notes;
    $record = DB::table($case['table'])->orderByDesc('id')->first();
    expect($record->reason)->toBe($explanation);
    $this->assertDatabaseHas('audit_logs', ['action' => $case['action'], 'subject_id' => $record->id, 'reason' => $explanation]);
})->with('operational request kinds')->with([5, 2000])->with([false, true]);

test('invalid request reasons and notes cannot persist requests or stock changes', function (string $kind, array $override, string $field) {
    $actor = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $case = operationalNotesRequest($kind, $actor);
    $beforeRequests = DB::table($case['table'])->count();
    $beforeAudit = DB::table('audit_logs')->count();
    $beforeMovements = DB::table('inventory_movements')->count();
    $beforeStock = DB::table('inventories')->where('product_id', $case['product']->id)->first();

    $response = $this->actingAs($actor)->{$case['method']}($case['url'], [...$case['payload'], ...$override]);
    if (in_array($kind, ['stock', 'count', 'opening', 'replacement', 'opening replacement'], true)
        && ($override['reason_code'] ?? null) === 'other' && empty(trim($override['reason'] ?? ''))) {
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas($case['table'], ['product_id' => $case['product']->id, 'reason_code' => 'other', 'reason' => $kind === 'count' ? 'Physical sellable stock count: Other' : 'Other']);

        return;
    }
    $response->assertSessionHasErrors($field);

    $this->assertDatabaseCount($case['table'], $beforeRequests);
    $this->assertDatabaseCount('audit_logs', $beforeAudit);
    $this->assertDatabaseCount('inventory_movements', $beforeMovements);
    expect(DB::table('inventories')->where('product_id', $case['product']->id)->first())->toEqual($beforeStock);
})->with('operational request kinds')->with([
    'missing code' => [['reason_code' => null, 'reason' => 'Explanation supplied'], 'reason_code'],
    'unknown code' => [['reason_code' => 'unknown', 'reason' => 'Explanation supplied'], 'reason_code'],
    'Other omitted notes' => [['reason_code' => 'other'], 'reason'],
    'Other null notes' => [['reason_code' => 'other', 'reason' => null], 'reason'],
    'Other empty notes' => [['reason_code' => 'other', 'reason' => ''], 'reason'],
    'Other whitespace notes' => [['reason_code' => 'other', 'reason' => '   '], 'reason'],
    'Other short notes' => [['reason_code' => 'other', 'reason' => 'four'], 'reason'],
    'Other excessive notes' => [['reason_code' => 'other', 'reason' => str_repeat('x', 2001)], 'reason'],
    'optional short notes' => [['reason' => 'four'], 'reason'],
    'optional excessive notes' => [['reason' => str_repeat('x', 2001)], 'reason'],
]);

test('accountability decisions still require explanations even with a predefined reason code', function (string $route, array $payload, string $field) {
    $actor = User::factory()->administrator()->create();
    $inventory = Product::factory()->available()->create()->inventory;
    $beforeStock = $inventory->getAttributes();
    $beforeAudit = DB::table('audit_logs')->count();

    $this->actingAs($actor)->post(route($route, 1), [...$payload, 'reason_code' => 'count_error'])->assertSessionHasErrors($field);

    expect($inventory->fresh()->getAttributes())->toBe($beforeStock);
    $this->assertDatabaseCount('audit_logs', $beforeAudit);
    $this->assertDatabaseCount('customer_refunds', 0);
    $this->assertDatabaseCount('customer_refund_payment_reviews', 0);
})->with([
    'adjustment approval' => ['administration.inventory-adjustments.decide', ['decision' => 'approve'], 'reason'],
    'adjustment rejection' => ['administration.inventory-adjustments.decide', ['decision' => 'reject'], 'reason'],
    'adjustment withdrawal' => ['administration.inventory-adjustments.withdraw', [], 'reason'],
    'damage write-off' => ['administration.damage-reports.decide', ['disposition' => 'write_off'], 'reason'],
    'supplier rejection' => ['administration.supplier-returns.decide', ['decision' => 'reject'], 'reason'],
    'supplier follow-up write-off' => ['administration.supplier-returns.follow-up', ['decision' => 'write_off'], 'reason'],
    'supplier resolution' => ['administration.supplier-returns.resolve', ['outcome' => 'replacement', 'external_reference' => 'SUP-REPLACEMENT'], 'notes'],
    'customer rejection' => ['administration.customer-returns.decide', ['decision' => 'reject'], 'reason'],
    'customer write-off' => ['administration.customer-returns.decide', ['decision' => 'accept', 'disposition' => 'write_off', 'approved_amount' => '0.00'], 'reason'],
    'refund dispute' => ['administration.customer-refund-payments.review', ['decision' => 'disputed'], 'reason'],
    'refund void' => ['administration.customer-refund-payments.review', ['decision' => 'voided'], 'reason'],
]);
