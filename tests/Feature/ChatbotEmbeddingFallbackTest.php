<?php

use App\Enums\ChatbotCategory;
use App\Jobs\SyncCatalogProjection;
use App\Jobs\SyncEmbeddingFallback;
use App\Jobs\SyncFaqProjection;
use App\Models\CatalogProjection;
use App\Models\Category;
use App\Models\ChatbotKnowledge;
use App\Models\Product;
use App\Services\Chatbot\ChatbotCatalogSelection;
use App\Services\Chatbot\ChatbotFailureTrace;
use App\Services\Chatbot\Retrieval\CatalogDocumentBuilder;
use App\Services\Chatbot\Retrieval\EmbeddingBudgetExhausted;
use App\Services\Chatbot\Retrieval\EmbeddingFallback;
use App\Services\Chatbot\Retrieval\FaqDocumentBuilder;
use App\Services\Chatbot\Retrieval\GeminiEmbeddingClient;
use App\Services\Chatbot\SemanticCatalogCandidates;
use App\Services\Chatbot\SemanticFaqCandidates;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['ai_integration.enabled' => true, 'ai_integration.qdrant.enabled' => true,
        'ai_integration.qdrant.url' => 'https://vector.example.test', 'ai_integration.qdrant.key' => 'fake',
        'ai_integration.embedding.enforce_project_limits' => false,
        'ai_integration.embedding.fallback_profiles' => [['model' => 'gemini-embedding-2', 'collection' => 'products_fallback', 'faq_collection' => 'faq_fallback', 'ready' => true, 'faq_ready' => true]]]);
    Http::swap(new Factory);
    Http::preventStrayRequests();
});

test('embedding limits use the paired collection and reject stale or mismatched vector evidence', function (string $mode) {
    $product = Product::factory()->available()->for(Category::factory()->state(['name' => 'Storage']))->create(['name' => 'Atlas SSD']);
    CatalogProjection::query()->create(['product_id' => $product->id, 'revision' => 2, 'qdrant_revision' => 0]);
    $hash = app(CatalogDocumentBuilder::class)->build($product, 'gemini-embedding-2')['hash'];
    $payload = ['revision' => 2, 'embedding_model' => 'gemini-embedding-2', 'embedding_dimensions' => 768, 'source_hash' => $hash];
    if ($mode === 'wrong_model') {
        $payload['embedding_model'] = 'gemini-embedding-001';
    } elseif ($mode === 'wrong_dimensions') {
        $payload['embedding_dimensions'] = 3072;
    } elseif ($mode === 'stale_hash') {
        $payload['source_hash'] = 'stale';
    } elseif ($mode === 'stale_revision') {
        $payload['revision'] = 1;
    }
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent' => Http::response([], 429, ['Retry-After' => '60']),
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:embedContent' => Http::response(['embedding' => ['values' => array_fill(0, 768, 1.0)]]),
        'https://vector.example.test/collections/products_fallback/points/query' => Http::response(['result' => ['points' => [['id' => $product->id, 'score' => 0.95, 'payload' => $payload]]]]),
    ]);

    $ids = app(SemanticCatalogCandidates::class)->ids('Atlas SSD');

    expect($ids)->toBe($mode === 'current' ? [$product->id] : []);
    Http::assertSentCount(3);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'gemini-embedding-2:')
        && str_starts_with($request['content']['parts'][0]['text'], 'task: search result | query: ')
        && ! isset($request['taskType']));
})->with(['current', 'wrong_model', 'wrong_dimensions', 'stale_hash', 'stale_revision']);

test('fallback projections write model specific documents without acknowledging the primary index', function () {
    $product = Product::factory()->available()->create();
    CatalogProjection::query()->create(['product_id' => $product->id, 'revision' => 2, 'qdrant_revision' => 0]);
    config(['ai_integration.sync_enabled' => true]);
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:embedContent' => Http::response(['embedding' => ['values' => array_fill(0, 768, 1.0)]]),
        'https://vector.example.test/collections/products_fallback/points?wait=true' => Http::response(['result' => ['status' => 'completed']]),
    ]);

    app()->call([new SyncEmbeddingFallback($product->id, false, 'gemini-embedding-2', 'products_fallback'), 'handle']);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'products_fallback/points?')
        && $request['points'][0]['payload']['embedding_model'] === 'gemini-embedding-2'
        && $request['points'][0]['payload']['source_hash'] === app(CatalogDocumentBuilder::class)->build($product, 'gemini-embedding-2')['hash']);
    expect(CatalogProjection::query()->where('product_id', $product->id)->value('qdrant_revision'))->toBe(0);
});

