<?php

use App\Jobs\SyncCatalogProjection;
use App\Models\CatalogProjection;
use App\Models\Product;
use App\Services\Chatbot\Retrieval\CatalogDocumentBuilder;
use App\Services\Chatbot\Retrieval\CatalogProjectionRecorder;
use App\Services\Chatbot\Retrieval\GeminiEmbeddingClient;
use App\Services\Chatbot\Retrieval\Neo4jClient;
use App\Services\Chatbot\Retrieval\QdrantClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('defers quota constrained projections without provider requests or failed attempts', function (bool $daily) {
    $this->travelTo(now()->setTimezone('America/Los_Angeles')->startOfDay()->addHours(12));
    $product = Product::factory()->create();
    config(['ai_integration.sync_enabled' => true, 'ai_integration.qdrant.enabled' => true, 'ai_integration.embedding.key' => 'fake', 'ai_integration.embedding.requests_per_minute' => $daily ? 5 : 0, 'ai_integration.embedding.requests_per_day' => $daily ? 0 : 100]);
    CatalogProjection::query()->create(['product_id' => $product->id, 'revision' => 1]);
    Queue::fake([SyncCatalogProjection::class]);
    $pacific = now()->setTimezone('America/Los_Angeles');
    $delay = $daily ? max(60, (int) ceil($pacific->diffInSeconds($pacific->copy()->addDay()->startOfDay())) + 5) : 60;

    app()->call([new SyncCatalogProjection($product->id), 'handle']);

    $projection = CatalogProjection::query()->where('product_id', $product->id)->sole();
    expect($projection->last_failure)->toBe('budget_deferred');
    expect($projection->qdrant_revision)->toBe(0);
    Queue::assertPushed(SyncCatalogProjection::class, fn ($job) => $job->productId === $product->id && $job->delay >= $delay && $job->delay <= $delay + 30);
    Http::assertNothingSent();
})->with([false, true]);

it('never connects when retrieval is disabled', function () {
    config(['ai_integration.qdrant.enabled' => false]);
    expect(fn () => app(QdrantClient::class)->search([1.0]))->toThrow(RuntimeException::class, 'Qdrant unavailable.');
    Http::assertNothingSent();
});

it('bounds vector queries and accepts only revisioned candidates', function () {
    config(['ai_integration.qdrant.enabled' => true, 'ai_integration.qdrant.url' => 'https://vector.example.test', 'ai_integration.qdrant.key' => 'fake', 'ai_integration.qdrant.collection' => 'products']);
    Http::fake(['https://vector.example.test/collections/products/points/query' => Http::response(['result' => ['points' => [['id' => 3, 'score' => 0.9, 'payload' => ['revision' => 4]], ['id' => 9]]]])]);
    expect(app(QdrantClient::class)->search([1.0], 100))->toBe([['product_id' => 3, 'revision' => 4, 'score' => 0.9]]);
    Http::assertSent(fn ($request) => $request['limit'] === 20 && $request->hasHeader('api-key', 'fake'));
});

it('does not expose provider failures or credentials', function () {
    config(['ai_integration.qdrant.enabled' => true, 'ai_integration.qdrant.url' => 'https://vector.example.test', 'ai_integration.qdrant.key' => 'fake', 'ai_integration.qdrant.collection' => 'products']);
    Http::fake(['https://vector.example.test/collections/products/points/query' => Http::response(['error' => 'sensitive response'], 401)]);
    expect(fn () => app(QdrantClient::class)->search([1.0]))->toThrow(RuntimeException::class, 'Qdrant unavailable.');
    Http::assertSentCount(1);
});

it('rejects Bolt addresses before sending graph credentials', function () {
    config(['ai_integration.neo4j.enabled' => true, 'ai_integration.neo4j.url' => 'neo4j+s://graph.example.test']);
    expect(fn () => app(Neo4jClient::class)->candidates([1]))->toThrow(RuntimeException::class, 'Graph unavailable.');
    Http::assertNothingSent();
});

it('initializes graph constraints with JSON object parameters', function () {
    config(['ai_integration.neo4j.enabled' => true, 'ai_integration.neo4j.url' => 'https://graph.example.test', 'ai_integration.neo4j.username' => 'test', 'ai_integration.neo4j.password' => 'fake', 'ai_integration.neo4j.database' => 'neo4j']);
    Http::fake(['https://graph.example.test/db/neo4j/query/v2' => Http::response(['data' => ['values' => []]], 202)]);
    app(Neo4jClient::class)->initialize();
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request->body(), '"parameters":{}'));
});

it('records monotonic projection revisions and retains deletion tombstones', function () {
    config(['ai_integration.sync_enabled' => true]);
    Queue::fake([SyncCatalogProjection::class]);
    $recorder = app(CatalogProjectionRecorder::class);
    $recorder->record(123);
    $recorder->record(123);
    expect(CatalogProjection::query()->where('product_id', 123)->sole()->revision)->toBe(2);
    Queue::assertPushed(SyncCatalogProjection::class, 2);
});

