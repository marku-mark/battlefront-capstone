<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

test('explicit model comparisons require the same pipeline and versioned catalog', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $report = ['dataset_hash' => 'dataset', 'configured_model' => 'gemini-baseline', 'mode' => 'live', 'pipeline_version' => 'pipeline', 'catalog_version' => 'public-fixture-v1', 'catalog_fingerprint' => str_repeat('a', 64), 'catalog_stable_before_after' => true, 'results' => [
        ['id' => 'price', 'run' => 1, 'language' => 'en', 'answer_hash' => str_repeat('a', 64), 'turns' => [['message' => 'Reply']]],
    ]];
    $disk->put('baseline.json', json_encode($report));
    $disk->put('candidate.json', json_encode([...$report, 'configured_model' => 'gemini-candidate']));
    $arguments = ['baseline' => $disk->path('baseline.json'), 'candidate' => $disk->path('candidate.json')];
    expect(Artisan::call('chatbot:compare-replies', $arguments))->toBe(1);
    expect(Artisan::call('chatbot:compare-replies', [...$arguments, '--compare-models' => true]))->toBe(0);
    $disk->put('candidate.json', json_encode([...$report, 'configured_model' => 'gemini-candidate', 'catalog_version' => 'different']));
    expect(Artisan::call('chatbot:compare-replies', [...$arguments, '--compare-models' => true]))->toBe(1);
    Http::assertNothingSent();
});

test('reply comparisons keep blinded answers separate and bind judgments to exact pairs', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $report = ['dataset_hash' => 'dataset', 'configured_model' => 'model', 'mode' => 'live', 'results' => [
        ['id' => 'en-price', 'run' => 1, 'language' => 'en', 'answer_hash' => str_repeat('a', 64),
            'turns' => [['message' => 'Baseline reply', 'source' => 'gemini', 'presentation' => ['intro' => 'Baseline reply']]]],
    ]];
    $disk->put('baseline.json', json_encode($report));
    $report['results'][0]['answer_hash'] = str_repeat('b', 64);
    $report['results'][0]['turns'][0]['message'] = 'Candidate reply';
    $disk->put('candidate.json', json_encode($report));

    expect(Artisan::call('chatbot:compare-replies', ['baseline' => $disk->path('baseline.json'), 'candidate' => $disk->path('candidate.json')]))->toBe(0);

    $comparison = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $pairs = json_decode(file_get_contents($comparison['blinded_file']), true, flags: JSON_THROW_ON_ERROR);
    $key = json_decode(file_get_contents($comparison['private_key_file']), true, flags: JSON_THROW_ON_ERROR);
    expect($comparison['human_comparison_passed'])->toBeFalse();
    expect($pairs[0])->not->toHaveKey('candidate');
    expect($pairs[0]['answers']['a'][0])->not->toHaveKey('source');
    $disk->put('reviews.json', json_encode(['en-price:1' => ['pair_hash' => $pairs[0]['pair_hash'],
        'winner' => $key['en-price:1']['candidate'], 'critical_failure_a' => false, 'critical_failure_b' => false]]));

    Artisan::call('chatbot:compare-replies', ['baseline' => $disk->path('baseline.json'), 'candidate' => $disk->path('candidate.json'), '--reviews' => $disk->path('reviews.json')]);

    $reviewed = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($reviewed)->toMatchArray(['human_comparison_passed' => true, 'candidate_win_or_tie_rate' => 1, 'release_gate_passed' => false]);
    $disk->put('critical.json', json_encode(['en-price:1' => ['pair_hash' => $pairs[0]['pair_hash'],
        'winner' => 'tie', 'critical_failure_a' => true, 'critical_failure_b' => true]]));

    Artisan::call('chatbot:compare-replies', ['baseline' => $disk->path('baseline.json'), 'candidate' => $disk->path('candidate.json'), '--reviews' => $disk->path('critical.json')]);

    $critical = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($critical['human_comparison_passed'])->toBeFalse();
    expect($critical['summary']['en']['critical_failures'])->toBe(1);
    $report['results'][0]['answer_hash'] = str_repeat('c', 64);
    $disk->put('candidate.json', json_encode($report));

    Artisan::call('chatbot:compare-replies', ['baseline' => $disk->path('baseline.json'), 'candidate' => $disk->path('candidate.json'), '--reviews' => $disk->path('reviews.json')]);

    $stale = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($stale['summary']['en']['reviewed'])->toBe(0);
    Http::assertNothingSent();
});

test('reply comparison refuses mismatched reports', function (string $change) {
    $disk = $this->fakeIsolatedStorage('local');
    $report = ['dataset_hash' => 'dataset', 'configured_model' => 'model', 'mode' => 'live', 'results' => [
        ['id' => 'price', 'run' => 1, 'language' => 'en', 'answer_hash' => str_repeat('a', 64), 'turns' => [['message' => 'Reply']]],
    ]];
    $disk->put('baseline.json', json_encode($report));
    if ($change === 'duplicate') {
        $report['results'][] = $report['results'][0];
    } elseif ($change === 'case') {
        $report['results'][0]['id'] = 'different';
    } else {
        $report[$change] = 'different';
    }
    $disk->put('candidate.json', json_encode($report));

    expect(Artisan::call('chatbot:compare-replies', ['baseline' => $disk->path('baseline.json'), 'candidate' => $disk->path('candidate.json')]))->toBe(1);

    Http::assertNothingSent();
})->with(['dataset_hash', 'configured_model', 'configuration', 'mode', 'case', 'duplicate']);

test('reply comparisons reject malformed report files', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('broken.json', '{broken');

    expect(Artisan::call('chatbot:compare-replies', ['baseline' => $disk->path('broken.json'), 'candidate' => $disk->path('missing.json')]))->toBe(1);

    Http::assertNothingSent();
});
