<?php

use App\Console\Commands\ResetDevCommand;
use App\Enums\ChatbotCategory;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\ChatbotKnowledge;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductComponentProfile;
use App\Models\Sale;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Services\Forecasting\ProductForecastPreparationService;
use App\Services\RealCatalogImportService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentCustomerSeeder;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Database\Seeders\PcBuilderCatalogDraftSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\HistoricalSalesCatalogFixtures;

beforeEach(function () {
    $this->fakeIsolatedStorage('local');
});

test('development reset rejects unsafe environments even with force', function (string $environment, bool $force) {
    $sentinel = User::factory()->create();
    $this->app->instance('env', $environment);

    $this->artisan('battlefront:reset-dev', ['--force' => $force, '--with-sales-history' => true])
        ->expectsOutputToContain('only allowed in local and testing')
        ->assertFailed();

    $this->assertModelExists($sentinel);
})->with(['production', 'staging'])->with([false, true]);

test('development reset requires confirmation', function (string $environment) {
    $coveragePath = config('forecasting.development_manifest');
    Storage::disk('local')->put($coveragePath, 'existing declaration');
    $this->app->instance('env', $environment);
    $sentinel = User::factory()->create();
    $this->mock(RealCatalogImportService::class, function ($mock) {
        $mock->shouldReceive('inspectFiles')->once()->andReturn(array_fill(0, 654, []));
        $mock->shouldNotReceive('execute');
    });

    $this->artisan('battlefront:reset-dev')
        ->expectsConfirmation('Recreate this development database?', 'no')
        ->expectsOutputToContain('Reset cancelled')
        ->assertFailed();

    $this->assertModelExists($sentinel);
    expect(Storage::disk('local')->get($coveragePath))->toBe('existing declaration');
})->with(['local', 'testing']);

test('noninteractive reset requires force', function () {
    $sentinel = User::factory()->create();
    $this->mock(RealCatalogImportService::class, function ($mock) {
        $mock->shouldReceive('inspectFiles')->once()->andReturn(array_fill(0, 654, []));
        $mock->shouldNotReceive('execute');
    });

    $this->artisan('battlefront:reset-dev', ['--no-interaction' => true])
        ->expectsOutputToContain('Reset cancelled')
        ->assertFailed();

    $this->assertModelExists($sentinel);
});