it('excludes private commercial fields from public catalog documents', function () {
    $product = Product::factory()->create(['price' => 123.45, 'acquisition_cost' => 67.89, 'description' => '<b>Public description</b>']);
    $document = app(CatalogDocumentBuilder::class)->build($product);
    expect($document)->not->toHaveKeys(['price', 'acquisition_cost', 'inventory']);
    expect($document['text'])->toContain('Public description')->not->toContain('<b>')->not->toContain('123.45')->not->toContain('67.89');
});

it('retracts inactive products and makes duplicate deliveries no ops', function () {
    $product = Product::factory()->create(['is_active' => false]);
    config(['ai_integration.sync_enabled' => true, 'ai_integration.qdrant.enabled' => true, 'ai_integration.qdrant.url' => 'https://vector.example.test', 'ai_integration.qdrant.key' => 'fake', 'ai_integration.qdrant.collection' => 'products', 'ai_integration.neo4j.enabled' => false]);
    CatalogProjection::query()->create(['product_id' => $product->id, 'revision' => 2]);
    Http::fake(['https://vector.example.test/collections/products/points/delete?wait=true' => Http::response(['result' => ['status' => 'completed']])]);
    app()->call([new SyncCatalogProjection($product->id), 'handle']);
    app()->call([new SyncCatalogProjection($product->id), 'handle']);
    expect(CatalogProjection::query()->where('product_id', $product->id)->sole()->qdrant_revision)->toBe(2);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['points'] === [$product->id]);
});

it('keeps failed projections pending for reconciliation', function () {
    $product = Product::factory()->create(['is_active' => false]);
    config(['ai_integration.sync_enabled' => true, 'ai_integration.qdrant.enabled' => true, 'ai_integration.qdrant.url' => 'https://vector.example.test', 'ai_integration.qdrant.key' => 'fake', 'ai_integration.qdrant.collection' => 'products']);
    CatalogProjection::query()->create(['product_id' => $product->id, 'revision' => 2]);
    Http::fake(['https://vector.example.test/collections/products/points/delete?wait=true' => Http::response([], 503)]);
    expect(fn () => app()->call([new SyncCatalogProjection($product->id), 'handle']))->toThrow(RuntimeException::class);
    expect(CatalogProjection::query()->where('product_id', $product->id)->sole()->qdrant_revision)->toBe(0);
    Http::assertSentCount(1);
});

it('parameterizes graph facts and rejects successful HTTP responses with query errors', function () {
    config(['ai_integration.neo4j.enabled' => true, 'ai_integration.neo4j.url' => 'https://graph.example.test', 'ai_integration.neo4j.username' => 'test', 'ai_integration.neo4j.password' => 'fake', 'ai_integration.neo4j.database' => 'neo4j']);
    Http::fake(['https://graph.example.test/db/neo4j/query/v2' => Http::response(['errors' => [['message' => 'sensitive']]])]);
    expect(fn () => app(Neo4jClient::class)->project(1, 2, ['name' => "quote'", 'specifications' => ['socket' => 'AM5']]))->toThrow(RuntimeException::class, 'Graph unavailable.');
    Http::assertSent(fn ($request) => json_decode($request->body(), true)['parameters']['name'] === "quote'" && ! str_contains($request['statement'], "quote'"));
});

it('normalizes reduced dimension embeddings and sends paired retrieval task types', function () {
    config(['ai_integration.qdrant.enabled' => true, 'ai_integration.embedding.key' => 'fake', 'ai_integration.embedding.model' => 'gemini-embedding-001', 'ai_integration.embedding.dimensions' => 768, 'ai_integration.gemini.project_budget_key' => 'embedding-test']);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent' => Http::response(['embedding' => ['values' => array_merge([3, 4], array_fill(0, 766, 0))]])]);
    $vector = app(GeminiEmbeddingClient::class)->embed('Public product', true);
    expect($vector)->toHaveCount(768);
    expect($vector[0])->toBe(0.6);
    expect($vector[1])->toBe(0.8);
    Http::assertSent(fn ($request) => $request['taskType'] === 'RETRIEVAL_DOCUMENT' && $request['outputDimensionality'] === 768 && $request->hasHeader('x-goog-api-key', 'fake'));
});

it('rejects malformed embedding dimensions without indexing', function () {
    config(['ai_integration.qdrant.enabled' => true, 'ai_integration.embedding.key' => 'fake', 'ai_integration.gemini.project_budget_key' => 'embedding-invalid-test']);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent' => Http::response(['embedding' => ['values' => [1, 2]]])]);
    expect(fn () => app(GeminiEmbeddingClient::class)->embed('Public product'))->toThrow(RuntimeException::class, 'Embedding unavailable.');
    Http::assertSentCount(1);
});

