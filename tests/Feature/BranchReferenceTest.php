<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

test('the branch schema follows the current application design', function () {
    expect(Schema::getColumnListing('branches'))->toEqualCanonicalizing([
        'id',
        'name',
        'address',
        'city',
        'contact_number',
        'latitude',
        'longitude',
        'email',
        'operating_hours',
        'is_published',
        'seed_key',
    ])->and(Schema::hasColumn('users', 'branch_id'))->toBeFalse();
});

test('branch reference data can be seeded repeatedly', function () {
    $this->seed();
    Branch::query()->where('city', 'Sagay City')->update(['contact_number' => '0999 123 4567']);

    $this->seed();

    expect(Branch::query()->count())->toBe(5)
        ->and(User::query()->where('email', 'test@example.com')->count())->toBe(1);
    $this->assertDatabaseHas('branches', [
        'city' => 'Sagay City',
        'contact_number' => '0999 123 4567',
    ]);
    $this->assertDatabaseHas('branches', [
        'city' => 'San Carlos City',
        'contact_number' => null,
    ]);
    $this->assertDatabaseHas('branches', [
        'city' => 'Guihulngan City',
        'contact_number' => '0947 946 5723',
    ]);
    $this->assertDatabaseHas('branches', [
        'city' => 'Bacolod City',
        'contact_number' => '0961 176 4608',
    ]);
    $this->assertDatabaseHas('branches', [
        'city' => 'Escalante City',
        'address' => 'National Road, Barangay Balintawak, Escalante City, 6124',
        'contact_number' => null,
    ]);

    $branches = Branch::query()->get()->keyBy('city');

    expect((float) $branches['Sagay City']->latitude)
        ->toEqualWithDelta(10.893690028077906, 0.0000001)
        ->and((float) $branches['Sagay City']->longitude)
        ->toEqualWithDelta(123.41372677628254, 0.0000001)
        ->and((float) $branches['Escalante City']->latitude)
        ->toEqualWithDelta(10.84283002809064, 0.0000001)
        ->and((float) $branches['Escalante City']->longitude)
        ->toEqualWithDelta(123.49853947111953, 0.0000001)
        ->and((float) $branches['San Carlos City']->latitude)
        ->toEqualWithDelta(10.482947521818934, 0.0000001)
        ->and((float) $branches['San Carlos City']->longitude)
        ->toEqualWithDelta(123.42169185526996, 0.0000001)
        ->and((float) $branches['Guihulngan City']->latitude)
        ->toEqualWithDelta(10.119597, 0.0000001)
        ->and((float) $branches['Guihulngan City']->longitude)
        ->toEqualWithDelta(123.273872, 0.0000001)
        ->and((float) $branches['Bacolod City']->latitude)
        ->toEqualWithDelta(10.671754246079693, 0.0000001)
        ->and((float) $branches['Bacolod City']->longitude)
        ->toEqualWithDelta(122.9470409276533, 0.0000001);
});

test('seeded branches expose stored reference information', function () {
    $this->seed();

    $branches = Branch::query()->get()->keyBy('city');

    expect(Branch::operational()->sole()->city)->toBe('Sagay City')
        ->and($branches['Sagay City']->toArray())->toMatchArray([
            'email' => 'battlefrontcomputertrading@gmail.com',
            'operating_hours' => '8:00 AM–6:00 PM',
            'is_operational' => true,
        ])
        ->and($branches['Sagay City']->is_operational)->toBeTrue()
        ->and($branches['Bacolod City']->is_operational)->toBeFalse()
        ->and($branches['San Carlos City']->is_operational)->toBeFalse()
        ->and($branches->pluck('operating_hours')->unique()->all())->toBe(['8:00 AM–6:00 PM'])
        ->and($branches['Sagay City']->email)->toBe('battlefrontcomputertrading@gmail.com')
        ->and($branches['Guihulngan City']->email)->toBe('battlefrontcomputertrading@gmail.com')
        ->and($branches['Bacolod City']->email)->toBe('battlefrontbacolod@gmail.com')
        ->and($branches['San Carlos City']->email)->toBeNull()
        ->and($branches['Escalante City']->email)->toBeNull();
});
