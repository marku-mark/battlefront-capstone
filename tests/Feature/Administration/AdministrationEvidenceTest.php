<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\AdministrationEvidenceService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

test('inspection evidence stays private and is limited to five files per case', function () {
    Storage::fake('local');
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->actingAs($staff)->post(route('administration.damage-reports.store'), [
        'product_id' => $product->id, 'quantity' => 1, 'reason_code' => 'handling_damage', 'reason' => 'Damaged packaging found',
    ])->assertSessionHasNoErrors();
    $damageId = DB::table('damage_reports')->where('product_id', $product->id)->value('id');

    $this->actingAs($staff)->post(route('administration.evidence.store', [
        'subjectType' => 'damage_report', 'subjectId' => $damageId,
    ]), ['files' => [UploadedFile::fake()->create('inspection.pdf', 10, 'application/pdf')]])->assertSessionHasNoErrors();

    $file = DB::table('administration_evidence')->where('subject_type', 'damage_report')->where('subject_id', $damageId)->first();
    Storage::disk('local')->assertExists($file->path);
    expect(fn () => DB::table('administration_evidence')->where('id', $file->id)->update(['original_name' => 'altered.pdf']))->toThrow(QueryException::class);
    $this->actingAs($staff)->get(route('administration.evidence.show', $file->id))->assertOk()->assertDownload('inspection.pdf');
    $this->actingAs($customer)->get(route('administration.evidence.show', $file->id))->assertForbidden();
    $this->actingAs($staff)->post(route('administration.evidence.store', [
        'subjectType' => 'damage_report', 'subjectId' => $damageId,
    ]), ['files' => [UploadedFile::fake()->create('script.php', 10, 'text/plain')]])->assertSessionHasErrors('files.0');
    $this->actingAs($staff)->post(route('administration.evidence.store', [
        'subjectType' => 'damage_report', 'subjectId' => $damageId,
    ]), ['files' => [
        UploadedFile::fake()->create('two.pdf', 10, 'application/pdf'),
        UploadedFile::fake()->create('three.pdf', 10, 'application/pdf'),
        UploadedFile::fake()->create('four.pdf', 10, 'application/pdf'),
        UploadedFile::fake()->create('five.pdf', 10, 'application/pdf'),
        UploadedFile::fake()->create('six.pdf', 10, 'application/pdf'),
    ]])->assertSessionHasErrors('files');
    $this->assertDatabaseCount('administration_evidence', 1);
});

test('return product photos are stored and served through the configured returns bucket', function () {
    config(['filesystems.disks.battlefront_returns.driver' => 's3']);
    Storage::fake('battlefront_returns');
    expect(config('filesystems.disks.battlefront_returns.bucket'))->toBe('battlefront_returns')
        ->and(config('filesystems.disks.battlefront_returns.visibility'))->toBe('private');
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->for(Product::factory()->create())->create();
    $returnId = DB::table('customer_returns')->insertGetId([
        'order_id' => $order->id,
        'requester_id' => $staff->id,
        'status' => 'pending',
        'reason' => 'Photo evidence test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_return_items')->insert([
        'customer_return_id' => $returnId,
        'order_item_id' => $item->id,
        'quantity' => 1,
        'condition' => 'opened',
    ]);

    $this->actingAs($staff)->post(route('administration.evidence.store', [
        'subjectType' => 'customer_return', 'subjectId' => $returnId,
    ]), ['files' => [UploadedFile::fake()->image('return.jpg')]])->assertSessionHasNoErrors();

    $file = DB::table('administration_evidence')->where('subject_type', 'customer_return')->where('subject_id', $returnId)->first();
    expect($file->path)->toStartWith('returns/product-photos/');
    Storage::disk('battlefront_returns')->assertExists($file->path);
    $this->actingAs($staff)->get(route('administration.evidence.show', $file->id))
        ->assertOk()->assertHeader('content-type', 'image/jpeg');

    $prepared = app(AdministrationEvidenceService::class)->prepareProductPhotos([UploadedFile::fake()->image('discard.jpg')]);
    Storage::disk('battlefront_returns')->assertExists($prepared[0]['path']);
    app(AdministrationEvidenceService::class)->discardPreparedFiles($prepared);
    Storage::disk('battlefront_returns')->assertMissing($prepared[0]['path']);
});
