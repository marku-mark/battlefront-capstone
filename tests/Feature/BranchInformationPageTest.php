<?php

use Database\Seeders\BranchSeeder;
use Inertia\Testing\AssertableInertia as Assert;

test('guests can view the confirmed branch information', function () {
    $this->seed(BranchSeeder::class);

    $response = $this->get(route('branches.index'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Branches/Index')
            ->where('auth.user', null)
            ->has('branches', 5)
            ->where('branches.0.city', 'Sagay City')
            ->where('branches.0.is_operational', true)
            ->where('branches.0.operational_notice', config('battlefront.sagay_cod_notice'))
            ->where('branches.0.address', 'A, E Marañon St., Brgy. Poblacion II, Sagay City, Negros Occidental (beside LBC Sagay City), Sagay, Philippines 6122')
            ->where('branches.0.contact_number', '0938 647 6046')
            ->where('branches.0.latitude', 10.89369)
            ->where('branches.0.longitude', 123.4137268)
            ->where('branches.0.email', 'battlefrontcomputertrading@gmail.com')
            ->where('branches.0.operating_hours', '8:00 AM–6:00 PM')
            ->where('branches.1.city', 'Bacolod City')
            ->where('branches.1.is_operational', false)
            ->where('branches.1.operational_notice', null)
            ->where('branches.1.address', 'Downtown, Along SKG Shopping Center, Beside Ukay-Ukayan 58 Lizares St. Brgy. 13, Bacolod CIty, Philippines, 6100')
            ->where('branches.1.contact_number', '0961 176 4608')
            ->where('branches.1.latitude', 10.6717542)
            ->where('branches.1.longitude', 122.9470409)
            ->where('branches.1.email', 'battlefrontbacolod@gmail.com')
            ->where('branches.2.city', 'Escalante City')
            ->where('branches.2.is_operational', false)
            ->where('branches.2.latitude', 10.84283)
            ->where('branches.2.longitude', 123.4985395)
            ->where('branches.3.city', 'Guihulngan City')
            ->where('branches.3.is_operational', false)
            ->where('branches.3.latitude', 10.119597)
            ->where('branches.3.longitude', 123.273872)
            ->where('branches.4.city', 'San Carlos City')
            ->where('branches.4.is_operational', false)
            ->where('branches.4.latitude', 10.4829475)
            ->where('branches.4.longitude', 123.4216919));
});

test('unconfirmed branch contact details remain null while its confirmed address is shown', function () {
    $this->seed(BranchSeeder::class);

    $response = $this->get(route('branches.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('branches.2.city', 'Escalante City')
        ->where('branches.2.address', 'National Road, Barangay Balintawak, Escalante City, 6124')
        ->where('branches.2.contact_number', null)
        ->where('branches.2.email', null)
        ->where('branches.4.city', 'San Carlos City')
        ->where('branches.4.contact_number', null)
        ->where('branches.4.email', null));
});
