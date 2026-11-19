<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

test('negative retrieval labels measure false positives without diluting positive recall', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('negatives.json', json_encode(['catalog_version' => 'fixture-v1', 'cases' => [
        ['id' => 'positive', 'relevant_product_ids' => [1], 'algolia' => [1], 'qdrant' => range(1, 10)],
        ['id' => 'negative', 'expected_no_match' => true, 'relevant_product_ids' => [], 'algolia' => [], 'qdrant' => [2]],
    ]]));
    expect(Artisan::call('chatbot:evaluate-retrieval', ['--dataset' => $disk->path('negatives.json')]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['summary']['qdrant']['recall_at_10'])->toBe(1);
    expect($report['summary']['qdrant']['false_positive_rate'])->toBe(1);
    expect($report['summary']['algolia']['false_positive_rate'])->toBe(0);
    expect($report['metrics_version'])->toBe(2);
    expect($report['summary']['algolia'])->toMatchArray(['precision_at_10' => 0.1, 'precision_among_returned' => 1]);
    expect($report['summary']['qdrant'])->toMatchArray(['precision_at_10' => 0.1, 'precision_among_returned' => 0.1]);
    expect($report['results'][1]['metrics']['qdrant'])->toMatchArray(['precision_at_10' => null, 'precision_among_returned' => null]);
    expect($report['candidate_depths'][20]['rrf']['false_positive_rate'])->toBe(1);
    Http::assertNothingSent();
});

test('retrieval depth ablations measure truncated candidate inputs without cloud requests', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('depths.json', json_encode(['catalog_version' => 'fixture-v1', 'cases' => [[
        'id' => 'depth', 'relevant_product_ids' => [20], 'algolia' => range(1, 20), 'qdrant' => [20],
    ]]]));
    expect(Artisan::call('chatbot:evaluate-retrieval', ['--dataset' => $disk->path('depths.json')]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['candidate_depths'])->toHaveKeys([10, 20, 40]);
    expect($report['candidate_depths'][10]['qdrant']['recall_at_10'])->toBe(1);
    expect($report['candidate_depths'][20]['rrf']['recall_at_10'])->toBe(1);
    Http::assertNothingSent();
});

test('retrieval evaluation compares labeled sources and RRF without provider requests', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('retrieval.json', json_encode(['catalog_version' => 'fixture-v1', 'cases' => [
        ['id' => 'discovery', 'relevant_product_ids' => [1, 3], 'algolia' => [1, 2], 'qdrant' => [2, 3, 1]],
        ['id' => 'miss', 'relevant_product_ids' => [4], 'algolia' => [], 'qdrant' => []],
    ]]));

    expect(Artisan::call('chatbot:evaluate-retrieval', ['--dataset' => $disk->path('retrieval.json')]))->toBe(0);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['summary']['algolia']['recall_at_10'])->toBe(0.25);
    expect($report['summary']['qdrant']['recall_at_10'])->toBe(0.5);
    expect($report['summary']['qdrant']['mrr_at_10'])->toBe(0.25);
    expect($report['summary']['rrf']['zero_result_rate'])->toBe(0.5);
    expect($report['results'][0]['metrics']['rrf']['top_ids'])->toBe([2, 1, 3]);
    expect($report['live_quality_verified'])->toBeFalse();
    Http::assertNothingSent();
});

test('retrieval evaluation does not count relevant products below rank ten', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('retrieval.json', json_encode(['catalog_version' => 'fixture-v1', 'cases' => [
        ['id' => 'deep-hit', 'relevant_product_ids' => [11], 'algolia' => range(1, 11), 'qdrant' => []],
    ]]));

    expect(Artisan::call('chatbot:evaluate-retrieval', ['--dataset' => $disk->path('retrieval.json')]))->toBe(0);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['summary']['algolia']['recall_at_10'])->toBe(0);
    expect($report['summary']['algolia']['mrr_at_10'])->toBe(0);
    expect($report['summary']['algolia']['zero_result_rate'])->toBe(0);
    expect($report['results'][0]['metrics']['algolia'])->toMatchArray(['precision_at_10' => 0, 'precision_among_returned' => 0]);
    expect($report['results'][0]['metrics']['qdrant'])->toMatchArray(['precision_at_10' => 0, 'precision_among_returned' => 0]);
    Http::assertNothingSent();
});

test('retrieval evaluation rejects ambiguous or invalid labels', function (array $case) {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('retrieval.json', json_encode(['catalog_version' => 'fixture-v1', 'cases' => [$case]]));

    expect(Artisan::call('chatbot:evaluate-retrieval', ['--dataset' => $disk->path('retrieval.json')]))->toBe(1);
    Http::assertNothingSent();
})->with([
    'duplicate candidates' => [['id' => 'dup', 'relevant_product_ids' => [1], 'algolia' => [1, 1], 'qdrant' => []]],
    'no relevance judgment' => [['id' => 'empty', 'relevant_product_ids' => [], 'algolia' => [], 'qdrant' => []]],
    'string IDs' => [['id' => 'strings', 'relevant_product_ids' => ['1'], 'algolia' => [1], 'qdrant' => []]],
    'unexpected private field' => [['id' => 'private', 'relevant_product_ids' => [1], 'algolia' => [], 'qdrant' => [], 'customer' => 'private']],
]);