it('does not acknowledge a newer revision that arrives during delivery', function () {
    $product = Product::factory()->create(['is_active' => false]);
    config(['ai_integration.sync_enabled' => true, 'ai_integration.qdrant.enabled' => true, 'ai_integration.qdrant.url' => 'https://vector.example.test', 'ai_integration.qdrant.key' => 'fake', 'ai_integration.qdrant.collection' => 'products', 'ai_integration.neo4j.enabled' => false]);
    CatalogProjection::query()->create(['product_id' => $product->id, 'revision' => 2]);
    Http::fake(['https://vector.example.test/collections/products/points/delete?wait=true' => function () use ($product) {
        CatalogProjection::query()->where('product_id', $product->id)->increment('revision');

        return Http::response(['result' => ['status' => 'completed']]);
    }]);
    app()->call([new SyncCatalogProjection($product->id), 'handle']);
    $projection = CatalogProjection::query()->where('product_id', $product->id)->sole();
    expect($projection->revision)->toBe(3);
    expect($projection->qdrant_revision)->toBe(0);
    Http::assertSentCount(1);
});

it('reconciles missed document changes but skips current projections', function () {
    $product = Product::factory()->create();
    $hash = app(CatalogDocumentBuilder::class)->build($product)['hash'];
    CatalogProjection::query()->create(['product_id' => $product->id, 'revision' => 1, 'qdrant_revision' => 1, 'content_hash' => $hash]);
    config(['ai_integration.sync_enabled' => true, 'ai_integration.qdrant.enabled' => true, 'ai_integration.neo4j.enabled' => false]);
    Queue::fake([SyncCatalogProjection::class]);
    $this->artisan('chatbot:reconcile-catalog')->assertSuccessful();
    Queue::assertNothingPushed();
    Product::query()->whereKey($product->id)->update(['description' => 'Changed through a bulk import']);
    $this->artisan('chatbot:reconcile-catalog')->assertSuccessful();
    Queue::assertPushed(SyncCatalogProjection::class, 1);
    expect(CatalogProjection::query()->where('product_id', $product->id)->sole()->revision)->toBe(2);
});

it('previews cloud setup without any network request', function () {
    $this->artisan('chatbot:initialize-catalog')->assertSuccessful();
    Http::assertNothingSent();
});

it('refuses to overwrite an incompatible vector collection', function () {
    config(['ai_integration.qdrant.enabled' => true, 'ai_integration.qdrant.url' => 'https://vector.example.test', 'ai_integration.qdrant.key' => 'fake', 'ai_integration.qdrant.collection' => 'products', 'ai_integration.embedding.dimensions' => 768]);
    Http::fake(['https://vector.example.test/collections/products' => Http::response(['result' => ['config' => ['params' => ['vectors' => ['size' => 1536, 'distance' => 'Cosine']]]]])]);
    expect(fn () => app(QdrantClient::class)->initialize())->toThrow(RuntimeException::class, 'Existing collection does not match');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->body() === '');
});

it('retains successful vector delivery when the graph fails and retries only the graph', function () {
    $product = Product::factory()->create(['is_active' => false]);
    config(['ai_integration.sync_enabled' => true, 'ai_integration.qdrant.enabled' => true, 'ai_integration.qdrant.url' => 'https://vector.example.test', 'ai_integration.qdrant.key' => 'fake', 'ai_integration.qdrant.collection' => 'products', 'ai_integration.neo4j.enabled' => true, 'ai_integration.neo4j.url' => 'https://graph.example.test', 'ai_integration.neo4j.username' => 'test', 'ai_integration.neo4j.password' => 'fake', 'ai_integration.neo4j.database' => 'neo4j']);
    CatalogProjection::query()->create(['product_id' => $product->id, 'revision' => 2]);
    Http::fake([
        'https://vector.example.test/collections/products/points/delete?wait=true' => Http::response(['result' => ['status' => 'completed']]),
        'https://graph.example.test/db/neo4j/query/v2' => Http::sequence()->push([], 503)->push(['data' => ['values' => []]]),
    ]);
    expect(fn () => app()->call([new SyncCatalogProjection($product->id), 'handle']))->toThrow(RuntimeException::class);
    $projection = CatalogProjection::query()->where('product_id', $product->id)->sole();
    expect($projection->qdrant_revision)->toBe(2);
    expect($projection->neo4j_revision)->toBe(0);
    expect($projection->last_failure)->toBe('provider_unavailable');
    app()->call([new SyncCatalogProjection($product->id), 'handle']);
    $projection->refresh();
    expect($projection->neo4j_revision)->toBe(2);
    expect($projection->last_failure)->toBeNull();
    Http::assertSentCount(3);
});
