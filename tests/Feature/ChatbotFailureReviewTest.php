<?php

use App\Ai\Agents\ChatbotEvidenceAgent;
use App\Ai\Agents\ChatbotResponseAgent;
use App\Ai\Agents\ChatbotUnderstandingAgent;
use App\Ai\Agents\ChatbotValidationAgent;
use App\Models\Product;
use App\Services\Chatbot\ChatbotFailureRecorder;
use App\Services\Chatbot\ChatbotFailureTrace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;

test('provider degradation stores an encrypted sanitized sample and leaves public responses unchanged', function () {
    $product = Product::factory()->available()->create(['name' => 'BIOSTAR H610M Motherboard']);
    ChatbotResponseAgent::fake(fn () => throw new AiException('DO NOT STORE RAW EXCEPTION'))->preventStrayPrompts();

    $response = $this->postJson('/chatbot', ['message' => 'meron po kayo BIOSTAR H610M Motherboard?'])->assertOk()->assertJsonPath('source', 'fallback');

    $event = DB::table('chatbot_failure_events')->sole();
    expect($event->reason)->toBe('provider_unavailable');
    expect($event->path)->toBe('legacy');
    expect($event->sample)->not->toContain('meron', 'DO NOT STORE');
    $sample = json_decode(Crypt::decryptString($event->sample), true, flags: JSON_THROW_ON_ERROR);
    expect($sample['question'])->toBe('meron po kayo BIOSTAR H610M Motherboard?');
    expect(json_decode($event->product_ids, true))->toBe([$product->id]);
    expect($response->json())->not->toHaveKey('diagnostics');
    Http::assertNothingSent();
});

test('recording resets between turns and does not classify successful deterministic replies as failures', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.adaptive' => true]);
    $product = Product::factory()->available()->create(['name' => 'Atlas SSD']);

    $this->postJson('/chatbot', ['message' => 'Price of Unknown Motherboard?'])->assertOk();
    $before = DB::table('chatbot_failure_events')->sum('occurrences');
    $this->postJson('/chatbot', ['message' => 'Price of Atlas SSD?'])->assertOk()->assertJsonPath('source', 'fallback');

    expect($before)->toBeGreaterThan(0);
    expect(DB::table('chatbot_failure_events')->sum('occurrences'))->toBe($before);
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('out of scope questions are never retained even when the model returns unsupported', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.gemini.tokens_per_minute' => 60000]);
    ChatbotUnderstandingAgent::fake([failureReviewIntent(['outcome' => 'unsupported'])])->preventStrayPrompts();

    $this->postJson('/chatbot', ['message' => 'Tell me a story about dragons'])->assertOk();

    expect(DB::table('chatbot_failure_events')->count())->toBe(0);
    Http::assertNothingSent();
});

test('unknown scope and sensitive inquiries retain metadata without text or customer references', function (string $question) {
    $trace = app(ChatbotFailureTrace::class);
    $trace->reset();
    $trace->record('understanding_failed', 'scope');

    app(ChatbotFailureRecorder::class)->capture($question, 'Temporary failure', 'tl', [19], 20);

    $event = DB::table('chatbot_failure_events')->sole();
    expect($event->sample)->toBeNull();
    expect($event->product_ids)->toBe('[]');
    expect((array) $event)->not->toHaveKeys(['user_id', 'session_id', 'ip_address', 'context_token']);
})->with(['Unclear words here', 'Motherboard for my order BF000081, email me at person@example.com', 'Motherboard password is abc123']);

test('unknown names and numbers are masked before storage and deduplication', function () {
    $trace = app(ChatbotFailureTrace::class);
    $trace->reset();
    $trace->scope = 'public';
    $trace->record('no_match', 'retrieval');

    app(ChatbotFailureRecorder::class)->capture('Motherboard for John Alvarez 12345?', 'No matching motherboard.', 'en', [], 12);

    $event = DB::table('chatbot_failure_events')->sole();
    $sample = Crypt::decryptString($event->sample);
    expect($sample)->toContain('Motherboard', '[redacted]')->not->toContain('John', 'Alvarez', '12345');
});

test('explicit PHP amounts remain useful while unrelated identifiers are masked', function () {
    app(ChatbotFailureTrace::class)->scope = 'public';
    app(ChatbotFailureTrace::class)->record('no_match', 'retrieval');
    app(ChatbotFailureRecorder::class)->capture('Motherboard under PHP 5,000.00 for John 12345?', 'No matching product.', 'en', [], 1);

    $sample = Crypt::decryptString(DB::table('chatbot_failure_events')->value('sample'));

    expect($sample)->toContain('PHP 5,000.00')->not->toContain('John', '12345');
});

