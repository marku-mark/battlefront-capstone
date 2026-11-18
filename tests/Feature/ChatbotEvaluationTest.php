<?php

use App\Ai\Agents\ChatbotEvidenceAgent;
use App\Ai\Agents\ChatbotIntentAgent;
use App\Ai\Agents\ChatbotResponseAgent;
use App\Ai\Agents\ChatbotValidationAgent;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ChatbotEvaluationMetrics;
use App\Services\Chatbot\ChatbotFailureTrace;
use App\Services\Chatbot\ChatbotProviderBudget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

test('evaluation writes an unapproved hash-bound fluent-speaker review packet', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('packet-cases.json', json_encode([['id' => 'greeting', 'language' => 'en', 'messages' => ['Hello']]]));
    expect(Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('packet-cases.json'), '--prepare-reviews' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $packet = json_decode(file_get_contents($report['review_packet']), true, flags: JSON_THROW_ON_ERROR);
    expect($packet['greeting:1']['answer_hash'])->toBe($report['results'][0]['answer_hash']);
    expect($packet['greeting:1']['fluent_speaker'])->toBeNull();
    expect($packet['greeting:1']['grounded'])->toBeNull();
    expect($report['summary']['en']['fluent_reviewed'])->toBe(0);
    expect($report['summary']['en']['accuracy_gate_passed'])->toBeFalse();
    Http::assertNothingSent();
});

test('live evaluation rejects oversized planned runs before invoking providers', function () {
    config(['ai_integration.enabled' => true]);
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('bounded.json', json_encode([
        ['id' => 'one', 'language' => 'en', 'messages' => array_fill(0, 6, 'Hello')],
        ['id' => 'two', 'language' => 'en', 'messages' => array_fill(0, 6, 'Hello')],
    ]));
    expect(Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('bounded.json'), '--live' => true]))->toBe(1);
    expect(Artisan::output())->toContain('text-call budget');
    expect(config('ai_integration.enabled'))->toBeTrue();
    Http::assertNothingSent();
});

test('attribute evaluation uses captured model understanding instead of keyword interpretation', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('attributes.json', json_encode([['id' => 'attributes', 'language' => 'en', 'messages' => ['What is its price?'], 'expected_attributes' => ['details']]]));
    $this->mock(ChatbotConversation::class, function ($mock) {
        $mock->shouldReceive('respond')->once()->andReturnUsing(function (): array {
            app(ChatbotFailureTrace::class)->reset();
            app(ChatbotFailureTrace::class)->understanding = ['attributes' => ['specifications']];

            return ['message' => 'Verified details.', 'language' => 'en', 'source' => 'fallback', 'context_token' => null, 'presentation' => []];
        });
    });
    expect(Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('attributes.json')]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['results'][0]['checks']['attributes'])->toBeTrue();
    expect($report['results'][0]['turns'][0]['attribute_source'])->toBe('model');
});

test('live evaluation pacing is bounded and can be faked without changing provider limits', function () {
    Sleep::fake();
    config(['ai_integration.enabled' => true]);
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('paced.json', json_encode([['id' => 'greetings', 'language' => 'en', 'messages' => ['Hello', 'Hello']]]));
    expect(Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('paced.json'), '--live' => true, '--pause-ms' => 10000]))->toBe(0);
    Sleep::assertSleptTimes(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['live_pause_ms'])->toBe(10000);
    expect(config('ai_integration.evaluation'))->toBeNull();
    expect(Artisan::call('chatbot:evaluate', ['--pause-ms' => 30001]))->toBe(1);
    Http::assertNothingSent();
});

test('evaluation keeps stage evidence without polluting operational counters', function () {
    config(['ai_integration.evaluation' => true]);
    $budget = app(ChatbotProviderBudget::class);
    $budget->recordStage('qdrant', 'transport', 15);
    $budget->recordRoleUsage('scope', 10, 20);
    $budget->recordTurn(15, 'fallback');
    expect(app(ChatbotFailureTrace::class)->stages)->toContain(['stage' => 'qdrant', 'status' => 'transport', 'duration_ms' => 15]);
    expect(Cache::get('chatbot:stage:'.now()->format('Ymd').':qdrant:transport', 0))->toBe(0);
    expect(Cache::get('chatbot:metrics:'.now()->format('Ymd').':turns', 0))->toBe(0);
});

