<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function vectorIndexMetadata(bool $complete = false, bool $compatible = true): array
{
    $fields = ['category_id' => ['data_type' => 'integer'], 'brand' => ['data_type' => 'keyword'], 'component_type' => ['data_type' => 'keyword']];
    if ($complete) {
        $fields['product_family'] = ['data_type' => 'keyword'];
        $fields['product_role'] = ['data_type' => 'keyword'];
    }

    return ['result' => ['config' => ['params' => ['vectors' => ['size' => $compatible ? 768 : 1536, 'distance' => 'Cosine']]], 'payload_schema' => $fields]];
}

test('primary-index inspection is read only without explicit apply', function () {
    config(['ai_integration.qdrant.enabled' => true, 'ai_integration.qdrant.url' => 'https://vector.test', 'ai_integration.qdrant.key' => 'testing-only']);
    Http::fake(['https://vector.test/*' => Http::response(vectorIndexMetadata())]);
    expect(Artisan::call('chatbot:initialize-catalog', ['--missing-primary-indexes-only' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['applied'])->toBeFalse();
    expect($report['readiness']['missing_indexes'])->toBe(['product_family', 'product_role']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->method() === 'GET');
});

test('explicit repair adds only missing payload indexes and verifies readiness', function () {
    config(['ai_integration.qdrant.enabled' => true, 'ai_integration.qdrant.url' => 'https://vector.test', 'ai_integration.qdrant.key' => 'testing-only']);
    Http::fakeSequence()->push(vectorIndexMetadata())->push(['status' => 'ok'])->push(['status' => 'ok'])->push(vectorIndexMetadata(true));
    expect(Artisan::call('chatbot:initialize-catalog', ['--missing-primary-indexes-only' => true, '--apply' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report)->toMatchArray(['created_indexes' => ['product_family', 'product_role'], 'changed_vectors' => false, 'changed_graph' => false]);
    expect($report['readiness']['ready'])->toBeTrue();
    Http::assertSentCount(4);
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/index?wait=true') && $request['field_name'] === 'product_role' && $request['field_schema'] === 'keyword');
});

test('primary-index repair refuses incompatible vector dimensions without writes', function () {
    config(['ai_integration.qdrant.enabled' => true, 'ai_integration.qdrant.url' => 'https://vector.test', 'ai_integration.qdrant.key' => 'testing-only']);
    Http::fake(['https://vector.test/*' => Http::response(vectorIndexMetadata(compatible: false))]);
    expect(Artisan::call('chatbot:initialize-catalog', ['--missing-primary-indexes-only' => true, '--apply' => true]))->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->method() === 'GET');
});

test('explicit cloud filter probes use one bounded synthetic query and never invoke embeddings', function () {
    config(['ai_integration.qdrant.enabled' => true, 'ai_integration.qdrant.url' => 'https://vector.test', 'ai_integration.qdrant.key' => 'testing-only']);
    Http::fakeSequence()->push(vectorIndexMetadata(true))->push(['result' => ['points' => []]]);
    expect(Artisan::call('chatbot:diagnostics', ['--cloud' => true, '--probe-filters' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['checks']['primary']['filter_probe_passed'])->toBeTrue();
    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request['limit'] === 1 && count($request['query']) === 768
        && $request['filter']['must'][0]['key'] === 'product_family');
});