test('named out of stock answers are successful fallback replies and are not failure records', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.adaptive' => true]);
    $product = Product::factory()->available()->create(['name' => 'Atlas SSD']);
    $product->inventory->update(['quantity' => 0]);

    $response = $this->postJson('/chatbot', ['message' => 'Stock of Atlas SSD?'])->assertOk()->assertJsonPath('source', 'fallback');

    expect($response->json('message'))->toContain('out of stock');
    expect(DB::table('chatbot_failure_events')->count())->toBe(0);
    Http::assertNothingSent();
});

test('mobile failures use the same private capture boundary', function () {
    Product::factory()->available()->create(['name' => 'Atlas SSD']);
    ChatbotResponseAgent::fake(fn () => throw new AiException('offline'))->preventStrayPrompts();

    $response = $this->postJson('/api/v1/chatbot', ['message' => 'Price of Atlas SSD?'])->assertOk();

    expect(DB::table('chatbot_failure_events')->value('reason'))->toBe('provider_unavailable');
    expect($response->json('data'))->not->toHaveKey('diagnostics');
    Http::assertNothingSent();
});

test('a necessary ambiguity is reviewable without being declared a chatbot defect', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.gemini.tokens_per_minute' => 60000]);
    ChatbotUnderstandingAgent::fake([failureReviewIntent(['outcome' => 'clarify', 'clarification' => 'cpu_meaning'])])->preventStrayPrompts();

    $this->postJson('/chatbot', ['message' => 'Do you have CPU?'])->assertOk();
    Artisan::call('chatbot:failures');
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['cases'][0]['reason'])->toBe('clarification');
    expect($report['cases'][0]['suggested_investigation'])->toContain('expected behavior');
    expect(Artisan::call('chatbot:failures', ['--dismiss' => $report['cases'][0]['id']]))->toBe(0);
    expect(DB::table('chatbot_failure_events')->value('status'))->toBe('dismissed');
    Http::assertNothingSent();
});

test('approved catalog advice clarifications are retained despite the legacy recommendation exclusion', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.gemini.tokens_per_minute' => 60000]);
    ChatbotUnderstandingAgent::fake([failureReviewIntent(['intent' => 'recommendation', 'outcome' => 'clarify', 'clarification' => 'missing_details', 'category_key' => 'graphics'])])->preventStrayPrompts();

    $this->postJson('/chatbot', ['message' => 'Recommend a GPU for gaming?'])->assertOk();

    expect(DB::table('chatbot_failure_events')->value('reason'))->toBe('clarification');
    expect(DB::table('chatbot_failure_events')->value('scope'))->toBe('public');
    Http::assertNothingSent();
});

test('explicit unsupported scope and blocked open domain content are excluded from diagnostic storage', function () {
    $trace = app(ChatbotFailureTrace::class);
    $trace->scope = 'unsupported';
    $trace->record('timeout', 'scope');
    app(ChatbotFailureRecorder::class)->capture('Tell a story about dragons', '', 'en', [], 1);
    $trace->reset();
    $trace->record('timeout', 'scope');
    app(ChatbotFailureRecorder::class)->capture('What is the weather tomorrow?', '', 'en', [], 1);

    expect(DB::table('chatbot_failure_events')->count())->toBe(0);
});

test('rollback cannot silently discard pending diagnostic samples', function () {
    app(ChatbotFailureTrace::class)->record('no_match', 'retrieval');
    app(ChatbotFailureRecorder::class)->capture('Motherboard?', '', 'en', [], 1);
    $migration = require database_path('migrations/2026_11_04_000802_create_chatbot_failure_events_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Refusing to discard');
    expect(DB::table('chatbot_failure_events')->count())->toBe(1);
});

test('equivalent failures deduplicate and recurring resolved cases reopen without extending retention', function () {
    $this->freezeTime();
    $trace = app(ChatbotFailureTrace::class);
    $trace->record('no_match', 'retrieval');
    $recorder = app(ChatbotFailureRecorder::class);
    $recorder->capture('Motherboard available?', 'No matching product.', 'en', [], 10);
    $first = DB::table('chatbot_failure_events')->sole();
    DB::table('chatbot_failure_events')->where('id', $first->id)->update(['status' => 'resolved', 'reviewed_at' => now()]);
    $this->travel(1)->days();

    $recorder->capture('Motherboard available?', 'No matching product.', 'en', [], 10);

    $updated = DB::table('chatbot_failure_events')->sole();
    expect($updated->occurrences)->toBe(2);
    expect($updated->status)->toBe('open');
    expect($updated->reviewed_at)->toBeNull();
    expect($updated->delete_after)->toBe($first->delete_after);
});

test('recording is capped disabled during offline evaluation and can be switched off', function () {
    config(['ai_integration.failure_review.max_records' => 1]);
    $trace = app(ChatbotFailureTrace::class);
    $trace->record('no_match', 'retrieval');
    $recorder = app(ChatbotFailureRecorder::class);
    $recorder->capture('Motherboard?', '', 'en', [], 1);
    $recorder->capture('Laptop?', '', 'en', [], 1);
    config(['ai_integration.offline' => true]);
    $recorder->capture('Motherboard?', '', 'en', [], 1);
    config(['ai_integration.offline' => false, 'ai_integration.failure_review.enabled' => false]);
    $recorder->capture('Motherboard?', '', 'en', [], 1);

    expect(DB::table('chatbot_failure_events')->count())->toBe(1);
    expect(DB::table('chatbot_failure_events')->value('occurrences'))->toBe(1);
});