test('profiles cannot point a different embedding model at a primary collection', function () {
    config(['ai_integration.embedding.fallback_profiles' => [['model' => 'gemini-embedding-2', 'collection' => config('ai_integration.qdrant.collection')]]]);

    expect(app(EmbeddingFallback::class)->profiles())->toBe([]);
    Http::assertNothingSent();
});

test('an unverified fallback collection can be populated but cannot serve retrieval yet', function () {
    config(['ai_integration.embedding.fallback_profiles' => [['model' => 'gemini-embedding-2', 'collection' => 'products_fallback', 'ready' => false]]]);
    $profiles = app(EmbeddingFallback::class);

    expect($profiles->profiles())->toHaveCount(1);
    expect($profiles->profiles(readyOnly: true))->toBe([]);
    expect(fn () => $profiles->search('public SSD query'))->toThrow(RuntimeException::class, 'No compatible embedding fallback');
    Http::assertNothingSent();
});

test('provider daily limits are respected until reset without repeated embedding requests', function () {
    $this->travelTo(now()->setTimezone('America/Los_Angeles')->startOfDay()->addHours(12));
    Http::fake(['https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:embedContent' => Http::response([
        'error' => ['details' => [['violations' => [['quotaId' => 'EmbedContentRequestsPerDayPerProjectFreeTier']]]]],
    ], 429)]);
    $failure = null;
    try {
        app(GeminiEmbeddingClient::class)->embed('Public catalog text', true, 'gemini-embedding-2');
    } catch (EmbeddingBudgetExhausted $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(EmbeddingBudgetExhausted::class);
    expect($failure->reason)->toBe('provider_daily_limit');
    expect($failure->retryAfter)->toBeGreaterThan(3600);
    expect(fn () => app(GeminiEmbeddingClient::class)->embed('Another public document', true, 'gemini-embedding-2'))->toThrow(EmbeddingBudgetExhausted::class);
    Http::assertSentCount(1);
});

test('embedding cooldown preserves integer remaining time and resumes after expiry', function () {
    $this->travelTo(now()->startOfSecond());
    Http::fake(['https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:embedContent' => Http::sequence()
        ->push([], 429, ['Retry-After' => '77'])
        ->push(['embedding' => ['values' => array_fill(0, 768, 1.0)]])]);

    foreach ([77, 47] as $remaining) {
        try {
            app(GeminiEmbeddingClient::class)->embed('Public catalog cooldown test', true, 'gemini-embedding-2');
            $this->fail('The provider cooldown must be respected.');
        } catch (EmbeddingBudgetExhausted $exception) {
            expect($exception->retryAfter)->toBe($remaining);
            expect($exception->reason)->toBe('provider_rate_limit');
        }
        if ($remaining === 77) {
            $this->travel(30)->seconds();
        }
    }
    Http::assertSentCount(1);

    $this->travel(48)->seconds();
    expect(app(GeminiEmbeddingClient::class)->embed('Public catalog cooldown test', true, 'gemini-embedding-2'))->toHaveCount(768);
    Http::assertSentCount(2);
});

test('fallback reconciliation previews without dispatching jobs or contacting providers', function () {
    Queue::fake();

    $this->artisan('chatbot:reconcile-embedding-fallbacks')->assertSuccessful();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('FAQ embedding limits route only to the matching FAQ fallback collection', function () {
    config(['ai_integration.faq.enabled' => true]);
    $record = ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => 'What is your warranty policy?', 'response_template' => 'Bring the item and receipt for assessment.']);
    $hash = app(FaqDocumentBuilder::class)->hash($record);
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent' => Http::response([], 429),
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:embedContent' => Http::response(['embedding' => ['values' => array_fill(0, 768, 1.0)]]),
        'https://vector.example.test/collections/faq_fallback/points/query' => Http::response(['result' => ['points' => [[
            'id' => $record->id * 10, 'score' => 0.95, 'payload' => ['faq_id' => $record->id, 'language' => 'en', 'source_hash' => $hash, 'embedding_model' => 'gemini-embedding-2', 'embedding_dimensions' => 768],
        ]]]]),
    ]);

    expect(app(SemanticFaqCandidates::class)->ids('What is your warranty policy?'))->toBe([$record->id]);
    Http::assertSentCount(3);
});

test('FAQ fallback jobs publish reviewed source metadata in the alternate index', function () {
    $record = ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => 'What is your warranty policy?', 'response_template' => 'Bring the item and receipt for assessment.']);
    config(['ai_integration.sync_enabled' => true, 'ai_integration.faq.enabled' => true]);
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:embedContent' => Http::response(['embedding' => ['values' => array_fill(0, 768, 1.0)]]),
        'https://vector.example.test/collections/faq_fallback/*' => Http::response(['result' => ['status' => 'completed']]),
    ]);

    app()->call([new SyncEmbeddingFallback($record->id, true, 'gemini-embedding-2', 'faq_fallback'), 'handle']);

    Http::assertSentCount(5);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'faq_fallback/points?')
        && $request['points'][0]['payload']['faq_id'] === $record->id
        && $request['points'][0]['payload']['source_hash'] === app(FaqDocumentBuilder::class)->hash($record));
});