test('evaluation retains per turn text diagnostics and aggregate usage without mixing field types', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('turns.json', json_encode([[
        'id' => 'two-turns', 'language' => 'en', 'messages' => ['Hello', 'Hello again'],
        'parts' => [
            ['id' => 'first', 'turn' => 1, 'contains' => ['First reply']],
            ['id' => 'second', 'turn' => 2, 'contains' => ['Second reply']],
        ],
    ]]));
    $this->mock(ChatbotConversation::class, function ($mock) {
        $mock->shouldReceive('respond')->twice()->andReturnUsing(function (string $message): array {
            $trace = app(ChatbotFailureTrace::class);
            $trace->reset();
            $first = $message === 'Hello';
            if (! $first) {
                $trace->record('timeout', 'generation');
            }
            app(ChatbotProviderBudget::class)->recordUsage(10, 20);

            return ['message' => $first ? 'First reply' : 'Second reply', 'language' => 'en', 'source' => 'fallback',
                'context_token' => null, 'presentation' => []];
        });
    });

    expect(Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('turns.json')]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($report['results'][0]['turns'], 'message'))->toBe(['First reply', 'Second reply']);
    expect($report['results'][0]['turns'][1]['failure'])->toBe(['reason' => 'timeout', 'stage' => 'generation']);
    expect($report['summary']['en'])->toMatchArray(['turns' => 2, 'input_tokens' => 20, 'output_tokens' => 40,
        'failed_turns' => 1, 'part_coverage_rate' => 1, 'turn_outcomes' => ['deterministic' => 1, 'generation:timeout' => 1]]);
    expect($report['pipeline_version'])->toMatch('/^[a-f0-9]{64}$/');
    Http::assertNothingSent();
});

test('evaluation checks labeled intent state and rejects leakage across dataset splits', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $case = ['id' => 'state-1', 'family' => 'ram-budget', 'split' => 'held_out', 'language' => 'en', 'messages' => ['Hello'],
        'expected_turns' => [['turn' => 1, 'constraints' => ['brand' => 'Atlas']]]];
    $disk->put('state.json', json_encode([$case]));
    expect(Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('state.json')]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['state_accuracy'])->toBe(0);
    expect($report['state_labeled_turns'])->toBe(1);
    expect($report['results'][0]['checks']['intent_state'])->toBeFalse();
    expect($report['results'][0]['turns'][0]['outcome'])->toBe('deterministic');
    $disk->put('state.json', json_encode([$case, [...$case, 'id' => 'state-2', 'split' => 'development']]));
    expect(Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('state.json')]))->toBe(1);
    expect(Artisan::output())->toContain('families must not cross');
    Http::assertNothingSent();
});

test('intent macro f1 counts wrong predictions and abstentions', function () {
    $metrics = app(ChatbotEvaluationMetrics::class);
    expect($metrics->macroF1([]))->toBeNull();
    expect($metrics->macroF1([['expected' => 'facts', 'actual' => 'facts'], ['expected' => 'facts', 'actual' => null],
        ['expected' => 'discovery', 'actual' => 'facts']]))->toBe(0.25);
});

test('offline evaluation refuses model overrides and restores configuration', function () {
    $model = config('ai_integration.gemini.model');
    expect(Artisan::call('chatbot:evaluate', ['--model' => 'gemini-candidate']))->toBe(1);
    expect(config('ai_integration.gemini.model'))->toBe($model);
    Http::assertNothingSent();
});

test('section labels preserve independent constraint ownership', function () {
    $metrics = app(ChatbotEvaluationMetrics::class);
    $actual = $metrics->snapshot(['multipart_sections' => [
        ['constraints' => ['brand' => 'Atlas'], 'product_ids' => [1]],
        ['constraints' => ['brand' => 'Beacon'], 'product_ids' => [2]],
    ]]);
    expect($metrics->matches(['sections' => [['constraints' => ['brand' => 'Atlas']], ['constraints' => ['brand' => 'Beacon']]]], $actual))->toBeTrue();
    expect($metrics->matches(['sections' => [['constraints' => ['brand' => 'Beacon']], ['constraints' => ['brand' => 'Atlas']]]], $actual))->toBeFalse();
});

