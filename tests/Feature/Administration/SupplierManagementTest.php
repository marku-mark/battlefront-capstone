<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;

test('supplier creation and update retain their audited payloads and version', function () {
    $owner = User::factory()->administrator()->create();
    $attributes = ['name' => 'Primary supplier', 'contact_name' => 'Supplier contact', 'email' => 'supplier@example.com'];

    $this->actingAs($owner)->post(route('administration.suppliers.store'), $attributes)
        ->assertRedirect()->assertSessionHasNoErrors();

    $supplier = DB::table('suppliers')->where('name', $attributes['name'])->first();
    $this->assertDatabaseHas('suppliers', [...$attributes, 'is_active' => true, 'edit_version' => 1]);
    $created = DB::table('audit_logs')->where('action', 'supplier.created')->first();
    expect($created->actor_id)->toBe($owner->id)
        ->and($created->subject_type)->toBe('supplier')
        ->and($created->subject_id)->toBe($supplier->id)
        ->and(json_decode($created->changes, true))->toBe($attributes);

    $updated = ['name' => 'Updated supplier', 'is_active' => false];
    $this->patch(route('administration.suppliers.update', $supplier->id), [...$updated, 'edit_version' => 1])
        ->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('suppliers', [...$updated, 'edit_version' => 2, 'contact_name' => $attributes['contact_name']]);
    $audit = DB::table('audit_logs')->where('action', 'supplier.updated')->first();
    expect($audit->actor_id)->toBe($owner->id)
        ->and($audit->subject_id)->toBe($supplier->id)
        ->and(json_decode($audit->changes, true))->toBe(['before' => (array) $supplier, 'after' => $updated]);

    $this->patch(route('administration.suppliers.update', $supplier->id), ['name' => 'Stale supplier', 'is_active' => true, 'edit_version' => 1])
        ->assertSessionHasErrors('edit_version');

    $this->assertDatabaseHas('suppliers', [...$updated, 'edit_version' => 2]);
    expect(DB::table('audit_logs')->where('subject_type', 'supplier')->count())->toBe(2);
});

test('supplier filtering preserves active status search and pagination query strings', function () {
    $owner = User::factory()->administrator()->create();
    foreach (range(1, 26) as $number) {
        DB::table('suppliers')->insert(['name' => sprintf('Supplier %02d', $number), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('suppliers')->insert(['name' => 'Supplier inactive', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($owner)->get(route('administration.suppliers.index', ['q' => 'Supplier', 'status' => 'active', 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.q', 'Supplier')->where('filters.status', 'active')
            ->where('suppliers.total', 26)->where('suppliers.current_page', 2)
            ->where('suppliers.data.0.name', 'Supplier 26')
            ->where('suppliers.prev_page_url', fn (string $url): bool => str_contains($url, 'status=active') && str_contains($url, 'q=Supplier')));
});

test('supplier mutations require supplier permission and retain missing record responses', function () {
    $it = User::factory()->administrator()->create(['administration_role_id' => legacyItRoleId()]);

    $this->actingAs($it)->post(route('administration.suppliers.store'), ['name' => 'Forbidden supplier'])->assertForbidden();

    $this->assertDatabaseCount('suppliers', 0);
    $this->assertDatabaseCount('audit_logs', 0);

    $owner = User::factory()->administrator()->create();
    $this->actingAs($owner)->patch(route('administration.suppliers.update', 999), ['name' => 'Missing supplier', 'is_active' => true, 'edit_version' => 1])->assertNotFound();

    $this->assertDatabaseCount('audit_logs', 0);
});

test('supplier audit failures roll back the supplier operation', function (bool $updating) {
    $owner = User::factory()->administrator()->create();
    $supplierId = $updating ? DB::table('suppliers')->insertGetId([
        'name' => 'Original supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]) : null;
    DB::unprepared("CREATE TRIGGER fail_supplier_audit BEFORE INSERT ON audit_logs WHEN NEW.subject_type = 'supplier' BEGIN SELECT RAISE(ABORT, 'Forced supplier audit failure'); END");
    Exceptions::fake();

    try {
        $this->actingAs($owner);
        if ($updating) {
            $this->patch(route('administration.suppliers.update', $supplierId), ['name' => 'Changed supplier', 'is_active' => false, 'edit_version' => 1])->assertServerError();
        } else {
            $this->post(route('administration.suppliers.store'), ['name' => 'New supplier'])->assertServerError();
        }
    } finally {
        DB::unprepared('DROP TRIGGER fail_supplier_audit');
    }

    $this->assertDatabaseCount('audit_logs', 0);
    if ($updating) {
        $this->assertDatabaseHas('suppliers', ['id' => $supplierId, 'name' => 'Original supplier', 'is_active' => true, 'edit_version' => 1]);
    } else {
        $this->assertDatabaseCount('suppliers', 0);
    }
})->with(['creation' => false, 'update' => true]);
