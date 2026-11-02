<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('Owner can inspect account access changes independently of the event actor', function (string $actorRole, string $action, string $subjectType, bool $wasActive) {
    $ownerViewer = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'owner')->value('id'),
    ]);
    $actor = $actorRole === 'system' ? null : User::factory()->administrator()->create([
        'administration_role_id' => ($actorRole === 'it' ? DB::table('administration_roles')->where('code', 'owner')->value('id') : DB::table('administration_roles')->where('code', $actorRole)->value('id')),
    ]);
    $id = DB::table('audit_logs')->insertGetId([
        'actor_id' => $actor?->id,
        'action' => $action,
        'subject_type' => $subjectType,
        'subject_id' => 123,
        'reason' => 'Account access review',
        'changes' => json_encode([
            'before' => ['role' => 'staff', 'is_active' => $wasActive],
            'after' => ['role' => 'manager', 'is_active' => ! $wasActive],
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);
    $original = DB::table('audit_logs')->where('id', $id)->first();

    $this->actingAs($ownerViewer)->get(route('administration.reports.audit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.total', 1)
            ->where('entries.data.0.details_visible', true)
            ->where('entries.data.0.reason', 'Account access review')
            ->where('entries.data.0.details', [
                ['label' => 'Role', 'before' => 'staff', 'after' => 'manager'],
                ['label' => 'Is Active', 'before' => $wasActive ? 'Yes' : 'No', 'after' => $wasActive ? 'No' : 'Yes'],
            ]));

    expect(DB::table('audit_logs')->where('id', $id)->first())->toEqual($original);
})->with(['owner', 'it', 'system'])->with([
    'web access update' => ['team.updated', 'administrator_account'],
    'CLI assignment' => ['team.role.assigned_by_cli', 'user'],
])->with(['deactivation' => true, 'activation' => false]);

test('Owner can inspect recorded account creation details', function (string $action, string $subjectType) {
    $ownerViewer = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'owner')->value('id'),
    ]);
    DB::table('audit_logs')->insert([
        'actor_id' => null,
        'action' => $action,
        'subject_type' => $subjectType,
        'subject_id' => 123,
        'changes' => json_encode(['role' => 'owner'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);

    $this->actingAs($ownerViewer)->get(route('administration.reports.audit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.data.0.details_visible', true)
            ->where('entries.data.0.details', [['label' => 'Role', 'before' => null, 'after' => 'owner']]));
})->with([
    'web creation' => ['team.created', 'administrator_account'],
    'CLI Owner creation' => ['team.owner.created_by_cli', 'user'],
    'seeded account' => ['team.created_by_seeder', 'user'],
    'seeded Owner' => ['team.owner.created_by_seeder', 'user'],
]);

test('Owner can inspect Owner permission changes while other roles retain restricted details', function (string $viewerRole, bool $visible) {
    $owner = User::factory()->administrator()->create();
    $viewer = User::factory()->administrator()->create([
        'administration_role_id' => ($viewerRole === 'it' ? legacyItRoleId() : DB::table('administration_roles')->where('code', $viewerRole)->value('id')),
    ]);
    DB::table('audit_logs')->insert([
        'actor_id' => $owner->id,
        'action' => 'role.permissions.updated',
        'subject_type' => 'administrator_account',
        'subject_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
        'changes' => json_encode([
            'before' => ['orders.view', 'inventory.view'],
            'after' => ['orders.view', 'reports.view'],
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);

    $this->actingAs($viewer)->get(route('administration.reports.audit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.data.0.details_visible', $visible)
            ->where('entries.data.0.details', $visible ? [
                ['label' => '', 'before' => 'orders.view', 'after' => 'orders.view'],
                ['label' => '1', 'before' => 'inventory.view', 'after' => 'reports.view'],
            ] : []));
})->with(['Owner' => ['owner', true], 'Manager' => ['manager', false]]);

test('legacy IT assignments cannot receive restricted business audit details, including historic IT events', function (string $action, string $subjectType) {
    $it = User::factory()->administrator()->create([
        'administration_role_id' => legacyItRoleId(),
    ]);
    DB::table('audit_logs')->insert([
        'actor_id' => $it->id,
        'action' => $action,
        'subject_type' => $subjectType,
        'subject_id' => 123,
        'reason' => 'Restricted business explanation',
        'changes' => json_encode(['before' => ['value' => 'restricted-before'], 'after' => ['value' => 'restricted-after']], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);

    $this->actingAs($it)->get(route('administration.reports.audit'))->assertForbidden();
})->with([
    'pricing and costs' => ['product.updated', 'product'],
    'inventory' => ['inventory.adjusted', 'inventory'],
    'orders' => ['order.status.updated', 'order'],
    'payments' => ['order.payment_status.updated', 'order'],
    'returns' => ['customer_return.accepted', 'customer_return'],
    'refunds' => ['refund.updated', 'customer_refund'],
    'refund payments' => ['refund.payment.recorded', 'refund_payment'],
    'financial records' => ['sale.recorded', 'sale'],
    'business alert on user' => ['approval.concentration_alert', 'user'],
    'unknown account event' => ['account.unknown', 'administrator_account'],
    'account action on business subject' => ['team.updated', 'product'],
]);

test('Owner account details preserve recursive credential and private path redaction', function () {
    $ownerViewer = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'owner')->value('id'),
    ]);
    $credentials = ['password' => 'password-value', 'remember_token' => 'token-value', 'two_factor_secret' => 'secret-value', 'private_path' => 'private/credential-file'];
    DB::table('audit_logs')->insert([
        'actor_id' => null,
        'action' => 'team.updated',
        'subject_type' => 'administrator_account',
        'subject_id' => 123,
        'changes' => json_encode(['before' => $credentials, 'after' => $credentials, 'nested' => $credentials], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);

    $this->actingAs($ownerViewer)->get(route('administration.reports.audit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.data.0.details', [
                ['label' => 'Password', 'before' => '[redacted]', 'after' => '[redacted]'],
                ['label' => 'Remember Token', 'before' => '[redacted]', 'after' => '[redacted]'],
                ['label' => 'Two Factor Secret', 'before' => '[redacted]', 'after' => '[redacted]'],
                ['label' => 'Private Path', 'before' => '[redacted]', 'after' => '[redacted]'],
                ['label' => 'Nested', 'before' => null, 'after' => '{"password":"[redacted]","remember_token":"[redacted]","two_factor_secret":"[redacted]","private_path":"[redacted]"}'],
            ])
            ->missing('entries.data.0.changes'));
});

test('legacy IT assignments cannot access audit details or exports', function () {
    $it = User::factory()->administrator()->create([
        'administration_role_id' => legacyItRoleId(),
    ]);
    $grants = DB::table('administration_role_permissions')->orderBy('administration_role_id')->orderBy('administration_permission_id')->get()->all();

    $this->actingAs($it)->put(route('administration.team.permissions.update', 'manager'), [
        'permissions' => ['orders.view'], 'edit_version' => 1,
    ])->assertForbidden();
    $this->get(route('administration.reports.audit.export'))->assertForbidden();

    expect($it->can('audit.view'))->toBeFalse()
        ->and($it->can('permissions.manage'))->toBeFalse()
        ->and($it->can('audit.export'))->toBeFalse()
        ->and($it->can('products.manage'))->toBeFalse()
        ->and($it->can('products.cost'))->toBeFalse()
        ->and($it->can('inventory.view'))->toBeFalse()
        ->and($it->can('orders.view'))->toBeFalse()
        ->and($it->can('reports.view'))->toBeFalse();
    expect(DB::table('administration_role_permissions')->orderBy('administration_role_id')->orderBy('administration_permission_id')->get()->all())->toEqual($grants);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('retired IT cannot search audit details while Owner retains access', function () {
    $owner = User::factory()->administrator()->create();
    $it = User::factory()->administrator()->create([
        'administration_role_id' => legacyItRoleId(),
    ]);
    DB::table('audit_logs')->insert([
        ['actor_id' => $owner->id, 'action' => 'team.updated', 'subject_type' => 'administrator_account', 'subject_id' => 1,
            'reason' => 'Account review', 'changes' => json_encode(['role' => 'manager']), 'created_at' => now()],
        ['actor_id' => $owner->id, 'action' => 'order.updated', 'subject_type' => 'order', 'subject_id' => 2,
            'reason' => 'Hidden financial note', 'changes' => json_encode(['amount' => 'restricted-amount']), 'created_at' => now()],
    ]);

    $this->actingAs($it)->get(route('administration.reports.audit', ['q' => 'manager']))->assertForbidden();
    $this->get(route('administration.reports.audit', ['q' => 'restricted-amount']))->assertForbidden();
    $this->get(route('administration.reports.audit', ['q' => 'Hidden financial note']))->assertForbidden();
    $this->get(route('administration.reports.audit', ['action' => 'order.updated']))->assertForbidden();
    $this->actingAs($owner)->get(route('administration.reports.audit', ['q' => 'restricted-amount']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1));
});

test('audit export protects spreadsheet cells containing staff supplied text', function () {
    $owner = User::factory()->administrator()->create();
    DB::table('audit_logs')->insert([
        'actor_id' => $owner->id,
        'action' => 'test.export',
        'subject_type' => 'supplier_return',
        'subject_id' => 123,
        'reason' => '  =HYPERLINK("bad")',
        'changes' => json_encode(['reference' => '=CMD()'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);

    $response = $this->actingAs($owner)->get(route('administration.reports.audit.export'));

    $response->assertOk()->assertStreamed()->assertDownload('audit-log.csv');
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $response->streamedContent());
    rewind($stream);
    fgetcsv($stream, 0, ',', '"', '');
    $row = fgetcsv($stream, 0, ',', '"', '');
    fclose($stream);

    expect($row[6])->toBe("'  =HYPERLINK(\"bad\")");
});

test('audit events show readable changes only to a role allowed to view the record', function () {
    $owner = User::factory()->administrator()->create();
    $it = User::factory()->administrator()->create([
        'administration_role_id' => legacyItRoleId(),
    ]);
    DB::table('audit_logs')->insert([
        'actor_id' => $owner->id,
        'action' => 'product.updated',
        'subject_type' => 'product',
        'subject_id' => 123,
        'reason' => 'Price correction',
        'changes' => json_encode(['before' => ['price' => '100'], 'after' => ['price' => '120', 'proof_path' => 'private/file.jpg']], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);

    $this->actingAs($owner)->get(route('administration.reports.audit', ['action' => 'product.updated']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)
            ->where('entries.data.0.action_label', 'Product Updated')
            ->where('entries.data.0.details.0.before', '100')
            ->where('entries.data.0.details.0.after', '120')
            ->where('entries.data.0.details.1.after', '[redacted]')
            ->where('entries.data.0.reason', 'Price correction'));
    $this->actingAs($it)->get(route('administration.reports.audit', ['action' => 'product.updated']))->assertForbidden();
});

test('product audit hides acquisition costs and cost change reasons without cost permission', function () {
    $owner = User::factory()->administrator()->create();
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    DB::table('audit_logs')->insert([
        'actor_id' => $owner->id,
        'action' => 'product.updated',
        'subject_type' => 'product',
        'subject_id' => 321,
        'reason' => 'Acquisition cost changed to 42.50 after supplier invoice review',
        'changes' => json_encode([
            'before' => ['acquisition_cost' => '40.00', 'acquisition_cost_source' => 'manual'],
            'after' => ['acquisition_cost' => '42.50', 'acquisition_cost_source' => 'manual', 'name' => 'Updated product'],
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);

    $this->actingAs($manager)->get(route('administration.reports.audit', ['action' => 'product.updated']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.data.0.reason', null)
            ->where('entries.data.0.details.0.label', 'Name')
            ->where('entries.data.0.details.0.after', 'Updated product')
            ->missing('entries.data.0.details.1'));
    $this->get(route('administration.reports.audit', ['q' => '42.50']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 0));

    $this->actingAs($owner)->get(route('administration.reports.audit', ['action' => 'product.updated']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.data.0.reason', 'Acquisition cost changed to 42.50 after supplier invoice review')
            ->where('entries.data.0.details.0.after', '42.50'));
});

test('audit log can search action, actor, reason, and recorded changes', function () {
    $owner = User::factory()->administrator()->create(['name' => 'Searchable Owner']);
    DB::table('audit_logs')->insert([
        'actor_id' => $owner->id,
        'action' => 'product.updated',
        'subject_type' => 'product',
        'subject_id' => 456,
        'reason' => 'Search fixture',
        'changes' => json_encode(['name' => 'Target hardware'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);

    $this->actingAs($owner)->get(route('administration.reports.audit', ['q' => 'Target hardware']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.q', 'Target hardware')
            ->where('entries.total', 1)
            ->where('entries.data.0.subject_id', 456));
});

test('audit timestamps are serialized in UTC and exported in Philippine time', function () {
    $owner = User::factory()->administrator()->create();
    DB::table('audit_logs')->insert([
        'actor_id' => $owner->id,
        'action' => 'stock.checked',
        'subject_type' => 'inventory',
        'subject_id' => 7,
        'created_at' => '2026-09-10 02:00:00',
    ]);

    $this->actingAs($owner)->get(route('administration.reports.audit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.data.0.created_at', '2026-09-10T02:00:00.000000Z'));

    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $this->actingAs($owner)->get(route('administration.reports.audit.export'))->streamedContent());
    rewind($stream);
    $header = fgetcsv($stream, 0, ',', '"', '');
    $row = fgetcsv($stream, 0, ',', '"', '');
    fclose($stream);

    expect($header[1])->toBe('Date');
    expect($row[1])->toBe('2026-09-10 10:00:00 +08:00');
});

test('audit date filters use inclusive Philippine calendar days', function () {
    $owner = User::factory()->administrator()->create();
    DB::table('audit_logs')->insert([
        ['actor_id' => $owner->id, 'action' => 'time.boundary', 'subject_type' => 'inventory', 'subject_id' => 1,
            'created_at' => '2026-09-09 15:59:59'],
        ['actor_id' => $owner->id, 'action' => 'time.boundary', 'subject_type' => 'inventory', 'subject_id' => 2,
            'created_at' => '2026-09-09 16:00:00'],
        ['actor_id' => $owner->id, 'action' => 'time.boundary', 'subject_type' => 'inventory', 'subject_id' => 3,
            'created_at' => '2026-09-10 15:59:59'],
        ['actor_id' => $owner->id, 'action' => 'time.boundary', 'subject_type' => 'inventory', 'subject_id' => 4,
            'created_at' => '2026-09-10 16:00:00'],
    ]);

    $this->actingAs($owner)->get(route('administration.reports.audit', [
        'from' => '2026-09-10',
        'to' => '2026-09-10',
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('entries.total', 2)
        ->where('entries.data.0.subject_id', 3)
        ->where('entries.data.1.subject_id', 2));
});

test('audit change values use shipment and order labels while preserving free text', function () {
    $owner = User::factory()->administrator()->create();
    DB::table('audit_logs')->insert([
        'actor_id' => $owner->id,
        'action' => 'order.status.updated',
        'subject_type' => 'order',
        'subject_id' => 123,
        'changes' => json_encode([
            'before' => 'processing',
            'after' => 'completed',
            'shipment' => ['status' => 'in_transit', 'note' => 'leave_this_text_alone'],
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);

    $this->actingAs($owner)->get(route('administration.reports.audit', ['action' => 'order.status.updated']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.data.0.details.0.label', 'Order status')
            ->where('entries.data.0.details.0.before', 'Processing')
            ->where('entries.data.0.details.0.after', 'Completed')
            ->where('entries.data.0.details.1.after', '{"status":"In transit","note":"leave_this_text_alone"}'));
});

test('product activation is recorded once and a repeated submission leaves history unchanged', function () {
    $owner = User::factory()->administrator()->create();
    $product = Product::factory()->create(['is_active' => true]);

    $this->actingAs($owner)->patch(route('administration.products.activation.update', $product), [
        'is_active' => false,
    ])->assertSessionHasNoErrors();
    $this->actingAs($owner)->patch(route('administration.products.activation.update', $product), [
        'is_active' => false,
    ])->assertSessionHasNoErrors();

    expect($product->fresh()->is_active)->toBeFalse();
    expect(DB::table('audit_logs')->where('subject_type', 'product')->where('subject_id', $product->id)
        ->where('action', 'product.deactivated')->count())->toBe(1);
});

test('audit screen and export use the same date actor action and subject filters', function () {
    $owner = User::factory()->administrator()->create();
    DB::table('audit_logs')->insert([
        ['actor_id' => $owner->id, 'action' => 'stock.checked', 'subject_type' => 'inventory', 'subject_id' => 7,
            'reason' => 'Count verified', 'created_at' => '2026-09-10 10:00:00'],
        ['actor_id' => $owner->id, 'action' => 'stock.checked', 'subject_type' => 'inventory', 'subject_id' => 8,
            'reason' => 'Other item', 'created_at' => '2026-09-10 10:00:00'],
    ]);
    $filters = ['from' => '2026-09-10', 'to' => '2026-09-10', 'actor_id' => $owner->id,
        'action' => 'stock.checked', 'subject_type' => 'inventory', 'subject_id' => 7];

    $this->actingAs($owner)->get(route('administration.reports.audit', $filters))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)
            ->where('entries.data.0.reason', 'Count verified')
            ->where('actors.0.id', $owner->id)
            ->where('actions', ['stock.checked'])
            ->where('subjectTypes', ['inventory']));
    $csv = $this->actingAs($owner)->get(route('administration.reports.audit.export', $filters))
        ->assertOk()->streamedContent();
    expect($csv)->toContain('Count verified')->not->toContain('Other item');
});

test('audit log identifies customer cancellation actors and filters by actor type', function () {
    $owner = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create(['name' => 'Badi Gaming']);
    DB::table('audit_logs')->insert([
        'actor_id' => $customer->id,
        'action' => 'order.status.updated',
        'subject_type' => 'order',
        'subject_id' => 28,
        'changes' => json_encode(['before' => 'pending', 'after' => 'cancelled'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);

    $this->actingAs($owner)->get(route('administration.reports.audit', ['actor_type' => 'customer']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)
            ->where('entries.data.0.actor_name', 'Badi Gaming')
            ->where('entries.data.0.actor_type', 'customer')
            ->where('entries.data.0.action_label', 'Customer cancelled order'));
});
