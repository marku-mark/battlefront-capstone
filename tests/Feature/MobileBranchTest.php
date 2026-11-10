<?php

use App\Models\Branch;
use Database\Seeders\BranchSeeder;

test('guests receive all five published branches in the same order and shape as the web directory', function () {
    $this->seed(BranchSeeder::class);

    $response = $this->get(route('api.v1.branches.index'));
    $webResponse = $this->get(route('branches.index'));

    $response->assertOk()->assertHeader('Content-Type', 'application/json')
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.0.city', 'Sagay City')
        ->assertJsonPath('data.0.is_operational', true)
        ->assertJsonPath('data.0.operational_notice', config('battlefront.sagay_cod_notice'))
        ->assertJsonPath('data.0.contact_number', '0938 647 6046')
        ->assertJsonPath('data.0.email', 'battlefrontcomputertrading@gmail.com')
        ->assertJsonPath('data.0.operating_hours', '8:00 AM–6:00 PM')
        ->assertJsonPath('data.0.latitude', 10.89369)
        ->assertJsonPath('data.0.longitude', 123.4137268)
        ->assertJsonPath('data.1.city', 'Bacolod City')
        ->assertJsonPath('data.1.email', 'battlefrontbacolod@gmail.com')
        ->assertJsonPath('data.1.is_operational', false)
        ->assertJsonPath('data.1.operational_notice', null)
        ->assertJsonPath('data.2.city', 'Escalante City')
        ->assertJsonPath('data.2.address', 'National Road, Barangay Balintawak, Escalante City, 6124')
        ->assertJsonPath('data.2.contact_number', null)
        ->assertJsonPath('data.2.email', null)
        ->assertJsonPath('data.3.city', 'Guihulngan City')
        ->assertJsonPath('data.4.city', 'San Carlos City')
        ->assertJsonPath('data.4.email', null);
    expect(array_keys($response->json('data.0')))->toEqualCanonicalizing([
        'id', 'name', 'address', 'city', 'contact_number', 'latitude', 'longitude',
        'email', 'operating_hours', 'is_operational', 'operational_notice',
    ]);
    expect($response->json('data'))->toBe($webResponse->inertiaProps('branches'));
});

test('unconfirmed branch fields remain null and stored hours override configuration', function () {
    config(['battlefront.operating_hours' => '9:00 AM–5:00 PM']);
    $branch = Branch::factory()->create([
        'city' => 'Unconfirmed City', 'address' => null, 'contact_number' => null,
        'latitude' => null, 'longitude' => null,
        'operating_hours' => '10:00 AM–4:00 PM',
    ]);

    $this->get(route('api.v1.branches.index'))->assertOk()->assertExactJson(['data' => [[
        'id' => $branch->id, 'name' => 'Battlefront Computer Trading',
        'city' => 'Unconfirmed City', 'address' => null, 'contact_number' => null,
        'latitude' => null, 'longitude' => null, 'email' => null,
        'operating_hours' => '10:00 AM–4:00 PM', 'is_operational' => false, 'operational_notice' => null,
    ]]]);
});

test('an empty branch directory returns an empty data collection', function () {
    $this->get(route('api.v1.branches.index'))->assertOk()->assertExactJson(['data' => []]);
});