test('FAQ source edits queue derived maintenance without enabling semantic FAQ replies', function () {
    $record = ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => 'What is your warranty policy?']);
    config(['ai_integration.sync_enabled' => true, 'ai_integration.faq.enabled' => false]);
    Queue::fake([SyncFaqProjection::class]);

    $record->update(['response_template' => 'Bring the item and receipt for assessment.']);

    Queue::assertPushed(SyncFaqProjection::class, fn ($job): bool => $job->knowledgeId === $record->id);
    expect(config('ai_integration.faq.enabled'))->toBeFalse();
    Http::assertNothingSent();
});

test('catalog synchronization queues fallback work independently of an already current primary index', function () {
    $product = Product::factory()->available()->create();
    CatalogProjection::query()->create(['product_id' => $product->id, 'revision' => 2, 'qdrant_revision' => 2]);
    config(['ai_integration.sync_enabled' => true, 'ai_integration.neo4j.enabled' => false]);
    Queue::fake([SyncEmbeddingFallback::class]);

    app()->call([new SyncCatalogProjection($product->id), 'handle']);

    Queue::assertPushed(SyncEmbeddingFallback::class, fn ($job): bool => $job->id === $product->id && $job->model === 'gemini-embedding-2' && $job->collection === 'products_fallback');
    Http::assertNothingSent();
});

test('fallback backfill queues public projection identifiers only after explicit apply', function () {
    $product = Product::factory()->available()->create();
    CatalogProjection::query()->create(['product_id' => $product->id, 'revision' => 1]);
    config(['ai_integration.sync_enabled' => true]);
    Queue::fake([SyncEmbeddingFallback::class]);

    $this->artisan('chatbot:reconcile-embedding-fallbacks', ['--apply' => true])->assertSuccessful();

    Queue::assertPushed(SyncEmbeddingFallback::class, 1);
    Http::assertNothingSent();
});

test('inline backfill stops at provider limits with a resumable checkpoint and no queued work', function () {
    $first = Product::factory()->available()->create();
    $second = Product::factory()->available()->create();
    foreach ([$first, $second] as $product) {
        CatalogProjection::query()->create(['product_id' => $product->id, 'revision' => 1]);
    }
    config(['ai_integration.sync_enabled' => true]);
    Queue::fake();
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:embedContent' => Http::sequence()
            ->push(['embedding' => ['values' => array_fill(0, 768, 1.0)]])
            ->push([], 429, ['Retry-After' => '77']),
        'https://vector.example.test/collections/products_fallback/points?wait=true' => Http::response(['result' => ['status' => 'completed']]),
    ]);

    $this->artisan('chatbot:reconcile-embedding-fallbacks', ['--apply' => true, '--inline' => true, '--after-faq' => (string) PHP_INT_MAX])
        ->expectsOutputToContain('retry after 77 seconds')
        ->expectsOutputToContain('--after-product='.$first->id)
        ->assertExitCode(2);

    Http::assertSentCount(3);
    Queue::assertNothingPushed();
});

test('fallback only initialization leaves primary collections and the graph untouched', function () {
    config(['ai_integration.neo4j.enabled' => true, 'ai_integration.faq.enabled' => false]);
    $handler = fn ($request) => $request->method() === 'GET' ? Http::response([], 404) : Http::response(['result' => ['status' => 'completed']]);
    Http::fake([
        'https://vector.example.test/collections/products_fallback*' => $handler,
        'https://vector.example.test/collections/faq_fallback*' => $handler,
    ]);

    $this->artisan('chatbot:initialize-catalog', ['--apply' => true, '--fallbacks-only' => true])->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'products_fallback/index') && $request['field_name'] === 'category_id');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'faq_fallback/index') && $request['field_name'] === 'faq_id');
});

test('inline backfill obeys disjoint product range boundaries', function () {
    $products = Product::factory()->available()->count(3)->create();
    foreach ($products as $product) {
        CatalogProjection::query()->create(['product_id' => $product->id, 'revision' => 1]);
    }
    config(['ai_integration.sync_enabled' => true]);
    Queue::fake();
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:embedContent' => Http::response(['embedding' => ['values' => array_fill(0, 768, 1.0)]]),
        'https://vector.example.test/collections/products_fallback/points?wait=true' => Http::response(['result' => ['status' => 'completed']]),
    ]);

    $this->artisan('chatbot:reconcile-embedding-fallbacks', ['--apply' => true, '--inline' => true,
        '--after-product' => $products[0]->id, '--through-product' => $products[1]->id, '--after-faq' => (string) PHP_INT_MAX])->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'products_fallback/points?') && $request['points'][0]['id'] === $products[1]->id);
    Queue::assertNothingPushed();
});