test('offline evaluation never calls providers and leaves language quality awaiting human review', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.localization_enabled' => true, 'ai_integration.qdrant.enabled' => true, 'ai_integration.neo4j.enabled' => true, 'ai_integration.memory.enabled' => true]);
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('cases.json', json_encode([['id' => 'hil-privacy', 'language' => 'hil', 'messages' => ['Diin na ang akon order?'], 'contains' => ['Palihog mag-sign in']]]));
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    ChatbotValidationAgent::fake()->preventStrayPrompts();

    expect(Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json')]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['mode'])->toBe('offline');
    expect($report['summary']['hil']['pass_rate'])->toBe(1);
    expect($report['summary']['hil']['grounding_rate'])->toBeNull();
    expect($report['summary']['hil']['release_gate_passed'])->toBeFalse();
    expect($report['results'][0]['turns'][0]['usage']['calls'])->toBe(0);
    expect(config('ai_integration.enabled'))->toBeTrue();
    expect(config('ai_integration.memory.enabled'))->toBeTrue();
    ChatbotIntentAgent::assertNeverPrompted();
    ChatbotResponseAgent::assertNeverPrompted();
    ChatbotEvidenceAgent::assertNeverPrompted();
    ChatbotValidationAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('evaluation rejects sensitive datasets before running a conversation', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('cases.json', json_encode([['id' => 'private', 'language' => 'en', 'messages' => ['My password is example123']]]));
    expect(Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json')]))->toBe(1);
    expect(Artisan::output())->toContain('public synthetic questions only');
    Http::assertNothingSent();
});

test('quality ratings are optional bounded and bound to each repeated answer', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('cases.json', json_encode([['id' => 'quality', 'language' => 'en', 'messages' => ['Where is my order?']]]));
    Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json'), '--runs' => 2]);
    $baseline = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $disk->put('reviews.json', json_encode([
        'quality:1' => ['answer_hash' => $baseline['results'][0]['answer_hash'], 'grounded' => true, 'privacy_pass' => true, 'language_score' => 4,
            'directness' => 5, 'completeness' => 4, 'naturalness' => 3, 'unnecessary_question' => false],
        'quality:2' => ['answer_hash' => 'stale', 'grounded' => true, 'privacy_pass' => true, 'language_score' => 5,
            'directness' => 5, 'completeness' => 5, 'naturalness' => 5, 'unnecessary_question' => true],
    ]));

    expect(Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json'), '--runs' => 2, '--reviews' => $disk->path('reviews.json')]))->toBe(0);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['summary']['en'])->toMatchArray(['reviewed' => 1, 'quality_reviews' => 1, 'average_directness' => 5,
        'average_completeness' => 4, 'average_naturalness' => 3, 'unnecessary_question_rate' => 0,
        'fallback_rate' => 1, 'failure_rate' => 0, 'provider_calls' => 0]);
    expect($report['results'][0]['turns'][0])->toHaveKeys(['presentation', 'failure', 'usage']);
    expect($report['cost'])->toBeNull();
    Http::assertNothingSent();
});

test('invalid quality scores do not invalidate legacy reviews or claim measured quality', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('cases.json', json_encode([['id' => 'quality', 'language' => 'en', 'messages' => ['Where is my order?']]]));
    Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json')]);
    $baseline = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $disk->put('reviews.json', json_encode(['quality' => ['answer_hash' => $baseline['results'][0]['answer_hash'], 'grounded' => true,
        'privacy_pass' => true, 'language_score' => 4, 'directness' => 6, 'completeness' => 4, 'naturalness' => 4, 'unnecessary_question' => false]]));

    Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json'), '--reviews' => $disk->path('reviews.json')]);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['summary']['en'])->toMatchArray(['reviewed' => 1, 'quality_reviews' => 0, 'average_directness' => null]);
    Http::assertNothingSent();
});

test('human review hashes include delivered product presentation', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('cases.json', json_encode([['id' => 'cards', 'language' => 'en', 'messages' => ['Show SSD prices']]]));
    $reply = ['message' => 'Matching drives.', 'language' => 'en', 'source' => 'fallback', 'context_token' => null,
        'presentation' => ['products' => [['id' => 42, 'name' => 'Atlas SSD', 'price' => '2000.00']]]];
    $changed = $reply;
    $changed['presentation']['products'][0]['price'] = '3000.00';
    $this->mock(ChatbotConversation::class)->shouldReceive('respond')->twice()->andReturn($reply, $changed);

    Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json')]);
    $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json')]);
    $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($second['results'][0]['answer_hash'])->not->toBe($first['results'][0]['answer_hash']);
    expect($second['results'][0]['questions'])->toBe(['Show SSD prices']);
    Http::assertNothingSent();
});

