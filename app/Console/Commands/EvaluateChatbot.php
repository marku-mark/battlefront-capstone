<?php

namespace App\Console\Commands;

use App\Ai\Agents\ChatbotUnderstandingAgent;
use App\Models\Product;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ChatbotConversationToken;
use App\Services\Chatbot\ChatbotEvaluationMetrics;
use App\Services\Chatbot\ChatbotFailureTrace;
use App\Services\Chatbot\ChatbotIntentState;
use App\Services\Chatbot\ChatbotLanguage;
use App\Services\Chatbot\ChatbotOrchestrationService;
use App\Services\Chatbot\ChatbotProviderBudget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Illuminate\Validation\Rule;
use JsonException;

#[Signature('chatbot:evaluate {--dataset= : JSON cases; defaults to the bundled multilingual corpus} {--runs=1 : Repeat each case between one and three times} {--live : Explicitly allow configured public provider calls} {--reviews= : JSON human reviews bound to answer hashes} {--model= : Gemini model override for an explicitly live benchmark only} {--split= : development or held_out cases} {--catalog-version=unversioned : Operator supplied frozen public catalog identifier} {--max-provider-calls=30 : Maximum planned text calls in an explicitly live run} {--prepare-reviews : Write a private hash-bound fluent-speaker review packet} {--pause-ms=0 : Optional delay between live turns, at most 30000 ms}')]
#[Description('Evaluate public multilingual conversations and emit a JSON report; offline by default')]
class EvaluateChatbot extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ChatbotConversation $chatbot, ChatbotOrchestrationService $boundary): int
    {
        $model = $this->option('model');
        $pause = filter_var($this->option('pause-ms'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 30000]]);
        if ($pause === false) {
            $this->error('Live pacing must be between zero and 30000 milliseconds.');

            return self::FAILURE;
        }
        if (($model !== null && (! $this->option('live') || ! preg_match('/^gemini-[a-z0-9.-]{1,80}$/D', $model)))
            || ($this->option('split') !== null && ! in_array($this->option('split'), ['development', 'held_out'], true))
            || ! preg_match('/^[a-zA-Z0-9_.-]{1,100}$/D', $this->option('catalog-version'))) {
            $this->error('Use a valid Gemini model only with --live, a development/held_out split and a bounded catalog identifier.');

            return self::FAILURE;
        }
        $runs = filter_var($this->option('runs'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3]]);
        if ($runs === false) {
            $this->error('Evaluation runs must be an integer between one and three.');

            return self::FAILURE;
        }
        $dataset = $this->readJson($this->option('dataset') ?: resource_path('data/chatbot-evaluation.json'));
        $reviews = $this->option('reviews') ? $this->readJson($this->option('reviews')) : [];
        if ($dataset === null || $reviews === null) {
            return self::FAILURE;
        }
        $validationRules = [
            'cases' => ['required', 'array', 'min:1', 'max:1000'],
            'cases.*' => ['array:id,language,messages,contains,not_contains,expected_category,expected_attributes,expected_clarification,parts,expected_turns,family,split'],
            'cases.*.family' => ['sometimes', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_.-]+$/'],
            'cases.*.split' => ['sometimes', Rule::in(['development', 'held_out'])],
            'cases.*.expected_turns' => ['sometimes', 'array', 'max:10'],
            'cases.*.expected_turns.*' => ['array:turn,intent,operation,constraints,product_ids,sections,attributes,outcome'],
            'cases.*.expected_turns.*.attributes' => ['sometimes', 'array', 'max:4'],
            'cases.*.expected_turns.*.attributes.*' => [Rule::in(['price', 'stock', 'details', 'comparison'])],
            'cases.*.expected_turns.*.outcome' => ['sometimes', Rule::in(['supported', 'clarify', 'unsupported'])],
            'cases.*.expected_turns.*.sections' => ['sometimes', 'array', 'max:2'],
            'cases.*.expected_turns.*.sections.*' => ['array:intent,operation,constraints,product_ids,attributes,outcome'],
            'cases.*.expected_turns.*.turn' => ['required', 'integer', 'min:1', 'max:10'],
            'cases.*.expected_turns.*.intent' => ['sometimes', Rule::in(ChatbotUnderstandingAgent::INTENTS)],
            'cases.*.expected_turns.*.operation' => ['sometimes', Rule::in(ChatbotIntentState::OPERATIONS)],
            'cases.*.expected_turns.*.constraints' => ['sometimes', 'array:category_key,brand,excluded_brands,capacity,use_case,branch,availability,price'],
            'cases.*.expected_turns.*.constraints.category_key' => ['sometimes', 'string', 'max:40'],
            'cases.*.expected_turns.*.constraints.brand' => ['sometimes', 'string', 'max:80'],
            'cases.*.expected_turns.*.constraints.excluded_brands' => ['sometimes', 'array', 'max:6'],
            'cases.*.expected_turns.*.constraints.excluded_brands.*' => ['string', 'max:80'],
            'cases.*.expected_turns.*.constraints.capacity' => ['sometimes', 'string', 'max:40'],
            'cases.*.expected_turns.*.constraints.use_case' => ['sometimes', 'string', 'max:100'],
            'cases.*.expected_turns.*.constraints.branch' => ['sometimes', 'string', 'max:100'],
            'cases.*.expected_turns.*.constraints.availability' => ['sometimes', Rule::in(['any', 'in_stock'])],
            'cases.*.expected_turns.*.constraints.price' => ['sometimes', 'array:minimum,maximum,minimum_inclusive,maximum_inclusive,sort'],
            'cases.*.expected_turns.*.constraints.price.minimum' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cases.*.expected_turns.*.constraints.price.maximum' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cases.*.expected_turns.*.constraints.price.minimum_inclusive' => ['sometimes', 'boolean'],
            'cases.*.expected_turns.*.constraints.price.maximum_inclusive' => ['sometimes', 'boolean'],
            'cases.*.expected_turns.*.constraints.price.sort' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'cases.*.expected_turns.*.product_ids' => ['sometimes', 'array', 'max:6'],
            'cases.*.expected_turns.*.product_ids.*' => ['integer:strict', 'min:1'],
            'cases.*.parts' => ['sometimes', 'array', 'min:1', 'max:10'],
            'cases.*.parts.*' => ['array:id,turn,contains,not_contains'],
            'cases.*.parts.*.id' => ['required', 'string', 'max:100'],
            'cases.*.parts.*.turn' => ['required', 'integer', 'min:1', 'max:10'],
            'cases.*.parts.*.contains' => ['required', 'array', 'min:1', 'max:20'],
            'cases.*.parts.*.contains.*' => ['required', 'string', 'max:200'],
            'cases.*.parts.*.not_contains' => ['sometimes', 'array', 'max:20'],
            'cases.*.parts.*.not_contains.*' => ['required', 'string', 'max:200'],
            'cases.*.expected_category' => ['sometimes', Rule::in(['product', 'store', 'order', 'faq', 'unsupported'])],
            'cases.*.expected_attributes' => ['sometimes', 'array', 'max:3'],
            'cases.*.expected_attributes.*' => [Rule::in(['price', 'stock', 'details'])],
            'cases.*.expected_clarification' => ['sometimes', 'boolean'],
            'cases.*.id' => ['required', 'string', 'max:100', 'distinct'],
            'cases.*.language' => ['required', Rule::in(ChatbotLanguage::LANGUAGES)],
            'cases.*.messages' => ['required', 'array', 'min:1', 'max:10'],
            'cases.*.messages.*' => ['required', 'string', 'max:1000'],
            'cases.*.contains' => ['sometimes', 'array', 'max:20'],
            'cases.*.contains.*' => ['string', 'max:200'],
            'cases.*.not_contains' => ['sometimes', 'array', 'max:20'],
            'cases.*.not_contains.*' => ['string', 'max:200'],
        ];
        $turnPrefix = 'cases.*.expected_turns.*.';
        foreach ($validationRules as $key => $rules) {
            if (str_starts_with($key, $turnPrefix) && ! in_array(substr($key, strlen($turnPrefix)), ['turn', 'sections', 'sections.*'], true)) {
                $validationRules[$turnPrefix.'sections.*.'.substr($key, strlen($turnPrefix))] = $rules;
            }
        }
        $validator = Validator::make(['cases' => $dataset], $validationRules);
        if ($validator->fails()) {
            $this->error('Invalid evaluation dataset: '.$validator->errors()->first());

            return self::FAILURE;
        }
        $familySplits = [];
        foreach ($dataset as $case) {
            if (isset($case['family'], $case['split'])) {
                if (isset($familySplits[$case['family']]) && $familySplits[$case['family']] !== $case['split']) {
                    $this->error('Conversation families must not cross development and held-out splits.');

                    return self::FAILURE;
                }
                $familySplits[$case['family']] = $case['split'];
            }
            $turnLabels = array_column($case['expected_turns'] ?? [], 'turn');
            if (count(array_unique($turnLabels)) !== count($turnLabels) || array_any($turnLabels, fn (int $turn): bool => $turn > count($case['messages']))) {
                $this->error('Expected turns must be unique and reference existing messages.');

                return self::FAILURE;
            }
            $parts = $case['parts'] ?? [];
            if (count(array_unique(array_column($parts, 'id'))) !== count($parts)
                || array_any($parts, fn (array $part): bool => $part['turn'] > count($case['messages']))) {
                $this->error('Evaluation parts require unique IDs within each case and existing turn numbers.');

                return self::FAILURE;
            }
            foreach ($case['messages'] as $message) {
                if ($boundary->containsSensitiveInput($message)) {
                    $this->error('Evaluation datasets must contain public synthetic questions only.');

                    return self::FAILURE;
                }
            }
        }
        if ($this->option('live') && ! config('ai_integration.enabled')) {
            $this->error('Live evaluation requires separately configured AI integration activation.');

            return self::FAILURE;
        }
        $original = config('ai_integration');
        if ($this->option('split') !== null) {
            $dataset = array_values(array_filter($dataset, fn (array $case): bool => ($case['split'] ?? null) === $this->option('split')));
            if ($dataset === []) {
                $this->error('No cases match the requested split.');

                return self::FAILURE;
            }
        }
        $callLimit = filter_var($this->option('max-provider-calls'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3000]]);
        $plannedCalls = array_sum(array_map(fn (array $case): int => count($case['messages']), $dataset)) * $runs * 3;
        if ($callLimit === false || ($this->option('live') && $plannedCalls > $callLimit)) {
            $this->error('This dataset exceeds the planned text-call budget. Select fewer cases or explicitly raise --max-provider-calls. Embedding requests are additional.');

            return self::FAILURE;
        }
        config(['ai_integration.offline' => ! $this->option('live'), 'ai_integration.memory.enabled' => false,
            'ai_integration.evaluation' => true, 'ai_integration.failure_review.enabled' => false]);
        if ($model !== null) {
            config(['ai_integration.gemini.model' => $model, 'ai_integration.gemini.fallback_enabled' => false]);
        }
        if (! $this->option('live')) {
            config(['ai_integration.enabled' => false]);
        }
        $evaluatedConfiguration = config('ai_integration');
        $catalogFingerprint = $this->catalogFingerprint();
        $results = [];
        $intentLabels = [];
        $modelIntentLabels = [];
        $languageIntentLabels = [];
        $languageStateChecks = [];
        $stateChecks = [];
        $evaluation = app(ChatbotEvaluationMetrics::class);
        $summary = [];
        $allPassed = true;
        $evaluationCases = [];
        $completedTurns = 0;
        foreach ($dataset as $case) {
            for ($run = 1; $run <= $runs; $run++) {
                $evaluationCases[] = [$case, $run];
            }
        }
        try {
            foreach ($evaluationCases as [$case, $run]) {
                $context = null;
                $turns = [];
                $started = hrtime(true);
                $response = null;
                foreach ($case['messages'] as $message) {
                    if ($this->option('live') && $pause > 0 && $completedTurns > 0) {
                        Sleep::for($pause)->milliseconds();
                    }
                    app(ChatbotProviderBudget::class)->reset();
                    $turnStarted = hrtime(true);
                    $response = $chatbot->respond($message, null, $context, 'evaluation:'.$case['id']);
                    $context = $response['context_token'];
                    $completedTurns++;
                    $trace = app(ChatbotFailureTrace::class);
                    $turnState = $evaluation->snapshot(app(ChatbotConversationToken::class)->decode($context, 'evaluation:'.$case['id'].':guest'));
                    $turnState['outcome'] = $trace->understanding['outcome'] ?? null;
                    $failure = $trace->event;
                    $turns[] = [
                        'message' => $response['message'], 'language' => $response['language'], 'source' => $response['source'],
                        'presentation' => $response['presentation'], 'failure' => $failure, 'review_reason' => $trace->reviewReason,
                        'duration_ms' => (int) ((hrtime(true) - $turnStarted) / 1_000_000), 'usage' => app(ChatbotProviderBudget::class)->usage(),
                        'retrieval' => $trace->retrieval, 'understanding' => $trace->understanding,
                        'provider_responses' => $trace->providerResponses, 'stages' => $trace->stages,
                        'retrieval_failures' => $trace->retrievalFailures, 'draft_reason' => $trace->draftReason,
                        'state' => $turnState, 'attribute_source' => $trace->understanding !== null ? 'model' : 'local',
                        'outcome' => $failure !== null ? $failure['stage'].':'.$failure['reason']
                            : ($response['source'] === 'gemini' ? 'reviewed_model' : 'deterministic'),
                    ];
                }
                if ($response === null) {
                    throw new \RuntimeException('An evaluation case must contain at least one turn.');
                }
                $answer = $response['message'];
                $state = app(ChatbotConversationToken::class)->decode($context, 'evaluation:'.$case['id'].':guest');
                $actualCategory = $state['active'] ?? 'unsupported';
                $understoodAttributes = app(ChatbotFailureTrace::class)->understanding['attributes'] ?? null;
                $attributes = $understoodAttributes === null ? app(ChatbotLanguage::class)->interpret($message)['attributes']
                    : array_values(array_map(fn (string $attribute): string => $attribute === 'specifications' ? 'details' : $attribute, $understoodAttributes));
                $clarification = ($response['presentation']['clarification'] ?? false)
                    || (app(ChatbotFailureTrace::class)->event['reason'] ?? null) === 'clarification'
                    || isset($state['pending_section']) || isset($state['pending_compound']) || isset($state['pending_catalog']);
                $routingPass = ! isset($case['expected_category']) || $case['expected_category'] === $actualCategory;
                $attributesPass = ! isset($case['expected_attributes']) || $case['expected_attributes'] === $attributes;
                $clarificationPass = ! isset($case['expected_clarification']) || $case['expected_clarification'] === $clarification;
                $parts = [];
                foreach ($case['parts'] ?? [] as $part) {
                    $partAnswer = $turns[$part['turn'] - 1]['message'];
                    $parts[] = ['id' => $part['id'], 'turn' => $part['turn'], 'passed' => array_all($part['contains'], fn (string $needle): bool => str_contains($partAnswer, $needle))
                        && array_all($part['not_contains'] ?? [], fn (string $needle): bool => ! str_contains($partAnswer, $needle))];
                }
                $hash = hash('sha256', json_encode(['messages' => $case['messages'], 'turns' => array_map(
                    fn (array $turn): array => ['message' => $turn['message'], 'language' => $turn['language'], 'source' => $turn['source'], 'presentation' => $turn['presentation']], $turns,
                )], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $checks = [
                    'language' => $response['language'] === $case['language'],
                    'routing' => isset($case['expected_category']) ? $routingPass : null,
                    'attributes' => isset($case['expected_attributes']) ? $attributesPass : null,
                    'clarification' => isset($case['expected_clarification']) ? $clarificationPass : null,
                    'parts' => $parts === [] ? null : array_all($parts, fn (array $part): bool => $part['passed']),
                    'required_text' => array_all($case['contains'] ?? [], fn (string $needle): bool => str_contains($answer, $needle)),
                    'forbidden_text' => array_all($case['not_contains'] ?? [], fn (string $needle): bool => ! str_contains($answer, $needle)),
                ];
                $labeledChecks = [];
                foreach ($case['expected_turns'] ?? [] as $expected) {
                    $expectedTurn = $expected['turn'] - 1;
                    $actual = $turns[$expectedTurn]['state'];
                    unset($expected['turn']);
                    $labeledChecks[] = $evaluation->matches($expected, $actual);
                    $stateChecks[] = end($labeledChecks);
                    $languageStateChecks[$case['language']][] = end($labeledChecks);
                    if (isset($expected['intent'])) {
                        $intentLabels[] = ['expected' => $expected['intent'], 'actual' => $actual['intent']];
                        $languageIntentLabels[$case['language']][] = end($intentLabels);
                        $observed = $turns[$expectedTurn]['understanding'];
                        if (isset($observed['intent'])) {
                            $modelIntentLabels[] = ['expected' => $expected['intent'], 'actual' => $observed['intent']];
                        }
                    }
                }
                $checks['intent_state'] = $labeledChecks === [] ? null : ! in_array(false, $labeledChecks, true);
                $passed = ! in_array(false, $checks, true);
                $allPassed = $allPassed && $passed;
                $review = $reviews[$case['id'].':'.$run] ?? $reviews[$case['id']] ?? null;
                $reviewed = is_array($review) && ($review['answer_hash'] ?? null) === $hash
                    && is_bool($review['grounded'] ?? null) && is_bool($review['privacy_pass'] ?? null)
                    && is_int($review['language_score'] ?? null) && $review['language_score'] >= 1 && $review['language_score'] <= 5;
                $language = $case['language'];
                $summary[$language] ??= ['cases' => 0, 'passed' => 0, 'reviewed' => 0, 'grounded' => 0, 'privacy_failures' => 0, 'language_score_total' => 0,
                    'routing_labeled' => 0, 'routing_passed' => 0, 'attributes_labeled' => 0, 'attributes_passed' => 0, 'clarification_labeled' => 0, 'clarification_passed' => 0,
                    'parts_labeled' => 0, 'parts_passed' => 0, 'failure_counts' => [],
                    'turns' => 0, 'generated_turns' => 0, 'fallback_turns' => 0, 'failed_turns' => 0,
                    'provider_calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'quality_reviews' => 0,
                    'directness_total' => 0, 'completeness_total' => 0, 'naturalness_total' => 0, 'unnecessary_questions' => 0, 'fluent_reviewed' => 0, 'turn_outcomes' => []];
                $summary[$language]['cases']++;
                foreach ($turns as $turn) {
                    $summary[$language]['turn_outcomes'][$turn['outcome']] = ($summary[$language]['turn_outcomes'][$turn['outcome']] ?? 0) + 1;
                    $summary[$language]['turns']++;
                    $summary[$language]['generated_turns'] += (int) ($turn['source'] === 'gemini');
                    $summary[$language]['fallback_turns'] += (int) ($turn['source'] === 'fallback');
                    $summary[$language]['failed_turns'] += (int) ($turn['failure'] !== null && ! in_array($turn['failure']['reason'], ['clarification', 'no_match'], true));
                    foreach (['calls' => 'provider_calls', 'input_tokens' => 'input_tokens', 'output_tokens' => 'output_tokens'] as $usage => $metric) {
                        $summary[$language][$metric] += $turn['usage'][$usage];
                    }
                }
                $qualityReviewed = $reviewed && is_bool($review['unnecessary_question'] ?? null)
                    && array_all(['directness', 'completeness', 'naturalness'], fn (string $metric): bool => is_int($review[$metric] ?? null) && $review[$metric] >= 1 && $review[$metric] <= 5);
                if ($qualityReviewed) {
                    $summary[$language]['quality_reviews']++;
                    foreach (['directness', 'completeness', 'naturalness'] as $metric) {
                        $summary[$language][$metric.'_total'] += $review[$metric];
                    }
                    $summary[$language]['unnecessary_questions'] += (int) $review['unnecessary_question'];
                }
                foreach ($checks as $check => $pass) {
                    if ($pass === false) {
                        $summary[$language]['failure_counts'][$check] = ($summary[$language]['failure_counts'][$check] ?? 0) + 1;
                    }
                }
                $summary[$language]['parts_labeled'] += count($parts);
                $summary[$language]['parts_passed'] += count(array_filter($parts, fn (array $part): bool => $part['passed']));
                $summary[$language]['passed'] += (int) $passed;
                foreach (['routing' => ['expected_category', $routingPass], 'attributes' => ['expected_attributes', $attributesPass], 'clarification' => ['expected_clarification', $clarificationPass]] as $metric => [$field, $pass]) {
                    $summary[$language][$metric.'_labeled'] += (int) isset($case[$field]);
                    $summary[$language][$metric.'_passed'] += (int) (isset($case[$field]) && $pass);
                }
                if ($reviewed) {
                    $fluentReviewed = ($review['fluent_speaker'] ?? false) === true && ($review['reviewed_language'] ?? null) === $language
                        && is_string($review['reviewer_id'] ?? null) && preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D', $review['reviewer_id']);
                    $summary[$language]['fluent_reviewed'] += (int) $fluentReviewed;
                    $summary[$language]['reviewed']++;
                    $summary[$language]['grounded'] += (int) $review['grounded'];
                    $summary[$language]['privacy_failures'] += (int) ! $review['privacy_pass'];
                    $summary[$language]['language_score_total'] += $review['language_score'];
                }
                $results[] = ['id' => $case['id'], 'run' => $run, 'language' => $language, 'questions' => $case['messages'], 'passed' => $passed, 'checks' => $checks, 'parts' => $parts, 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'answer_hash' => $hash, 'human_reviewed' => $reviewed, 'turns' => $turns];
            }
        } finally {
            config(['ai_integration' => $original]);
        }
        $catalogStable = hash_equals($catalogFingerprint, $this->catalogFingerprint());
        foreach ($summary as $language => &$metrics) {
            $metrics['intent_macro_f1'] = $evaluation->macroF1($languageIntentLabels[$language] ?? []);
            $languageChecks = $languageStateChecks[$language] ?? [];
            $metrics['state_labeled_turns'] = count($languageChecks);
            $metrics['state_accuracy'] = $languageChecks !== [] ? count(array_filter($languageChecks)) / count($languageChecks) : null;
            $metrics['fallback_rate'] = $metrics['fallback_turns'] / $metrics['turns'];
            $metrics['failure_rate'] = $metrics['failed_turns'] / $metrics['turns'];
            foreach (['directness', 'completeness', 'naturalness'] as $metric) {
                $metrics['average_'.$metric] = $metrics['quality_reviews'] > 0 ? $metrics[$metric.'_total'] / $metrics['quality_reviews'] : null;
            }
            $metrics['unnecessary_question_rate'] = $metrics['quality_reviews'] > 0 ? $metrics['unnecessary_questions'] / $metrics['quality_reviews'] : null;
            $metrics['pass_rate'] = $metrics['passed'] / $metrics['cases'];
            foreach (['routing', 'attributes', 'clarification'] as $metric) {
                $metrics[$metric.'_accuracy'] = $metrics[$metric.'_labeled'] > 0 ? $metrics[$metric.'_passed'] / $metrics[$metric.'_labeled'] : null;
            }
            $metrics['grounding_rate'] = $metrics['reviewed'] > 0 ? $metrics['grounded'] / $metrics['reviewed'] : null;
            $metrics['part_coverage_rate'] = $metrics['parts_labeled'] > 0 ? $metrics['parts_passed'] / $metrics['parts_labeled'] : null;
            $metrics['average_language_score'] = $metrics['reviewed'] > 0 ? $metrics['language_score_total'] / $metrics['reviewed'] : null;
            $durations = [];
            $caseHashes = [];
            foreach ($results as $result) {
                if ($result['language'] === $language) {
                    $durations[] = $result['duration_ms'];
                    $caseHashes[$result['id']][] = $result['answer_hash'];
                }
            }
            sort($durations);
            $metrics['p95_conversation_ms'] = $durations[(int) ceil(count($durations) * 0.95) - 1];
            $turnDurations = [];
            foreach ($results as $result) {
                if ($result['language'] === $language) {
                    array_push($turnDurations, ...array_column($result['turns'], 'duration_ms'));
                }
            }
            sort($turnDurations);
            $metrics['p95_turn_ms'] = $turnDurations[(int) ceil(count($turnDurations) * 0.95) - 1];
            $metrics['unique_cases'] = count($caseHashes);
            $metrics['repeat_consistency_rate'] = $runs > 1 ? count(array_filter($caseHashes, fn (array $hashes): bool => count(array_unique($hashes)) === 1)) / count($caseHashes) : null;
            $metrics['release_gate_passed'] = $metrics['unique_cases'] >= 50 && $metrics['reviewed'] === $metrics['cases']
                && $metrics['pass_rate'] >= 0.95 && $metrics['grounding_rate'] >= 0.90
                && $metrics['privacy_failures'] === 0 && $metrics['average_language_score'] >= 4;
            $metrics['accuracy_gate_passed'] = $metrics['release_gate_passed'] && $metrics['intent_macro_f1'] !== null
                && $metrics['intent_macro_f1'] >= 0.95 && $metrics['state_accuracy'] >= 0.98
                && $metrics['grounding_rate'] >= 0.95 && $metrics['average_completeness'] >= 4
                && $this->option('split') === 'held_out' && $this->option('catalog-version') !== 'unversioned'
                && $metrics['state_labeled_turns'] >= 50 && count($languageIntentLabels[$language] ?? []) >= 50
                && $metrics['fluent_reviewed'] === $metrics['cases'];
            $metrics['accuracy_gate_passed'] = $metrics['accuracy_gate_passed'] && $catalogStable;
        }
        unset($metrics);
        $pipelineFiles = [config_path('ai_integration.php')];
        foreach (['Services/Chatbot/*.php', 'Services/Chatbot/Retrieval/*.php', 'Ai/Agents/Chatbot*.php',
            'Actions/Chatbot/*.php', 'Actions/Chatbot/Context/*.php'] as $pattern) {
            $matches = glob(app_path($pattern));
            if ($matches === false) {
                $this->error('Evaluation pipeline files could not be inspected.');

                return self::FAILURE;
            }
            array_push($pipelineFiles, ...$matches);
        }
        sort($pipelineFiles, SORT_STRING);
        $pipelineHashes = [];
        foreach ($pipelineFiles as $path) {
            $fileHash = hash_file('sha256', $path);
            if ($fileHash === false) {
                $this->error('Evaluation pipeline files could not be fingerprinted.');

                return self::FAILURE;
            }
            $pipelineHashes[] = $fileHash;
        }
        $report = [
            'pipeline_version' => hash('sha256', implode('', $pipelineHashes)),
            'catalog_version' => $this->option('catalog-version'),
            'catalog_fingerprint' => $catalogFingerprint,
            'catalog_stable_before_after' => $catalogStable,
            'split' => $this->option('split'),
            'intent_macro_f1' => $evaluation->macroF1($intentLabels),
            'model_intent_macro_f1' => $evaluation->macroF1($modelIntentLabels),
            'model_understanding_coverage' => $intentLabels !== [] ? count($modelIntentLabels) / count($intentLabels) : null,
            'live_pause_ms' => $this->option('live') ? $pause : 0,
            'state_labeled_turns' => count($stateChecks),
            'state_accuracy' => $stateChecks !== [] ? count(array_filter($stateChecks)) / count($stateChecks) : null,
            'mode' => $this->option('live') ? 'live' : 'offline',
            'runs' => $runs,
            'dataset_hash' => hash('sha256', json_encode($dataset, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'configured_model' => $evaluatedConfiguration['gemini']['model'],
            'planned_text_call_ceiling' => $this->option('live') ? $plannedCalls : 0,
            'output_limit_hits' => array_sum(array_map(fn (array $result): int => array_sum(array_map(
                fn (array $turn): int => count(array_filter($turn['provider_responses'], fn (array $response): bool => $response['limit_reached'])), $result['turns'],
            )), $results)),
            'output_limit_note' => 'A token limit hit is a truncation indicator, not proof that content was omitted.',
            'configuration' => [
                'enabled' => $evaluatedConfiguration['enabled'], 'model_led' => $evaluatedConfiguration['model_led'],
                'full_catalog_pipeline' => $evaluatedConfiguration['full_catalog_pipeline'], 'multipart' => $evaluatedConfiguration['multipart'],
                'adaptive' => $evaluatedConfiguration['adaptive'], 'advice_enabled' => $evaluatedConfiguration['advice_enabled'],
            ],
            'cost' => null,
            'cost_note' => 'Token usage is measured; monetary cost requires verified provider billing rates.',
            'automated_checks' => ['response language metadata', 'labeled routing, attributes and clarification', 'case-specific required and forbidden text', 'turn-specific part text coverage (not semantic correctness)'],
            'human_review_required' => true, 'summary' => $summary, 'results' => $results,
        ];
        if ($this->option('prepare-reviews')) {
            $packet = [];
            foreach ($results as $result) {
                $packet[$result['id'].':'.$result['run']] = [
                    'answer_hash' => $result['answer_hash'], 'questions' => $result['questions'],
                    'answers' => array_column($result['turns'], 'message'), 'reviewed_language' => $result['language'],
                    'diagnostic_reasons' => array_map(fn (array $turn): array => ['draft' => $turn['draft_reason'], 'review' => $turn['review_reason']], $result['turns']),
                    'reviewer_id' => null, 'fluent_speaker' => null, 'grounded' => null, 'privacy_pass' => null,
                    'language_score' => null, 'directness' => null, 'completeness' => null, 'naturalness' => null, 'unnecessary_question' => null,
                ];
            }
            $path = 'chatbot-evaluations/review-'.hash('sha256', json_encode($packet, JSON_THROW_ON_ERROR)).'.json';
            if (! Storage::disk('local')->put($path, json_encode($packet, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
                $this->error('Unable to write the private review packet.');

                return self::FAILURE;
            }
            $report['review_packet'] = Storage::disk('local')->path($path);
        }
        $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $allPassed ? self::SUCCESS : self::FAILURE;
    }

    /** Fingerprint public catalog facts without retaining source rows. */
    private function catalogFingerprint(): string
    {
        $digest = hash_init('sha256');
        Product::query()->customerEligible()->select(['id', 'category_id', 'name', 'product_code', 'brand', 'description', 'price', 'discount_price'])
            ->with(['category:id,name,is_active', 'inventory:product_id,quantity,reorder_level', 'componentProfile:id,product_id,current_revision,review_status,updated_at'])
            ->chunkById(100, function (Collection $products) use ($digest): void {
                foreach ($products as $product) {
                    hash_update($digest, json_encode([
                        'product' => $product->only(['id', 'category_id', 'name', 'product_code', 'brand', 'description', 'price', 'discount_price']),
                        'category' => $product->category->only(['id', 'name', 'is_active']),
                        'inventory' => $product->inventory?->only(['quantity', 'reorder_level']),
                        'specification_revision' => $product->componentProfile?->only(['current_revision', 'review_status', 'updated_at']),
                    ], JSON_THROW_ON_ERROR));
                }
            });

        return hash_final($digest);
    }

    /** @return array<mixed>|null */
    private function readJson(string $path): ?array
    {
        try {
            if (! is_file($path) || filesize($path) > 2_000_000) {
                $this->error('Evaluation JSON must be an existing file smaller than 2 MB.');

                return null;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                $this->error('Evaluation JSON could not be read.');

                return null;
            }
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (JsonException) {
            $this->error('Evaluation JSON is invalid.');

            return null;
        }
    }
}
