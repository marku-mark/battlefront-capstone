<?php

use App\Ai\Agents\ChatbotEvidenceAgent;
use App\Ai\Agents\ChatbotIntentAgent;
use App\Ai\Agents\ChatbotResponseAgent;
use App\Ai\Agents\ChatbotValidationAgent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

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