test('failure storage outages never replace valid customer replies or expose exception details', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.adaptive' => true]);
    Log::spy();
    DB::statement('DROP TABLE chatbot_failure_events');

    $this->postJson('/chatbot', ['message' => 'Price of Unknown Motherboard?'])->assertOk();

    Log::shouldHaveReceived('warning')->with('Chatbot failure recording unavailable.', ['failure' => 'storage_unavailable'])->once();
    Http::assertNothingSent();
});

test('model led invalid interpretation is distinguishable from retrieval failure across languages', function (string $question, string $language) {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.gemini.tokens_per_minute' => 60000]);
    ChatbotUnderstandingAgent::fake([['outcome' => 'invalid']])->preventStrayPrompts();

    $this->postJson('/chatbot', ['message' => $question])->assertOk();

    $event = DB::table('chatbot_failure_events')->sole();
    expect($event->path)->toBe('model_led');
    expect($event->reason)->toBe('understanding_invalid_output');
    expect($event->stage)->toBe('scope');
    expect($event->language)->toBe($language);
    Http::assertNothingSent();
})->with([
    ['Do you carry motherboard?', 'en'], ['Meron po kayo motherboard?', 'tl'], ['Naa moy motherboard?', 'ceb'], ['May ara kamo motherboard?', 'hil'],
]);

test('rejected generated answers keep validation stage evidence and do not store the rejected draft', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.gemini.max_calls' => 3,
        'ai_integration.gemini.tokens_per_minute' => 60000, 'scout.driver' => 'null']);
    $product = Product::factory()->available()->create(['name' => 'Atlas SSD', 'price' => '1000.00', 'discount_price' => null]);
    ChatbotUnderstandingAgent::fake([failureReviewIntent(['intent' => 'facts', 'category_key' => 'storage', 'search_query' => 'Atlas SSD', 'product_mentions' => ['Atlas SSD']])])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake([['answer' => 'Atlas SSD costs PHP 1,000.00.', 'evidence_ids' => ['product:'.$product->id]]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'reject']])->preventStrayPrompts();

    $this->postJson('/chatbot', ['message' => 'Price of Atlas SSD?'])->assertOk()->assertJsonPath('source', 'fallback');

    $event = DB::table('chatbot_failure_events')->sole();
    expect($event->reason)->toBe('validation_failed');
    expect($event->stage)->toBe('validation');
    Http::assertNothingSent();
});

test('local review defaults to metadata supports human resolution and prunes expired samples', function () {
    $this->freezeTime();
    app(ChatbotFailureTrace::class)->record('no_match', 'retrieval');
    app(ChatbotFailureRecorder::class)->capture('Motherboard available?', 'No matching product.', 'en', [], 10);
    $event = DB::table('chatbot_failure_events')->sole();

    expect(Artisan::call('chatbot:failures'))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['cases'][0])->not->toHaveKey('sample');
    expect($report['automatic_changes'])->toBeFalse();
    expect(Artisan::call('chatbot:failures', ['--samples' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['cases'][0]['sample']['question'])->toBe('Motherboard available?');
    expect(Artisan::call('chatbot:failures', ['--resolve' => $event->id]))->toBe(1);
    expect(Artisan::call('chatbot:failures', ['--resolve' => $event->id, '--regression' => 'tests/Feature/ChatbotFailureReviewTest.php']))->toBe(0);
    expect(DB::table('chatbot_failure_events')->value('status'))->toBe('resolved');
    $this->travel(8)->days();
    expect(Artisan::call('chatbot:failures'))->toBe(0);
    expect(json_decode(Artisan::output(), true)['cases'])->toBe([]);
    expect(Artisan::call('chatbot:failures', ['--prune' => true]))->toBe(0);
    expect(DB::table('chatbot_failure_events')->count())->toBe(0);
    Http::assertNothingSent();
});

test('diagnostic samples are not exposed through customer web or mobile routes', function () {
    $this->getJson('/chatbot/failures')->assertNotFound();
    $this->getJson('/api/v1/chatbot/failures')->assertNotFound();
});

function failureReviewIntent(array $changes = []): array
{
    return array_replace(['outcome' => 'supported', 'intent' => 'discovery', 'language' => 'en', 'category_key' => 'motherboard', 'search_query' => 'motherboard',
        'product_mentions' => [], 'attributes' => ['stock'], 'maximum_php' => null, 'excluded_brands' => [], 'brand' => '', 'capacity' => '', 'use_case' => '',
        'reference' => 'new', 'position' => 0, 'clarification' => 'none'], $changes);
}
