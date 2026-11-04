<?php

Schedule::command('chatbot:prune-memory')->daily()->withoutOverlapping()
    ->when(fn (): bool => (bool) config('ai_integration.memory.enabled'));
Schedule::command('chatbot:failures --prune')->hourly()->withoutOverlapping();
Schedule::command('chatbot:reconcile-catalog')->hourly()->withoutOverlapping()
    ->when(fn (): bool => (bool) config('ai_integration.sync_enabled'));

use App\Console\Commands\PruneExpiredCustomerSearches;
use App\Console\Commands\PruneExpiredRecommendationInteractions;
use App\Console\Commands\PruneWishlistActivities;
use App\Console\Commands\ReconcilePayMongoOrders;
use App\Jobs\ProcessPayMongoWebhook;
use App\Models\PayMongoWebhookInbox;
use App\Services\CatalogImagePipeline;
use App\Services\RealCatalogImportService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command(PruneExpiredCustomerSearches::class)->daily();
Schedule::command(PruneExpiredRecommendationInteractions::class)->daily();
Schedule::command(PruneWishlistActivities::class)->daily();
Schedule::call(static function (): void {
    PayMongoWebhookInbox::query()
        ->whereIn('status', ['received', 'unmatched'])
        ->where(function ($query): void {
            $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', now());
        })
        ->orderBy('next_check_at')->orderBy('id')
        ->limit(100)
        ->pluck('id')
        ->each(static function (int $id): void {
            PayMongoWebhookInbox::query()->whereKey($id)->update(['next_check_at' => now()->addMinute()]);
            ProcessPayMongoWebhook::dispatch($id)->onQueue('payments');
        });
})->name('paymongo-webhook-inbox-recovery')->everyMinute()->withoutOverlapping();
Schedule::command(ReconcilePayMongoOrders::class)
    ->name('paymongo-overdue-order-reconciliation')
    ->everyMinute()
    ->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('catalog-images:next {code?} {--manifest=}', function (CatalogImagePipeline $pipeline) {
    $code = $this->argument('code');
    $manifest = $this->option('manifest');
    if (($code !== null && ! is_string($code)) || ($manifest !== null && ! is_string($manifest))) {
        $this->error('The code and manifest option must be text values.');

        return 1;
    }

    $entry = $pipeline->next($code, $manifest);
    if ($entry === null) {
        $this->warn('No matching pending catalog image job was found.');

        return 1;
    }

    $this->line("Code: {$entry['product_code']}");
    $this->line("Source: {$entry['source_file']} row {$entry['source_row']}");
    $this->line("Reference: {$entry['reference_image']}");
    $this->line("Status: {$entry['status']}");
    $this->newLine();
    $this->line($entry['prompt']);

    return 0;
})->purpose('Show the next product-specific image prompt');

Artisan::command('catalog-images:ingest {code} {source} {--approved} {--manifest=}', function (CatalogImagePipeline $pipeline) {
    if (! $this->option('approved')) {
        $this->error('Review the generated image, then pass --approved to accept it.');

        return 1;
    }

    $code = $this->argument('code');
    $source = $this->argument('source');
    $manifest = $this->option('manifest');
    if (! is_string($code) || ! is_string($source) || ($manifest !== null && ! is_string($manifest))) {
        $this->error('The code, source, and manifest option must be text values.');

        return 1;
    }

    $path = $pipeline->ingest($code, $source, $manifest);
    $this->info("Saved $path");

    return 0;
})->purpose('Accept and optimize one reviewed product image');

Artisan::command('catalog-images:fail {code} {reason} {--manifest=}', function (CatalogImagePipeline $pipeline) {
    $code = $this->argument('code');
    $reason = $this->argument('reason');
    $manifest = $this->option('manifest');
    if (! is_string($code) || ! is_string($reason) || ($manifest !== null && ! is_string($manifest))) {
        $this->error('The code, reason, and manifest option must be text values.');

        return 1;
    }

    $pipeline->fail($code, $reason, $manifest);
    $this->warn("Recorded failed generation for $code.");

    return 0;
})->purpose('Record a failed product image generation for later retry');

Artisan::command('catalog-images:audit {--manifest=}', function (CatalogImagePipeline $pipeline) {
    $manifest = $this->option('manifest');
    if ($manifest !== null && ! is_string($manifest)) {
        $this->error('The manifest option must be a text path.');

        return 1;
    }

    $result = $pipeline->audit($manifest);
    $this->line("Total: {$result['total']}; complete: {$result['complete']}; pending: {$result['pending']}; failed: {$result['failed']}");
    foreach ($result['issues'] as $issue) {
        $this->error($issue);
    }

    return $result['pending'] === 0 && $result['failed'] === 0 && $result['issues'] === [] ? 0 : 1;
})->purpose('Detect missing, changed, duplicate, or unmapped catalog images');

Artisan::command('catalog:import-real {mapping} {--manifest=} {--apply}', function (RealCatalogImportService $importer) {
    $mapping = $this->argument('mapping');
    $manifest = $this->option('manifest');
    if (! is_string($mapping) || ($manifest !== null && ! is_string($manifest))) {
        $this->error('The mapping and manifest option must be text paths.');

        return 1;
    }

    if (! $this->option('apply')) {
        $rows = $importer->inspect($mapping, $manifest);
        $this->info('Validated '.count($rows).' catalog rows and image paths. No product data was changed.');

        return 0;
    }

    $result = $importer->execute($mapping, $manifest);
    $this->info("Imported {$result['created']} new products and updated {$result['updated']} existing products.");

    return 0;
})->purpose('Validate or apply the real catalog using verified product details');

Artisan::command('catalog:details-template {output} {--manifest=}', function (RealCatalogImportService $importer) {
    $output = $this->argument('output');
    $manifest = $this->option('manifest');
    if (! is_string($output) || ($manifest !== null && ! is_string($manifest))) {
        $this->error('The output and manifest option must be text paths.');

        return 1;
    }

    $rows = $importer->writeTemplate($output, $manifest);
    $this->info("Prepared $rows verified-details rows at $output.");

    return 0;
})->purpose('Create a code-keyed template for verified brand and inventory details');
