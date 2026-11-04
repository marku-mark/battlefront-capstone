<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Actions\Chatbot\Context\ResolveChatbotRuleGuidance;
use App\Actions\Chatbot\Context\ResolveFaqContext;
use App\Actions\Chatbot\Context\ResolveStoreContext;
use App\Actions\Chatbot\RenderChatbotFallback;
use App\Enums\ChatbotQueryCategory;
use App\Models\Product;
use App\Models\User;

class ModelLedChatbot
{
    public function __construct(private ChatbotAiAdapter $ai, private ChatbotLanguage $language) {}

    public function eligible(string $message): bool
    {
        $boundary = app(ClassifyChatbotInquiry::class);

        return config('ai_integration.enabled') && config('ai_integration.model_led')
            && ! $boundary->containsSensitiveInput($message)
            && $boundary->execute($message)['category'] !== ChatbotQueryCategory::Order
            && preg_match('/\bbf[\s\W_]*\d+\b/iu', $message) !== 1;
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed> */
    public function respond(string $message, array $state, ?User $customer): array
    {
        $trace = app(ChatbotFailureTrace::class);
        $trace->path = 'model_led';
        $budget = app(ChatbotProviderBudget::class);
        $budget->reset();
        $started = hrtime(true);
        $language = $this->language->explicit($message) ?? $state['language_preference'] ?? $this->language->select($message, $state['language'] ?? null);
        $context = [];
        $category = ChatbotQueryCategory::Product;
        $source = 'fallback';
        $text = 'The chatbot is temporarily unavailable. Please try again later.';
        try {
            $pendingCatalog = $state['pending_catalog'] ?? null;
            unset($state['pending_catalog']);
            $previous = Product::query()->customerEligible()->whereIn('id', array_slice($state['references']['product'] ?? [], 0, 6))->get(['id', 'name'])->keyBy('id');
            $publicProducts = [];
            foreach (array_slice($state['references']['product'] ?? [], 0, 6) as $id) {
                if ($previous->has($id)) {
                    $publicProducts[] = ['name' => $previous[$id]->name];
                }
            }
            $publicConstraints = array_intersect_key($state['model_constraints'] ?? [], array_flip(['category_key', 'product_role', 'brand', 'capacity', 'use_case', 'excluded_brands', 'price']));
            $public = ['language' => $language, 'language_preference' => $state['language_preference'] ?? null, 'products' => $publicProducts, 'constraints' => $publicConstraints];
            $intent = $this->resolvePendingCpuClarification($message, $state);
            if ($intent === null) {
                unset($state['pending_clarification']);
                $intent = $this->ai->understand($message, $public);
            }
            if ($intent !== null && is_array($pendingCatalog)) {
                $resumed = $this->resumePendingCatalog($message, $intent, $pendingCatalog);
                if ($resumed !== null) {
                    $message = $resumed['message'];
                    $intent = $resumed['intent'];
                }
            }
            if ($intent === null && $trace->event === null) {
                $trace->record('understanding_failed', 'scope');
            }
            if ($intent !== null) {
                $trace->scope = $intent['outcome'] === 'unsupported' ? 'unsupported' : ($intent['intent'] === 'private' ? 'private' : 'public');
                $trace->publicScopeConfirmed = $trace->scope === 'public';
                $language = $this->language->explicit($message) ?? $state['language_preference'] ?? ($intent['language'] === 'und' ? $language : $intent['language']);
                if ($intent['outcome'] === 'unsupported') {
                    $text = 'I can only help with Battlefront products, your orders, store information, payment methods, pickup, and delivery.';
                } elseif ($intent['outcome'] === 'clarify' || $intent['clarification'] !== 'none') {
                    $trace->record('clarification', 'scope');
                    if ($intent['clarification'] === 'cpu_meaning') {
                        $state['pending_clarification'] = 'cpu_meaning';
                    } else {
                        unset($state['pending_clarification']);
                    }
                    $text = $intent['clarification'] === 'cpu_meaning'
                        ? 'By CPU, do you mean a processor or a complete desktop system unit?'
                        : 'Please provide a product name or model, or clarify your Battlefront question.';
                } elseif ($intent['intent'] === 'private') {
                    $category = ChatbotQueryCategory::Order;
                    $text = $customer === null ? 'Please sign in with your customer account to check your order status.' : 'Which order do you mean? Please provide its BF order reference from your order history.';
                } elseif ($intent['intent'] === 'greeting') {
                    $text = 'Please enter a question about Battlefront products, orders, stores, payment methods, pickup, or delivery.';
                } else {
                    if ($intent['intent'] === 'store') {
                        $category = ChatbotQueryCategory::Store;
                        $context = app(ResolveStoreContext::class)->execute($intent['search_query']);
                    } elseif ($intent['intent'] === 'support') {
                        $category = ChatbotQueryCategory::Faq;
                        $rule = app(ClassifyChatbotInquiry::class)->execute($intent['search_query'])['rule_topic'];
                        $context = $rule !== null ? app(ResolveChatbotRuleGuidance::class)->execute($rule) : app(ResolveFaqContext::class)->execute($intent['search_query'], $language);
                    } else {
                        $context = $this->catalog($message, $intent, $state);
                    }
                    if (isset($context['clarification'])) {
                        $trace->record('clarification', 'retrieval');
                        $pending = $this->pendingCatalog($message, $intent, $context['clarification']);
                        if ($pending !== null) {
                            $state['pending_catalog'] = $pending;
                        }
                    } elseif (($context['products'] ?? []) === [] && ($context['branches'] ?? []) === [] && ($context['knowledge'] ?? []) === [] && ! isset($context['guidance'])) {
                        $trace->record('no_match', 'retrieval');
                    }
                    $fallback = $context === [] ? null : app(RenderChatbotFallback::class)->execute($category, $intent['search_query'], $context);
                    $text = $context['clarification'] ?? $fallback ?? match ($category) {
                        ChatbotQueryCategory::Store => 'Store information is currently unavailable. Please try again later.',
                        ChatbotQueryCategory::Faq => 'Approved information for that question is currently unavailable.',
                        default => "I couldn't find a matching product in the current Battlefront catalog. Please try the product name, brand, category, or tag.",
                    };
                    if ($context !== [] && (($context['products'] ?? []) !== [] || ($context['branches'] ?? []) !== [] || ($context['knowledge'] ?? []) !== [] || isset($context['guidance']) || isset($context['compatibility']))) {
                        $context['interpreted_intent'] = ['focus' => $intent['intent'], 'requested_attributes' => $intent['attributes'], 'maximum_php' => $intent['maximum_php'], 'excluded_terms' => $intent['excluded_brands'], 'price_selection' => $context['price_selection'] ?? null];
                        $draft = $this->ai->grounded($message, $context, $language, scopeChecked: true);
                        if ($draft['successful']) {
                            $text = $draft['text'];
                            $source = 'gemini';
                        } elseif ($trace->event === null) {
                            $trace->record($draft['failure'] ?? 'provider_unavailable', ($draft['failure'] ?? null) === 'validation_failed' ? 'validation' : 'generation');
                        }
                    }
                }
                if ($intent['reference'] === 'new' && ! isset($context['clarification'])) {
                    unset($state['references']['product'], $state['model_constraints']);
                }
                if ($intent['clarification'] === 'none') {
                    unset($state['pending_clarification']);
                }
                if ($intent['outcome'] === 'supported' && ! isset($context['clarification'])) {
                    $state['model_constraints'] = $context['selection_constraints'] ?? array_intersect_key($intent, array_flip(['category_key', 'maximum_php', 'excluded_brands', 'brand', 'capacity', 'use_case']));
                }
            }
            $state['language'] = $language;
            if ($this->language->explicit($message) !== null) {
                $state['language_preference'] = $language;
            }
            $state['active'] = $category->value;
            if (($context['products'] ?? []) !== []) {
                $state['references']['product'] = array_slice(array_column($context['products'], 'id'), 0, 6);
            }
            if ($source === 'fallback') {
                $text = app(ChatbotLocalizedResponse::class)->text($text, $language, array_column($context['products'] ?? [], 'name'));
            }
            $result = ['category' => $category, 'message' => $text, 'source' => $source];

            return [...$result, 'state' => $state, 'presentation' => app(ChatbotPresentation::class)->build($result, $context, $message, $customer, $language)];
        } finally {
            $budget->recordTurn((int) ((hrtime(true) - $started) / 1_000_000), $source);
            $budget->releaseTurn();
        }
    }

    /** @param array<string, mixed> $intent
     * @param array<string, mixed> $state
     * @return array<string, mixed> */
    private function catalog(string $message, array $intent, array $state): array
    {
        if ($intent['intent'] === 'compatibility') {
            $selection = app(ChatbotCatalogSelection::class)->select($message, $intent, $state);
            if (isset($selection['clarification'])) {
                return $selection;
            }

            return app(ChatbotCatalogAdvice::class)->answer('compatible '.implode(' and ', array_column($selection['products'], 'name')))['context'];
        }

        return app(ChatbotCatalogSelection::class)->select($message, $intent, $state);
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private function resolvePendingCpuClarification(string $message, array $state): ?array
    {
        if (($state['pending_clarification'] ?? null) !== 'cpu_meaning') {
            return null;
        }

        $answer = $this->language->normalized($message);
        $category = match (true) {
            preg_match('/\A(?:(?:a|ang|the) )?processor\z/u', $answer) === 1 => 'processor',
            preg_match('/\A(?:(?:a|the|complete) )?(?:desktop|system unit|desktop system unit)\z/u', $answer) === 1 => 'desktop',
            default => null,
        };
        if ($category === null) {
            return null;
        }

        return [
            'outcome' => 'supported',
            'intent' => 'discovery',
            'language' => $state['language_preference'] ?? $state['language'] ?? 'en',
            'category_key' => $category,
            'search_query' => $category === 'processor' ? 'processor' : 'desktop',
            'product_mentions' => [],
            'attributes' => ['stock'],
            'sort' => 'relevance',
            'minimum_php' => null,
            'minimum_inclusive' => true,
            'maximum_inclusive' => true,
            'price_scope' => 'catalog',
            'product_role' => 'main',
            'maximum_php' => null,
            'excluded_brands' => [],
            'brand' => '',
            'capacity' => '',
            'use_case' => '',
            'reference' => 'new',
            'position' => 0,
            'clarification' => 'none',
        ];
    }

    /** @param array<string, mixed> $intent
     * @return array{intent: array<string, mixed>, message: string}|null
     */
    private function resumePendingCatalog(string $message, array $intent, array $pending): ?array
    {
        $original = $pending['intent'] ?? null;
        if (! is_array($original)
            || $intent['outcome'] !== 'supported'
            || ! in_array($intent['intent'], ['discovery', 'facts', 'comparison', 'recommendation', 'compatibility'], true)
            || ! is_array($intent['product_mentions'] ?? null)) {
            return null;
        }

        $mentions = array_values(array_filter($intent['product_mentions'], fn (mixed $mention): bool => is_string($mention)
            && $mention !== '' && mb_stripos($message, $mention) !== false));
        if ($mentions === [] || ($original['intent'] !== 'compatibility' && $intent['category_key'] !== $original['category_key'])) {
            return null;
        }

        $selectedMentions = ($pending['alternatives'] ?? false) === true
            ? $mentions
            : array_values(array_unique([...($original['product_mentions'] ?? []), ...$mentions]));
        $resumedIntent = [...$original,
            'outcome' => 'supported',
            'product_mentions' => $selectedMentions,
            'reference' => 'new',
            'position' => 0,
            'clarification' => 'none',
            'language' => $intent['language'],
        ];

        if ($original['intent'] === 'compatibility') {
            $query = 'compatible '.implode(' and ', $selectedMentions);
        } elseif (($pending['alternatives'] ?? false) === true) {
            $prefix = ($pending['cheaper'] ?? false) ? 'cheaper alternatives to ' : 'alternatives to ';
            $query = $prefix.implode(' and ', $selectedMentions);
        } else {
            $query = trim($original['search_query'].' '.implode(' ', $selectedMentions));
        }

        return ['intent' => $resumedIntent, 'message' => $query];
    }

    /** @param array<string, mixed> $intent
     * @return array{intent: array<string, mixed>, alternatives: bool, cheaper: bool}|null
     */
    private function pendingCatalog(string $message, array $intent, string $clarification): ?array
    {
        $requiresProduct = str_contains(mb_strtolower($clarification), 'which product do you mean')
            || str_contains(mb_strtolower($clarification), 'identify one exact product for alternatives');
        if (! $requiresProduct) {
            return null;
        }

        return [
            'intent' => $intent,
            'alternatives' => preg_match('/\b(?:alternatives?|similar to|cheaper|more affordable|mas mura|mas barato|mas baratu)\b/iu', $message) === 1,
            'cheaper' => preg_match('/\b(?:cheaper|more affordable|mas mura|mas barato|mas baratu)\b/iu', $message) === 1,
        ];
    }
}
