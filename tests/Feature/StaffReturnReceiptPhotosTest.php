<?php

use App\Actions\Inventory\HandleCustomerReturnRequest;
use App\Models\Branch;
use App\Models\CustomerReturnRequest;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\AdministrationEvidenceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function photoReceiptEmployee(string $role = 'staff'): User
{
    return User::factory()->administrator()->create(['administration_role_id' => ($role === 'it' ? legacyItRoleId() : DB::table('administration_roles')->where('code', $role)->value('id'))]);
}

/** @return array{Order, OrderItem, Inventory, CustomerReturnRequest} */
function photoReceiptPurchase(): array
{
    Storage::fake('local');
    $order = Order::factory()->create(['status' => 'completed', 'payment_status' => 'verified']);
    $inventory = Inventory::factory()->create(['quantity' => 3, 'quarantined_quantity' => 0]);
    $item = OrderItem::factory()->for($order)->for($inventory->product)->create(['quantity' => 2, 'price_at_time' => '100.00']);
    $order->sale()->create(['amount' => '200.00', 'sale_date' => today()]);
    $request = app(HandleCustomerReturnRequest::class)->submit($order->user, $order->id, ['order_item_id' => $item->id, 'quantity' => 1, 'reason_code' => 'defective']);

    return [$order, $item, $inventory, $request];
}