test('live evaluation requires configured activation and never silently enables providers', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('cases.json', json_encode([['id' => 'public', 'language' => 'en', 'messages' => ['Where is my order?']]]));
    expect(Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json'), '--live' => true]))->toBe(1);
    expect(Artisan::output())->toContain('separately configured');
    Http::assertNothingSent();
});

test('human review ratings apply only to the exact evaluated conversation and answer', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('cases.json', json_encode([['id' => 'privacy', 'language' => 'en', 'messages' => ['Where is my order?']]]));
    Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json')]);
    $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $disk->put('reviews.json', json_encode(['privacy' => ['answer_hash' => $first['results'][0]['answer_hash'], 'grounded' => false, 'privacy_pass' => false, 'language_score' => 3]]));
    Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json'), '--reviews' => $disk->path('reviews.json')]);
    $reviewed = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($reviewed['summary']['en']['reviewed'])->toBe(1);
    expect($reviewed['summary']['en']['privacy_failures'])->toBe(1);
    expect($reviewed['summary']['en']['average_language_score'])->toBe(3);

    $disk->put('cases.json', json_encode([['id' => 'privacy', 'language' => 'en', 'messages' => ['What is my order status?']]]));
    Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json'), '--reviews' => $disk->path('reviews.json')]);
    $changed = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($changed['summary']['en']['reviewed'])->toBe(0);
    expect($changed['results'][0]['human_reviewed'])->toBeFalse();
});

test('the bundled review corpus covers fifty conversations in each target language', function () {
    $cases = json_decode(file_get_contents(resource_path('data/chatbot-evaluation.json')), true, flags: JSON_THROW_ON_ERROR);
    expect(array_count_values(array_column($cases, 'language')))->toBe(['en' => 50, 'ceb' => 50, 'tl' => 50, 'hil' => 50]);
    expect(count(array_unique(array_column($cases, 'id'))))->toBe(200);
});

test('evaluation measures required parts on their specified turns instead of the last answer', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('cases.json', json_encode([[
        'id' => 'parts', 'language' => 'en',
        'messages' => ['Where is my order?', 'Tell me a joke'],
        'parts' => [
            ['id' => 'privacy', 'turn' => 1, 'contains' => ['sign in']],
            ['id' => 'missing-answer', 'turn' => 2, 'contains' => ['invented delivery promise']],
        ],
    ]]));

    expect(Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json')]))->toBe(1);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['results'][0]['parts'])->toBe([
        ['id' => 'privacy', 'turn' => 1, 'passed' => true],
        ['id' => 'missing-answer', 'turn' => 2, 'passed' => false],
    ]);
    expect($report['summary']['en']['part_coverage_rate'])->toBe(0.5);
    expect($report['results'][0]['checks']['parts'])->toBeFalse();
    expect($report['summary']['en']['failure_counts']['parts'])->toBe(1);
    Http::assertNothingSent();
});

