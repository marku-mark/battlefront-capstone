<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ChatbotConversationToken;
use App\Services\Chatbot\ChatbotLanguage;
use App\Services\Chatbot\ChatbotOrchestrationService;
use App\Services\Chatbot\ChatbotProviderBudget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonException;

#[Signature('chatbot:evaluate {--dataset= : JSON cases; defaults to the bundled multilingual corpus} {--live : Explicitly allow configured public provider calls} {--reviews= : JSON human reviews bound to answer hashes}')]
#[Description('Evaluate public multilingual conversations and emit a JSON report; offline by default')]
class EvaluateChatbot extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ChatbotConversation $chatbot, ChatbotOrchestrationService $boundary): int
    {
        $dataset = $this->readJson($this->option('dataset') ?: resource_path('data/chatbot-evaluation.json'));
        $reviews = $this->option('reviews') ? $this->readJson($this->option('reviews')) : [];
        if ($dataset === null || $reviews === null) {
            return self::FAILURE;
        }
        $validator = Validator::make(['cases' => $dataset], [
            'cases' => ['required', 'array', 'min:1', 'max:1000'],
            'cases.*' => ['array:id,language,messages,contains,not_contains,expected_category,expected_attributes,expected_clarification'],
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
        ]);
        if ($validator->fails()) {
            $this->error('Invalid evaluation dataset: '.$validator->errors()->first());

            return self::FAILURE;
        }
        foreach ($dataset as $case) {
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
        config(['ai_integration.offline' => ! $this->option('live'), 'ai_integration.memory.enabled' => false]);
        if (! $this->option('live')) {
            config(['ai_integration.enabled' => false]);
        }
        $results = [];
        $summary = [];
        $allPassed = true;
        try {
            foreach ($dataset as $case) {
                $context = null;
                $turns = [];
                $started = hrtime(true);
                $response = null;
                foreach ($case['messages'] as $message) {
                    app(ChatbotProviderBudget::class)->reset();
                    $turnStarted = hrtime(true);
                    $response = $chatbot->respond($message, null, $context, 'evaluation:'.$case['id']);
                    $context = $response['context_token'];
                    $turns[] = ['message' => $response['message'], 'language' => $response['language'], 'source' => $response['source'], 'duration_ms' => (int) ((hrtime(true) - $turnStarted) / 1_000_000), 'usage' => app(ChatbotProviderBudget::class)->usage()];
                }
                if ($response === null) {
                    throw new \RuntimeException('An evaluation case must contain at least one turn.');
                }
                $answer = $response['message'];
                $state = app(ChatbotConversationToken::class)->decode($context, 'evaluation:'.$case['id'].':guest');
                $actualCategory = $state['active'] ?? 'unsupported';
                $attributes = app(ChatbotLanguage::class)->interpret($message)['attributes'];
                $clarification = ($response['presentation']['choices'] ?? []) !== [];
                $routingPass = ! isset($case['expected_category']) || $case['expected_category'] === $actualCategory;
                $attributesPass = ! isset($case['expected_attributes']) || $case['expected_attributes'] === $attributes;
                $clarificationPass = ! isset($case['expected_clarification']) || $case['expected_clarification'] === $clarification;
                $hash = hash('sha256', json_encode(['messages' => $case['messages'], 'turns' => array_map(
                    fn (array $turn): array => ['message' => $turn['message'], 'language' => $turn['language'], 'source' => $turn['source']], $turns,
                )], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $passed = $response['language'] === $case['language'] && $routingPass && $attributesPass && $clarificationPass;
                foreach ($case['contains'] ?? [] as $needle) {
                    $passed = $passed && str_contains($answer, $needle);
                }
                foreach ($case['not_contains'] ?? [] as $needle) {
                    $passed = $passed && ! str_contains($answer, $needle);
                }
                $allPassed = $allPassed && $passed;
                $review = $reviews[$case['id']] ?? null;
                $reviewed = is_array($review) && ($review['answer_hash'] ?? null) === $hash
                    && is_bool($review['grounded'] ?? null) && is_bool($review['privacy_pass'] ?? null)
                    && is_int($review['language_score'] ?? null) && $review['language_score'] >= 1 && $review['language_score'] <= 5;
                $language = $case['language'];
                $summary[$language] ??= ['cases' => 0, 'passed' => 0, 'reviewed' => 0, 'grounded' => 0, 'privacy_failures' => 0, 'language_score_total' => 0,
                    'routing_labeled' => 0, 'routing_passed' => 0, 'attributes_labeled' => 0, 'attributes_passed' => 0, 'clarification_labeled' => 0, 'clarification_passed' => 0];
                $summary[$language]['cases']++;
                $summary[$language]['passed'] += (int) $passed;
                foreach (['routing' => ['expected_category', $routingPass], 'attributes' => ['expected_attributes', $attributesPass], 'clarification' => ['expected_clarification', $clarificationPass]] as $metric => [$field, $pass]) {
                    $summary[$language][$metric.'_labeled'] += (int) isset($case[$field]);
                    $summary[$language][$metric.'_passed'] += (int) (isset($case[$field]) && $pass);
                }
                if ($reviewed) {
                    $summary[$language]['reviewed']++;
                    $summary[$language]['grounded'] += (int) $review['grounded'];
                    $summary[$language]['privacy_failures'] += (int) ! $review['privacy_pass'];
                    $summary[$language]['language_score_total'] += $review['language_score'];
                }
                $results[] = ['id' => $case['id'], 'language' => $language, 'passed' => $passed, 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'answer_hash' => $hash, 'human_reviewed' => $reviewed, 'turns' => $turns];
            }
        } finally {
            config(['ai_integration' => $original]);
        }
        foreach ($summary as $language => &$metrics) {
            $metrics['pass_rate'] = $metrics['passed'] / $metrics['cases'];
            foreach (['routing', 'attributes', 'clarification'] as $metric) {
                $metrics[$metric.'_accuracy'] = $metrics[$metric.'_labeled'] > 0 ? $metrics[$metric.'_passed'] / $metrics[$metric.'_labeled'] : null;
            }
            $metrics['grounding_rate'] = $metrics['reviewed'] > 0 ? $metrics['grounded'] / $metrics['reviewed'] : null;
            $metrics['average_language_score'] = $metrics['reviewed'] > 0 ? $metrics['language_score_total'] / $metrics['reviewed'] : null;
            $durations = [];
            foreach ($results as $result) {
                if ($result['language'] === $language) {
                    $durations[] = $result['duration_ms'];
                }
            }
            sort($durations);
            $metrics['p95_conversation_ms'] = $durations[(int) ceil(count($durations) * 0.95) - 1];
            $metrics['release_gate_passed'] = $metrics['cases'] >= 50 && $metrics['reviewed'] === $metrics['cases']
                && $metrics['pass_rate'] >= 0.95 && $metrics['grounding_rate'] >= 0.90
                && $metrics['privacy_failures'] === 0 && $metrics['average_language_score'] >= 4;
        }
        unset($metrics);
        $this->line(json_encode(['mode' => $this->option('live') ? 'live' : 'offline', 'automated_checks' => ['response language metadata', 'case-specific required and forbidden text'], 'human_review_required' => true, 'summary' => $summary, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $allPassed ? self::SUCCESS : self::FAILURE;
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