test('Staff receipt rejects missing invalid oversized and excessive product photos without side effects', function (string $kind) {
    [$order, $item, $stock, $request] = photoReceiptPurchase();
    $files = match ($kind) {
        'missing' => [],
        'pdf' => [UploadedFile::fake()->create('document.pdf', 10, 'application/pdf')],
        'fake image' => [UploadedFile::fake()->create('pretend.jpg', 10, 'text/plain')],
        'oversized' => [UploadedFile::fake()->image('large.jpg')->size(5121)],
        'too many' => array_map(fn () => UploadedFile::fake()->image('product.jpg'), range(1, 6)),
    };
    $this->actingAs(photoReceiptEmployee())->post(route('administration.customer-return-requests.receive', $request), ['condition' => 'opened', 'files' => $files])->assertSessionHasErrors();
    expect($request->refresh()->customer_return_id)->toBeNull();
    expect($stock->refresh()->quarantined_quantity)->toBe(0);
    expect($stock->quantity)->toBe(3);
    $this->assertDatabaseCount('customer_returns', 0);
    $this->assertDatabaseCount('customer_refunds', 0);
    $this->assertDatabaseCount('administration_evidence', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['missing', 'pdf', 'fake image', 'oversized', 'too many']);

test('Staff receives either request path with product photos and exactly one quarantined return', function (bool $customerRequest) {
    [$order, $item, $stock, $request] = photoReceiptPurchase();
    if (! $customerRequest) {
        app(HandleCustomerReturnRequest::class)->withdraw($order->user, $order->id, $request->id);
    }
    $staff = photoReceiptEmployee();
    $url = $customerRequest ? route('administration.customer-return-requests.receive', $request) : route('administration.customer-returns.store');
    $data = ['condition' => 'opened', 'files' => [UploadedFile::fake()->image('product.jpg')]];
    if (! $customerRequest) {
        $data += ['order_item_id' => $item->id, 'quantity' => 1, 'reason_code' => 'defective'];
    }
    $this->actingAs($staff)->post($url, $data)->assertSessionHasNoErrors();
    $id = DB::table('customer_returns')->sole()->id;
    $this->assertDatabaseHas('customer_returns', ['id' => $id, 'requester_id' => $staff->id, 'status' => 'pending']);
    expect($stock->refresh()->quarantined_quantity)->toBe(1);
    expect($stock->quantity)->toBe(3);
    $photo = DB::table('administration_evidence')->sole();
    expect($photo->mime_type)->toBe('image/jpeg');
    Storage::disk('local')->assertExists($photo->path);
    $this->assertDatabaseCount('customer_refunds', 0);
    if ($customerRequest) {
        expect($request->refresh()->customer_return_id)->toBe((int) $id);
        $this->post($url, ['condition' => 'opened', 'files' => [UploadedFile::fake()->image('duplicate.jpg')]])->assertSessionHasErrors('return_request');
        $this->assertDatabaseCount('customer_returns', 1);
        $this->assertDatabaseCount('administration_evidence', 1);
        expect(Storage::disk('local')->allFiles())->toHaveCount(1);
    }
})->with([true, false]);

test('Manager Owner legacy IT assignments and customers cannot record new physical return receipt', function (string $role, bool $requestPath) {
    [$order, $item, $stock, $request] = photoReceiptPurchase();
    $actor = $role === 'customer' ? $order->user : photoReceiptEmployee($role);
    $url = $requestPath ? route('administration.customer-return-requests.receive', $request) : route('administration.customer-returns.store');
    $this->actingAs($actor)->post($url, ['condition' => 'opened', 'files' => [UploadedFile::fake()->image('product.jpg')],
        'order_item_id' => $item->id, 'quantity' => 1, 'reason_code' => 'defective'])->assertForbidden();
    $this->assertDatabaseCount('customer_returns', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['manager', 'owner', 'it', 'customer'])->with([true, false]);

test('receipt rollback removes uploaded files and all stock evidence and conversion effects', function () {
    [$order, $item, $stock, $request] = photoReceiptPurchase();
    Event::listen('eloquent.updated: '.CustomerReturnRequest::class, fn () => throw new RuntimeException('Forced linkage failure'));
    Exceptions::fake();
    try {
        $this->actingAs(photoReceiptEmployee())->post(route('administration.customer-return-requests.receive', $request), ['condition' => 'opened',
            'files' => [UploadedFile::fake()->image('product.jpg'), UploadedFile::fake()->image('serial.jpg')]])->assertServerError();
    } finally {
        Event::forget('eloquent.updated: '.CustomerReturnRequest::class);
    }
    Exceptions::assertReported(RuntimeException::class);
    expect($request->refresh()->customer_return_id)->toBeNull();
    expect($stock->refresh()->quarantined_quantity)->toBe(0);
    $this->assertDatabaseCount('customer_returns', 0);
    $this->assertDatabaseCount('administration_evidence', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseCount('audit_logs', 1);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('customer web and API share current published Sagay return instructions and safe missing-address fallback', function () {
    [$order] = photoReceiptPurchase();
    $branch = Branch::factory()->create(['city' => 'Sagay City', 'address' => 'Verified Sagay return address', 'contact_number' => null, 'is_published' => true]);
    $this->withToken($order->user->createToken('phone')->plainTextToken);
    $detail = $this->getJson(route('api.v1.orders.show', $order))->assertOk()->assertJsonPath('data.return_instructions.label', 'Return instructions')
        ->assertJsonPath('data.return_instructions.branch.address', $branch->address)->assertJsonPath('data.return_instructions.branch.contact_number', null);
    $this->actingAs($order->user)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page->where('order.return_instructions', $detail->json('data.return_instructions')));
    $branch->update(['address' => 'Updated Sagay return address']);
    $this->getJson(route('api.v1.orders.show', $order))->assertJsonPath('data.return_instructions.branch.address', 'Updated Sagay return address');
    $branch->update(['address' => null]);
    $this->getJson(route('api.v1.orders.show', $order))->assertJsonPath('data.return_instructions.branch', null);
    $branch->update(['address' => 'Unpublished return address', 'is_published' => false]);
    $this->getJson(route('api.v1.orders.show', $order))->assertJsonPath('data.return_instructions.branch', null)->assertDontSee('Unpublished return address');
});

test('reviewers read product photos while only Staff supplement them before a final decision', function (string $role) {
    [$order, $item, $stock, $request] = photoReceiptPurchase();
    $staff = photoReceiptEmployee();
    $id = app(HandleCustomerReturnRequest::class)->receive($staff, $request->id, 'opened', [UploadedFile::fake()->image('receipt.jpg')]);
    $reviewer = photoReceiptEmployee($role);
    $url = route('administration.evidence.store', ['subjectType' => 'customer_return', 'subjectId' => $id]);
    $photo = DB::table('administration_evidence')->sole();
    $this->actingAs($reviewer)->get(route('administration.evidence.show', $photo->id))->assertOk();
    $this->get(route('administration.customer-returns.index', ['case' => $id]))->assertInertia(fn (Assert $page) => $page
        ->where('returns.data.0.has_product_photo', true)->where('returns.data.0.can_upload_evidence', false)->where('can_receive_returns', false));
    $this->post($url, ['files' => [UploadedFile::fake()->image('reviewer.jpg')]])->assertForbidden();
    $this->actingAs(photoReceiptEmployee())->post($url, ['files' => [UploadedFile::fake()->image('supplement.jpg')]])->assertSessionHasNoErrors();
    $this->actingAs($reviewer)->post(route('administration.customer-returns.decide', $id), [
        'decision' => 'accept', 'resolution_type' => 'repair', 'resolution_summary' => 'Repair and return the original item',
        'approved_amount' => '0.00', 'disposition' => 'return_to_customer', 'reason' => 'Repair agreed after inspection',
    ])->assertSessionHasNoErrors();
    $this->actingAs($staff)->post($url, ['files' => [UploadedFile::fake()->image('late.jpg')]])->assertSessionHasErrors('files');
    $this->assertDatabaseCount('administration_evidence', 2);
    expect(Storage::disk('local')->allFiles())->toHaveCount(2);
})->with(['manager', 'owner']);

test('pending legacy cases require a stored product image and retain historical attachments', function () {
    [$order, $item, $stock, $request] = photoReceiptPurchase();
    $staff = photoReceiptEmployee();
    $id = app(HandleCustomerReturnRequest::class)->receive($staff, $request->id, 'opened', [UploadedFile::fake()->image('old-photo.jpg')]);
    $initial = DB::table('administration_evidence')->sole();
    Storage::disk('local')->delete($initial->path);
    Storage::disk('local')->put('legacy-report.pdf', '%PDF-1.4 inspection report');
    DB::table('administration_evidence')->insert(['subject_type' => 'customer_return', 'subject_id' => $id, 'uploaded_by' => $staff->id,
        'path' => 'legacy-report.pdf', 'mime_type' => 'application/pdf', 'original_name' => 'legacy-report.pdf', 'created_at' => now()]);
    $reviewer = photoReceiptEmployee('manager');
    $payload = ['decision' => 'accept', 'resolution_type' => 'repair', 'resolution_summary' => 'Repair and return original hardware',
        'approved_amount' => '0.00', 'disposition' => 'return_to_customer', 'reason' => 'Repair agreed after inspection'];
    $this->actingAs($reviewer)->post(route('administration.customer-returns.decide', $id), $payload)->assertSessionHasErrors('evidence');
    $this->assertDatabaseHas('customer_returns', ['id' => $id, 'status' => 'pending']);
    app(AdministrationEvidenceService::class)->store($staff, 'customer_return', $id, [UploadedFile::fake()->image('new-photo.jpg')]);
    $this->post(route('administration.customer-returns.decide', $id), $payload)->assertSessionHasNoErrors();
    $this->assertDatabaseHas('administration_evidence', ['id' => $initial->id, 'path' => $initial->path]);
    $this->assertDatabaseCount('administration_evidence', 3);
    $this->get(route('administration.evidence.show', DB::table('administration_evidence')->where('original_name', 'legacy-report.pdf')->value('id')))->assertOk();
});

test('product photo count remains limited to five without leaving rejected uploads on disk', function () {
    [$order, $item, $stock, $request] = photoReceiptPurchase();
    $staff = photoReceiptEmployee();
    $id = app(HandleCustomerReturnRequest::class)->receive($staff, $request->id, 'opened', array_map(fn () => UploadedFile::fake()->image('product.jpg'), range(1, 5)));
    $url = route('administration.evidence.store', ['subjectType' => 'customer_return', 'subjectId' => $id]);
    $this->actingAs($staff)->post($url, ['files' => [UploadedFile::fake()->image('sixth.jpg')]])->assertSessionHasErrors('files');
    $this->post($url, ['files' => [UploadedFile::fake()->create('not-photo.pdf', 10, 'application/pdf')]])->assertSessionHasErrors('files.0');
    $this->assertDatabaseCount('administration_evidence', 5);
    expect(Storage::disk('local')->allFiles())->toHaveCount(5);
});

test('a failed photo write cleans earlier files without recording receipt', function () {
    [$order, $item, $stock, $request] = photoReceiptPurchase();
    $source = UploadedFile::fake()->image('source.jpg');
    $broken = Mockery::mock(UploadedFile::class, [$source->getPathname(), 'broken.jpg', 'image/jpeg', null, true])->makePartial();
    $broken->shouldReceive('storeAs')->once()->andThrow(new RuntimeException('Forced storage failure'));
    Exceptions::fake();
    $this->actingAs(photoReceiptEmployee())->post(route('administration.customer-return-requests.receive', $request), ['condition' => 'opened',
        'files' => [UploadedFile::fake()->image('first.jpg'), $broken]])->assertServerError();
    Exceptions::assertReported(RuntimeException::class);
    $this->assertDatabaseCount('customer_returns', 0);
    $this->assertDatabaseCount('administration_evidence', 0);
    expect($stock->refresh()->quarantined_quantity)->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('a rejected receipt replay never overwrites or deletes the original product photo', function () {
    [$order, $item, $stock, $request] = photoReceiptPurchase();
    $payload = ['condition' => 'opened', 'files' => [UploadedFile::fake()->image('same-photo.jpg')]];
    $url = route('administration.customer-return-requests.receive', $request);
    $this->actingAs(photoReceiptEmployee())->post($url, $payload)->assertSessionHasNoErrors();
    $photo = DB::table('administration_evidence')->sole();
    $this->post($url, $payload)->assertSessionHasErrors('return_request');
    Storage::disk('local')->assertExists($photo->path);
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
    $this->assertDatabaseCount('administration_evidence', 1);
});