test('evaluation rejects invalid part references before processing messages', function (array $parts) {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('cases.json', json_encode([[
        'id' => 'invalid-parts', 'language' => 'en', 'messages' => ['Where is my order?'], 'parts' => $parts,
    ]]));

    expect(Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json')]))->toBe(1);
    expect(Artisan::output())->toContain('Evaluation parts require');
    Http::assertNothingSent();
})->with([
    'missing turn' => [[['id' => 'order', 'turn' => 2, 'contains' => ['sign in']]]],
    'duplicate IDs' => [[
        ['id' => 'order', 'turn' => 1, 'contains' => ['sign in']],
        ['id' => 'order', 'turn' => 1, 'contains' => ['sign in']],
    ]],
]);

test('an unlabeled dataset does not claim measured part coverage', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('cases.json', json_encode([['id' => 'legacy', 'language' => 'en', 'messages' => ['Where is my order?']]]));

    expect(Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json')]))->toBe(0);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['summary']['en']['part_coverage_rate'])->toBeNull();
    expect($report['results'][0]['checks']['parts'])->toBeNull();
});

test('diagnostics reports role readiness and configured limits without exposing credentials', function () {
    config([
        'ai_integration.gemini.model' => 'configured-model',
        'ai_integration.gemini.scope_key' => 'scope-secret-value',
        'ai_integration.gemini.response_key' => null,
        'ai_integration.gemini.validation_key' => 'review-secret-value',
        'ai_integration.gemini.project_budget_key' => 'private-budget-identity',
    ]);

    expect(Artisan::call('chatbot:diagnostics'))->toBe(0);

    expect(Artisan::output())->toContain('configured-model', 'unverified', 'missing', 'Local project limits')
        ->not->toContain('scope-secret-value', 'review-secret-value', 'private-budget-identity');
    Http::assertNothingSent();
});

test('evaluation fingerprints dataset labels as well as messages', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $cases = [['id' => 'fingerprint', 'language' => 'en', 'messages' => ['Where is my order?']]];
    $disk->put('cases.json', json_encode($cases));
    Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json')]);
    $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $cases[0]['parts'] = [['id' => 'privacy', 'turn' => 1, 'contains' => ['sign in']]];
    $disk->put('cases.json', json_encode($cases));
    Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json')]);
    $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($second['dataset_hash'])->not->toBe($first['dataset_hash']);
    expect($second['results'][0]['answer_hash'])->toBe($first['results'][0]['answer_hash']);
    expect($second['configured_model'])->toBe(config('ai_integration.gemini.model'));
});

test('repeated evaluation reports consistency without counting repeats as unique coverage', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('cases.json', json_encode([['id' => 'repeat', 'language' => 'en', 'messages' => ['Where is my order?']]]));

    expect(Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('cases.json'), '--runs' => 3]))->toBe(0);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($report['results'], 'run'))->toBe([1, 2, 3]);
    expect($report['summary']['en']['cases'])->toBe(3);
    expect($report['summary']['en']['unique_cases'])->toBe(1);
    expect($report['summary']['en']['repeat_consistency_rate'])->toBe(1);
    expect($report['summary']['en']['release_gate_passed'])->toBeFalse();
    Http::assertNothingSent();
});

test('repeat counts are bounded before evaluation', function (string $runs) {
    expect(Artisan::call('chatbot:evaluate', ['--runs' => $runs]))->toBe(1);
    expect(Artisan::output())->toContain('between one and three');
    Http::assertNothingSent();
})->with(['0', '4', 'arbitrary']);

test('extended corpus preserves originals and adds balanced long and routing cases', function () {
    $original = json_decode(file_get_contents(resource_path('data/chatbot-evaluation.json')), true, flags: JSON_THROW_ON_ERROR);
    $extended = json_decode(file_get_contents(resource_path('data/chatbot-evaluation-extended.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(array_slice($extended, 0, 200))->toBe($original);
    expect(count(array_unique(array_column($extended, 'id'))))->toBe(300);
    foreach (['en', 'tl', 'ceb', 'hil'] as $language) {
        $added = array_values(array_filter(array_slice($extended, 200), fn (array $case): bool => $case['language'] === $language));
        expect($added)->toHaveCount(25);
        expect(count(array_filter($added, fn (array $case): bool => mb_strlen($case['messages'][0]) >= 500)))->toBe(15);
        expect(array_all($added, fn (array $case): bool => mb_strlen($case['messages'][0]) <= 1000 && isset($case['expected_category'])))->toBeTrue();
    }
});

test('extended corpus runs offline without consuming providers or hiding routing failures', function () {
    $status = Artisan::call('chatbot:evaluate', ['--dataset' => resource_path('data/chatbot-evaluation-extended.json')]);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['results'])->toHaveCount(300);
    expect($report['mode'])->toBe('offline');
    expect($status)->toBe(array_any($report['results'], fn (array $result): bool => ! $result['passed']) ? 1 : 0);
    foreach ($report['summary'] as $metrics) {
        expect($metrics['routing_labeled'])->toBe(25);
        expect($metrics['release_gate_passed'])->toBeFalse();
    }
    expect(array_all($report['results'], fn (array $result): bool => array_all($result['turns'], fn (array $turn): bool => $turn['usage']['calls'] === 0)))->toBeTrue();
    Http::assertNothingSent();
});