test('payload audit distinguishes current, missing and stale points without fetching vectors', function (string $mode) {
    $product = Product::factory()->available()->create();
    CatalogProjection::query()->create(['product_id' => $product->id, 'revision' => 2]);
    config(['ai_integration.embedding.fallback_profiles' => [['model' => 'gemini-embedding-2', 'collection' => 'products_fallback']]]);
    $payload = ['product_id' => $product->id, 'revision' => 2, 'embedding_model' => 'gemini-embedding-2', 'embedding_dimensions' => 768,
        'source_hash' => $mode === 'stale' ? 'stale' : app(CatalogDocumentBuilder::class)->build($product, 'gemini-embedding-2')['hash']];
    Http::fake(['https://vector.example.test/collections/products_fallback/points' => Http::response(['result' => $mode === 'missing' ? [] : [['id' => $product->id, 'payload' => $payload]]])]);

    expect(Artisan::call('chatbot:audit-embedding-fallbacks'))->toBe($mode === 'current' ? 0 : 1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['payloads_current'])->toBe($mode === 'current');
    expect($report['semantic_quality_verified'])->toBeFalse();
    expect($report['profiles'][0][$mode])->toBe(1);
    Http::assertSent(fn ($request): bool => $request['with_vector'] === false);
    Http::assertSentCount(1);
})->with(['current', 'missing', 'stale']);

test('payload readiness includes eligible products that have no projection', function (bool $withCurrentProjection) {
    $product = Product::factory()->available()->create();
    config(['ai_integration.embedding.fallback_profiles' => [['model' => 'gemini-embedding-2', 'collection' => 'products_fallback']]]);
    if ($withCurrentProjection) {
        $current = Product::factory()->available()->create();
        CatalogProjection::query()->create(['product_id' => $current->id, 'revision' => 1]);
        $payload = ['product_id' => $current->id, 'revision' => 1, 'embedding_model' => 'gemini-embedding-2', 'embedding_dimensions' => 768,
            'source_hash' => app(CatalogDocumentBuilder::class)->build($current, 'gemini-embedding-2')['hash']];
        Http::fake(['https://vector.example.test/collections/products_fallback/points' => Http::response(['result' => [['id' => $current->id, 'payload' => $payload]]])]);
    }

    expect(Artisan::call('chatbot:audit-embedding-fallbacks'))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['payloads_current'])->toBeFalse();
    expect($report['profiles'][0])->toMatchArray(['eligible_products' => $withCurrentProjection ? 2 : 1,
        'missing_projections' => 1, 'current' => $withCurrentProjection ? 1 : 0, 'missing' => 0, 'stale' => 0]);
    expect(CatalogProjection::query()->where('product_id', $product->id)->exists())->toBeFalse();
    if ($withCurrentProjection) {
        Http::assertSentCount(1);
    } else {
        Http::assertNothingSent();
    }
})->with(['empty projection table' => false, 'otherwise current index' => true]);

test('malformed vector results record a failure while retaining authoritative local catalog matches', function () {
    $product = Product::factory()->available()->for(Category::factory()->state(['name' => 'Storage']))
        ->create(['name' => 'Atlas SSD', 'price' => '2000.00', 'discount_price' => null]);
    config(['ai_integration.qdrant.collection' => 'products']);
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent' => Http::response(['embedding' => ['values' => array_fill(0, 768, 1.0)]]),
        'https://vector.example.test/collections/products/points/query' => Http::response(['result' => ['unexpected' => []]]),
    ]);

    $context = app(ChatbotCatalogSelection::class)->select('Recommend SSD for editing', [
        'intent' => 'recommendation', 'category_key' => 'storage', 'search_query' => 'SSD',
        'product_mentions' => [], 'reference' => 'new', 'position' => 0, 'maximum_php' => null,
        'excluded_brands' => [], 'brand' => '', 'capacity' => '', 'use_case' => 'editing',
    ]);

    expect(array_column($context['products'], 'id'))->toBe([$product->id]);
    expect($context['products'][0]['price'])->toBe('2000.00');
    expect(app(ChatbotFailureTrace::class)->retrievalFailures)->toBe([
        ['provider' => 'qdrant', 'reason' => 'invalid_response', 'http_status' => null],
    ]);
    Http::assertSentCount(2);
});