test('invalid restore inputs preserve the existing database', function (string $problem) {
    $publicDisk = Storage::fake('reset-dev-'.getmypid().'-'.bin2hex(random_bytes(6)), ['throw' => true]);
    Storage::set('public', $publicDisk);
    $sentinel = User::factory()->create();
    $manifest = tempnam(sys_get_temp_dir(), 'reset-manifest-');
    $mapping = tempnam(sys_get_temp_dir(), 'reset-mapping-');
    $imagePath = 'products/graphics-card/00123.webp';
    $bytes = UploadedFile::fake()->image('00123.webp', 1024, 1024)->getContent();
    expect($publicDisk->put($imagePath, $bytes))->toBeTrue();
    $publicDisk->assertExists($imagePath, $bytes);
    file_put_contents($manifest, json_encode(['version' => 1, 'entries' => [[
        'product_code' => '00123',
        'name' => 'Test GPU',
        'category' => 'Graphics Card',
        'category_slug' => 'graphics-card',
        'quantity' => '5',
        'price' => '7500',
        'image_path' => $imagePath,
        'status' => 'complete',
        'image_sha256' => hash('sha256', $bytes),
        'image_bytes' => strlen($bytes),
    ]]], JSON_THROW_ON_ERROR));
    file_put_contents($mapping, "product_code,product_name,category,brand,quantity_override,reorder_level\n00123,Test GPU,Graphics Card,Biostar,,2\n");
    $options = ['--force' => true, '--manifest' => $manifest, '--mapping' => $mapping];

    try {
        expect(app(RealCatalogImportService::class)->inspectFiles($mapping, $manifest))->toHaveCount(1);

        match ($problem) {
            'missing manifest' => $options['--manifest'] = $manifest.'.missing',
            'missing mapping' => $options['--mapping'] = $mapping.'.missing',
            'invalid manifest' => file_put_contents($manifest, '{'),
            'invalid mapping' => file_put_contents($mapping, 'wrong,columns'),
            'missing image' => Storage::disk('public')->delete('products/graphics-card/00123.webp'),
            'changed image' => Storage::disk('public')->put('products/graphics-card/00123.webp', 'invalid'),
            'wrong count' => null,
        };

        $this->artisan('battlefront:reset-dev', $options)
            ->expectsOutputToContain('Catalog preflight failed')
            ->expectsOutputToContain('No database records were changed')
            ->assertFailed();

        $this->assertModelExists($sentinel);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
})->with(['missing manifest', 'missing mapping', 'invalid manifest', 'invalid mapping', 'missing image', 'changed image', 'wrong count']);

test('reset stops when an orchestration stage fails', function (string $stage) {
    $sentinel = User::factory()->create();
    $this->mock(RealCatalogImportService::class, function ($mock) use ($stage) {
        $mock->shouldReceive('inspectFiles')->once()->andReturn(array_fill(0, 654, []));
        if ($stage === 'import') {
            $mock->shouldReceive('execute')->once()->andThrow(new RuntimeException('Import failed.'));
        } elseif ($stage === 'count') {
            $mock->shouldReceive('execute')->once()->andReturn(['created' => 653, 'updated' => 0]);
        } elseif ($stage === 'pc-builder') {
            $mock->shouldReceive('execute')->once()->andReturn(['created' => 654, 'updated' => 0]);
        } else {
            $mock->shouldNotReceive('execute');
        }
    });
    $command = Mockery::mock(ResetDevCommand::class)->makePartial();
    $command->setName('battlefront:reset-dev');
    $command->setDefinition((new ResetDevCommand)->getDefinition());
    $command->shouldReceive('call')->with('migrate:fresh', Mockery::any())->once()->andReturn($stage === 'migration' ? 1 : 0);
    if ($stage !== 'migration') {
        $command->shouldReceive('call')->with('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true, '--no-interaction' => true])->once()->andReturn($stage === 'seeding' ? 1 : 0);
    }
    if (! in_array($stage, ['migration', 'seeding'], true)) {
        $command->shouldReceive('call')->with('db:seed', ['--class' => DevelopmentCustomerSeeder::class, '--force' => true, '--no-interaction' => true])->once()->andReturn($stage === 'customers' ? 1 : 0);
    }
    if ($stage === 'pc-builder') {
        $command->shouldReceive('call')->with('db:seed', ['--class' => PcBuilderCatalogDraftSeeder::class, '--force' => true, '--no-interaction' => true])->once()->andReturn(1);
    } else {
        $command->shouldNotReceive('call')->with('db:seed', ['--class' => PcBuilderCatalogDraftSeeder::class, '--force' => true, '--no-interaction' => true]);
    }
    $command->shouldNotReceive('call')->with('db:seed', ['--class' => DevelopmentHistoricalSalesSeeder::class, '--force' => true, '--no-interaction' => true]);
    Artisan::registerCommand($command);

    $this->artisan('battlefront:reset-dev', ['--force' => true])
        ->expectsOutputToContain($stage === 'pc-builder' ? 'PC Builder draft seeding failed' : 'failed:')
        ->expectsOutputToContain('Reset incomplete')
        ->doesntExpectOutput('Development reset completed.')
        ->assertFailed();

    $this->assertModelExists($sentinel);
})->with(['migration', 'seeding', 'customers', 'import', 'count', 'pc-builder']);

test('reset prepares component drafts after catalog import and before optional history', function (bool $withHistory) {
    $this->mock(RealCatalogImportService::class, function ($mock) {
        $mock->shouldReceive('inspectFiles')->once()->andReturn(array_pad(array_map(fn (string $code): array => ['product_code' => $code], DevelopmentHistoricalSalesSeeder::PRODUCT_CODES), 654, []));
        $mock->shouldReceive('execute')->once()->andReturnUsing(function (): array {
            expect(ProductComponentProfile::query()->count())->toBe(0);
            Product::factory()->available()->for(Category::factory()->create(['name' => 'Motherboard']))->create([
                'product_code' => '62504865', 'name' => 'MSI B450M-A PRO MAX II',
            ]);

            return ['created' => 654, 'updated' => 0];
        });
    });
    $command = Mockery::mock(ResetDevCommand::class)->makePartial();
    $command->setName('battlefront:reset-dev');
    $command->setDefinition((new ResetDevCommand)->getDefinition());
    $command->shouldReceive('call')->with('migrate:fresh', Mockery::any())->once()->andReturn(0);
    foreach ([DatabaseSeeder::class, DevelopmentCustomerSeeder::class] as $seeder) {
        $command->shouldReceive('call')->with('db:seed', ['--class' => $seeder, '--force' => true, '--no-interaction' => true])->once()->andReturn(0);
    }
    if ($withHistory) {
        $command->shouldReceive('call')->with('db:seed', ['--class' => DevelopmentHistoricalSalesSeeder::class, '--force' => true, '--no-interaction' => true])->once()->andReturnUsing(function (): int {
            expect(ProductComponentProfile::query()->sole()->review_status)->toBe('draft');

            return 0;
        });
    } else {
        $command->shouldNotReceive('call')->with('db:seed', ['--class' => DevelopmentHistoricalSalesSeeder::class, '--force' => true, '--no-interaction' => true]);
    }
    Artisan::registerCommand($command);

    $this->artisan('battlefront:reset-dev', ['--force' => true, '--with-sales-history' => $withHistory])
        ->expectsOutput('PC Builder catalog drafts seeded. Review specifications before publishing predefined builds.')
        ->assertSuccessful();

    $profile = ProductComponentProfile::query()->sole();
    expect($profile->review_status)->toBe('draft');
    expect($profile->revisions()->sole()->review_status)->toBe('draft');
    $this->get(route('pc-builder.index'))->assertInertia(fn (Assert $page) => $page
        ->where('components.motherboard.0.id', $profile->product_id)
        ->where('components.motherboard.0.specifications_reviewed', false)
        ->where('components.motherboard.0.specifications', [])
        ->has('templates', 0));
})->with([false, true]);

test('reset restores the complete real catalog with historical sales only when requested', function (bool $withHistory) {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    $manifestPath = storage_path('app/private/product-catalog-images/manifest.json');
    $mappingPath = storage_path('app/imports/product_catalog/verified-product-details.csv');
    if (! is_file($manifestPath) || ! is_file($mappingPath)) {
        $this->markTestSkipped('The private catalog data is unavailable.');
    }
    $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    foreach ($manifest['entries'] as $entry) {
        if (! Storage::disk('public')->exists($entry['image_path'])) {
            $this->markTestSkipped('The private catalog images are unavailable.');
        }
    }

    $originalConnection = DB::getDefaultConnection();
    config(['database.connections.reset_test' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('reset_test');

    try {
        $expected = app(RealCatalogImportService::class)->inspectFiles($mappingPath, $manifestPath);
        expect($expected)->toHaveCount(654);
        expect(DB::connection()->getSchemaBuilder()->hasTable('products'))->toBeFalse();
        Artisan::call('migrate', ['--force' => true]);
        $transient = Product::factory()->create(['product_code' => $expected[0]['product_code']]);
        $transient->inventory()->create(['quantity' => 999, 'reorder_level' => 999]);
        OrderItem::factory()->for($transient)->create();
        CartItem::factory()->for($transient)->create();
        Sale::factory()->create();
        $filesBefore = collect(Storage::disk('public')->allFiles('products'))
            ->mapWithKeys(fn ($path) => [$path => hash_file('sha256', Storage::disk('public')->path($path))])->all();
        $sourcesBefore = [hash_file('sha256', $manifestPath), hash_file('sha256', $mappingPath)];

        $coveragePath = config('forecasting.development_manifest');
        Storage::disk('local')->put($coveragePath, 'old declaration');
        $this->artisan('battlefront:reset-dev', ['--force' => true, '--with-sales-history' => $withHistory])
            ->expectsOutput('Database recreated.')
            ->expectsOutput('Seeders completed.')
            ->expectsOutput('654 products imported.')
            ->expectsOutput('Development reset completed.')
            ->assertSuccessful();

        $products = Product::with(['inventory', 'category', 'tags'])->where('is_catalog_imported', true)->get()->keyBy('product_code');
        expect($products)->toHaveCount(654);
        $this->assertDatabaseCount('products', 654);
        $this->assertDatabaseCount('inventories', 654);
        $this->assertDatabaseCount('product_component_profiles', 14);
        expect(ProductComponentProfile::query()->where('review_status', '!=', 'draft')->count())->toBe(0);
        $this->assertDatabaseCount('pc_build_templates', 6);
        $this->assertDatabaseCount('pc_build_template_items', 0);
        foreach ($expected as $row) {
            $product = $products->get($row['product_code']);
            expect($product)->not->toBeNull();
            expect($product->is_catalog_imported)->toBeTrue();
            expect($product->name)->toBe($row['name']);
            expect($product->inventory->quantity)->toBe($row['quantity']);
            expect($product->inventory->reorder_level)->toBe($row['reorder_level']);
            expect($product->category->name)->toBe($row['category']);
            expect($product->tags->pluck('name')->sort()->values()->all())->toBe(collect($row['tags'])->sort()->values()->all());
            expect($product->image_path)->toBe($row['image_path']);
            expect($product->is_active)->toBeTrue();
            expect($product->category->is_active)->toBeTrue();
        }
        expect($products->filter(fn ($product) => $product->inventory->quantity > $product->inventory->reorder_level))->toHaveCount(650);
        expect($products->filter(fn ($product) => $product->inventory->quantity > 0 && $product->inventory->quantity <= $product->inventory->reorder_level))->toHaveCount(3);
        expect($products->filter(fn ($product) => $product->inventory->quantity === 0))->toHaveCount(1);
        expect(Product::query()->customerAvailable()->count())->toBe(653);
        foreach (['80520996', '10044', '6940056198471'] as $code) {
            expect($products[$code]->lowStockInventory()->exists())->toBeTrue();
            $this->get(route('products.show', $products[$code]))->assertInertia(fn (Assert $page) => $page
                ->where('product.inventory.status', 'low_stock'));
            $this->get(route('api.v1.products.show', $products[$code]))->assertJsonPath('data.inventory.status', 'low_stock');
        }
        $soldOut = $products['301501828'];
        $this->get(route('products.show', $soldOut))->assertNotFound();
        $this->get(route('api.v1.products.show', $soldOut))->assertNotFound();
        $filesAfter = collect(Storage::disk('public')->allFiles('products'))
            ->mapWithKeys(fn ($path) => [$path => hash_file('sha256', Storage::disk('public')->path($path))])->all();
        expect($filesAfter)->toBe($filesBefore);
        expect([hash_file('sha256', $manifestPath), hash_file('sha256', $mappingPath)])->toBe($sourcesBefore);
        foreach (['orders' => 259, 'order_items' => 1505, 'sales' => 259, 'shipments' => 74, 'carts' => 0, 'cart_items' => 0] as $table => $historicalCount) {
            $this->assertDatabaseCount($table, $withHistory ? $historicalCount : 0);
        }
        if ($withHistory) {
            $sales = Sale::query()->orderBy('sale_date')->get();
            expect($sales->first()->sale_date->format('Y-m'))->toBe('2022-10');
            expect($sales->last()->sale_date->format('Y-m'))->toBe('2026-09');
            $manifest = json_decode(Storage::disk('local')->get($coveragePath), true, flags: JSON_THROW_ON_ERROR);
            expect($manifest['products'])->toHaveCount(15);
            expect($manifest['products']['10003']['end_exclusive'])->toBe('2026-10-01');
            $selected = Product::whereIn('product_code', DevelopmentHistoricalSalesSeeder::PRODUCT_CODES)->get();
            expect($selected)->toHaveCount(15);
            foreach ($selected as $product) {
                $readiness = app(ProductForecastPreparationService::class)->prepareMonthly($product);
                expect($readiness['status'])->toBe('ready');
                expect($readiness['positive_sales_months'])->toBe([12, 12, 12]);
            }
        } else {
            Storage::disk('local')->assertMissing($coveragePath);
        }
        $this->assertDatabaseCount('users', 35);
        expect(User::query()->where('email', 'like', 'demo.customer%@example.test')->count())->toBe(30);
        expect(User::query()->where('email', 'historical-sales@example.test')->exists())->toBeFalse();
        expect(Product::query()->where('is_catalog_imported', false)->count())->toBe(0);
        expect(Product::where('product_code', 'like', 'DEVHIST40%')->exists())->toBeFalse();
        $this->assertDatabaseHas('users', ['email' => 'admin@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
        $this->assertDatabaseHas('branches', ['city' => 'Sagay City']);
        $this->assertDatabaseCount('chatbot_knowledge', 9);
        expect(ChatbotKnowledge::query()->where('category', '!=', ChatbotCategory::Faq->value)->exists())->toBeFalse();
        expect(ChatbotKnowledge::query()->where('is_active', false)->exists())->toBeFalse();

        $stockBefore = $products->map(fn ($product) => [$product->inventory->quantity, $product->inventory->reorder_level])->all();
        if ($withHistory) {
            $inventoryBefore = DB::table('inventories')->orderBy('id')->get()->all();
            $this->seed(DevelopmentHistoricalSalesSeeder::class);
            expect(DB::table('inventories')->orderBy('id')->get()->all())->toEqual($inventoryBefore);
        }
        $owner = User::query()->where('email', 'admin@example.com')->sole();
        expect($owner->administrationRoleCode())->toBe('owner');
        $this->actingAs($owner)->get(route('administration.inventory.index'))->assertOk();

        $manager = User::query()->where('email', 'manager@example.com')->sole();
        $staff = User::query()->where('email', 'staff@example.com')->sole();
        expect($manager->administrationRoleCode())->toBe('manager');
        expect($staff->administrationRoleCode())->toBe('staff');
        $it = User::query()->where('email', 'it@example.com')->sole();
        expect($it->administrationRoleCode())->toBe('it');
        $this->actingAs($it)->get(route('administration.inventory.index'))->assertForbidden();
        $dashboard = app(DashboardService::class)->administration($manager);
        expect($dashboard['kpis']['low_stock_products'])->toBe(3);
        expect($dashboard['kpis']['out_of_stock_products'])->toBe(1);

        $this->actingAs($manager)->get(route('administration.inventory.index', ['stock' => 'low_stock']))
            ->assertInertia(fn (Assert $page) => $page->where('products.total', 3));
        $this->get(route('administration.inventory.index', ['stock' => 'out_of_stock']))
            ->assertInertia(fn (Assert $page) => $page->where('products.total', 1));
        $reorderLevelBefore = $soldOut->inventory->reorder_level;
        $this->actingAs($staff)->patch(route('administration.inventory.update', $soldOut->inventory), ['quantity' => 6, 'reorder_level' => $reorderLevelBefore, 'reason_code' => 'found_stock', 'reason' => 'Found stock during reset check'])
            ->assertRedirect()->assertSessionHasNoErrors();

        expect($soldOut->inventory->refresh()->quantity)->toBe(0);
        expect(Product::query()->customerAvailable()->whereKey($soldOut->id)->exists())->toBeFalse();
        $adjustment = DB::table('inventory_adjustment_requests')->where('product_id', $soldOut->id)->sole();
        expect($adjustment->status)->toBe('pending');
        expect((int) $adjustment->requester_id)->toBe($staff->id);

        $this->actingAs($manager)->post(route('administration.inventory-adjustments.decide', $adjustment->id), [
            'decision' => 'approve', 'reason' => 'Found stock independently verified',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventory_adjustment_requests', [
            'id' => $adjustment->id, 'status' => 'approved', 'reviewer_id' => $manager->id,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $soldOut->id, 'actor_id' => $manager->id,
            'reference_type' => 'inventory_adjustment_request', 'reference_id' => $adjustment->id,
            'kind' => 'approved_adjustment', 'sellable_before' => 0, 'sellable_delta' => 6, 'sellable_after' => 6,
        ]);
        expect($soldOut->inventory->refresh()->quantity)->toBe(6);
        expect($soldOut->inventory->reorder_level)->toBe($reorderLevelBefore);

        $this->patch(route('administration.inventory.reorder-level.update', $soldOut->inventory), [
            'reorder_level' => 5, 'reason' => 'Reorder threshold adjusted during reset check',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $soldOut->inventory->refresh();
        expect($soldOut->inventory->reorder_level)->toBe(5);
        expect(Product::query()->customerAvailable()->whereKey($soldOut->id)->exists())->toBeTrue();
        expect($soldOut->refresh()->is_active)->toBeTrue();
        expect($soldOut->category->is_active)->toBeTrue();

        $this->artisan('battlefront:reset-dev', ['--force' => true, '--with-sales-history' => $withHistory])->assertSuccessful();
        $stockAfter = Product::query()->where('is_catalog_imported', true)->with('inventory')->get()->keyBy('product_code')
            ->map(fn ($product) => [$product->inventory->quantity, $product->inventory->reorder_level])->all();
        expect($stockAfter)->toBe($stockBefore);
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('reset_test');
    }
})->with([false, true]);

test('reset reports optional historical seeding failure after a successful catalog import', function () {
    $this->mock(RealCatalogImportService::class, function ($mock) {
        $mock->shouldReceive('inspectFiles')->once()->andReturn(array_pad(array_map(fn (string $code): array => ['product_code' => $code], DevelopmentHistoricalSalesSeeder::PRODUCT_CODES), 654, []));
        $mock->shouldReceive('execute')->once()->andReturn(['created' => 654, 'updated' => 0]);
    });
    $command = Mockery::mock(ResetDevCommand::class)->makePartial();
    $command->setName('battlefront:reset-dev');
    $command->setDefinition((new ResetDevCommand)->getDefinition());
    $command->shouldReceive('call')->with('migrate:fresh', Mockery::any())->once()->andReturn(0);
    $command->shouldReceive('call')->with('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true, '--no-interaction' => true])->once()->andReturn(0);
    $command->shouldReceive('call')->with('db:seed', ['--class' => DevelopmentCustomerSeeder::class, '--force' => true, '--no-interaction' => true])->once()->andReturn(0);
    $command->shouldReceive('call')->with('db:seed', ['--class' => PcBuilderCatalogDraftSeeder::class, '--force' => true, '--no-interaction' => true])->once()->andReturn(0);
    $command->shouldReceive('call')->with('db:seed', ['--class' => DevelopmentHistoricalSalesSeeder::class, '--force' => true, '--no-interaction' => true])->once()->andReturn(1);
    Artisan::registerCommand($command);

    $this->artisan('battlefront:reset-dev', ['--force' => true, '--with-sales-history' => true])
        ->expectsOutput('654 products imported.')
        ->expectsOutputToContain('Synthetic historical sales seeding failed')
        ->expectsOutputToContain('Reset incomplete')
        ->doesntExpectOutput('Development reset completed.')
        ->assertFailed();
});

test('history product selection is validated before database recreation', function () {
    $sentinel = User::factory()->create();
    Storage::disk('local')->put(config('forecasting.development_manifest'), 'existing declaration');
    $this->mock(RealCatalogImportService::class, function ($mock) {
        $mock->shouldReceive('inspectFiles')->once()->andReturn(array_fill(0, 654, ['product_code' => 'UNSELECTED']));
        $mock->shouldNotReceive('execute');
    });

    $this->artisan('battlefront:reset-dev', ['--force' => true, '--with-sales-history' => true])
        ->expectsOutputToContain('Historical sales requires selected catalog product codes')
        ->expectsOutputToContain('No database records were changed')
        ->assertFailed();

    $this->assertModelExists($sentinel);
    expect(Storage::disk('local')->get(config('forecasting.development_manifest')))->toBe('existing declaration');
});

test('reset seeds thirty demonstration customers with either history option without running database recreation', function (bool $withHistory) {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    HistoricalSalesCatalogFixtures::createProducts();
    $this->mock(RealCatalogImportService::class, function ($mock) {
        $mock->shouldReceive('inspectFiles')->once()->andReturn(array_pad(array_map(fn (string $code): array => ['product_code' => $code], DevelopmentHistoricalSalesSeeder::PRODUCT_CODES), 654, []));
        $mock->shouldReceive('execute')->once()->andReturn(['created' => 654, 'updated' => 0]);
    });
    $command = Mockery::mock(ResetDevCommand::class)->makePartial();
    $command->setName('battlefront:reset-dev');
    $command->setDefinition((new ResetDevCommand)->getDefinition());
    $command->shouldReceive('call')->with('migrate:fresh', Mockery::any())->once()->andReturn(0);
    Artisan::registerCommand($command);

    $result = Artisan::call('battlefront:reset-dev', ['--force' => true, '--with-sales-history' => $withHistory]);

    expect($result)->toBe(0);
    expect(Artisan::output())->toContain('30 demonstration customer accounts seeded.', 'Development reset completed.')
        ->not->toContain('customer123');
    $this->assertDatabaseCount('users', 35);
    expect(User::query()->where('email', 'like', 'demo.customer%@example.test')->count())->toBe(30);
    expect(User::query()->where('email', 'historical-sales@example.test')->exists())->toBeFalse();
    $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'role' => 'administrator']);
    $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'role' => 'customer']);
    foreach (['admin' => 'owner', 'staff' => 'staff', 'manager' => 'manager', 'it' => 'it'] as $email => $role) {
        expect(User::query()->where('email', $email.'@example.com')->sole()->administrationRoleCode())->toBe($role);
    }
    expect(User::query()->where('email', 'like', 'demo.customer%@example.test')->whereHas('orders')->count())->toBe($withHistory ? 30 : 0);
})->with([false, true]);
