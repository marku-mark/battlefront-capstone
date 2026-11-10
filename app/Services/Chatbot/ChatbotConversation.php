<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\CategorizeChatbotQuery;
use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Actions\Chatbot\RenderChatbotFallback;
use App\Actions\Chatbot\RouteChatbotQuery;
use App\Enums\ChatbotQueryCategory;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Catalog\CatalogSearchIntent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ChatbotConversation
{
    public function __construct(
        private ChatbotOrchestrationService $chatbot,
        private ChatbotConversationToken $tokens,
        private RouteChatbotQuery $router,
        private CategorizeChatbotQuery $categorizer,
        private ResolveProductContext $products,
        private RenderChatbotFallback $fallbackRenderer,
        private ChatbotLanguage $language = new ChatbotLanguage,
        private ChatbotMemory $memory = new ChatbotMemory,
    ) {}

    /** @return array{message: string, source: string, context_token: string|null, language: string, presentation: array<string, mixed>} */
    public function respond(string $message, ?User $customer, ?string $token, string $contextScope): array
    {
        $trace = app(ChatbotFailureTrace::class);
        $trace->reset();
        $started = hrtime(true);
        try {
            $response = $this->respondWithPresentation($message, $customer, $token, $contextScope);
        } catch (Throwable $exception) {
            $trace->record('application_error', 'application');
            app(ChatbotFailureRecorder::class)->capture($message, '', $this->language->select($message), [], (int) ((hrtime(true) - $started) / 1_000_000));
            throw $exception;
        }
        $state = $this->tokens->decode($response['context_token'], $contextScope.':'.($customer?->getKey() ?? 'guest'));
        app(ChatbotFailureRecorder::class)->capture($message, $response['message'], $response['language'], $state['references']['product'] ?? [], (int) ((hrtime(true) - $started) / 1_000_000));

        return $response;
    }

    /** @return array{message: string, source: string, context_token: string|null, language: string, presentation: array<string, mixed>} */
    private function respondWithPresentation(string $message, ?User $customer, ?string $token, string $contextScope): array
    {
        $response = $this->respondToMessage($message, $customer, $token, $contextScope);
        $subject = $contextScope.':'.($customer?->getKey() ?? 'guest');
        $responseState = $this->tokens->decode($response['context_token'], $subject);
        $response['language'] = $responseState['language'] ?? $this->language->select($message);
        $response['presentation'] ??= app(ChatbotPresentation::class)->build([
            'message' => $response['message'],
            'category' => ChatbotQueryCategory::tryFrom($responseState['active'] ?? '') ?? ChatbotQueryCategory::Unsupported,
        ], [], $message, $customer, $response['language']);
        foreach ($responseState['pending'] ?? [] as $topic => $question) {
            $response['presentation']['choices'][] = ['label' => str_replace(['faq:', '_'], ['', ' '], $topic), 'question' => $question];
        }
        if (isset($responseState['product_clarification']) && $response['presentation']['choices'] === []) {
            foreach ($this->products->clarificationChoices($responseState['references']['product'] ?? []) as $product) {
                $response['presentation']['choices'][] = ['label' => $product['name'], 'question' => $product['name']];
            }
        }
        if ($this->memory->enabled($subject) && $response['context_token'] !== null) {
            $state = $this->tokens->decode($response['context_token'], $subject);
            try {
                $this->memory->recordTurn($state, $subject, $message, $response['message']);
            } catch (QueryException) {
                Log::warning('Chatbot memory unavailable', ['operation' => 'history']);
            }
        }

        return $response;
    }

    /** @return array{message: string, source: string, context_token: string|null, presentation?: array<string, mixed>|null} */
    private function respondToMessage(string $message, ?User $customer, ?string $token, string $contextScope): array
    {
        $subject = $contextScope.':'.($customer?->getKey() ?? 'guest');
        $state = $this->tokens->decode($token, $subject);
        $modelLed = app(ModelLedChatbot::class);
        if ($modelLed->eligible($message, $state) && ! (trim($message) !== '' && $this->language->withoutLanguageRequest($message) === '')) {
            $result = $modelLed->respond($message, $state, $customer);

            return ['message' => $result['message'], 'source' => $result['source'], 'context_token' => $this->tokens->encode($result['state'], $subject), 'presentation' => $result['presentation']];
        }
        $branchAvailability = app(ChatbotBranchAvailability::class);
        if (! $this->chatbot->containsSensitiveInput($message) && $branchAvailability->supports($message, $state)) {
            return $this->respondToBranch($message, $customer, $state, $subject);
        }
        if (($state['model_constraints']['intent'] ?? null) === 'branch_availability'
            && (app(CatalogSearchIntent::class)->resolve($this->language->canonical($message)) !== null || $this->products->namesProduct($message))) {
            unset($state['model_constraints'], $state['pending_catalog']);
        }
        $message = Str::squish($message);
        $languageMessage = $message;
        if (preg_match('/^(?:please )?(?:explain (?:that|it)(?: more)? simply|say (?:that|it) again|rephrase(?: that)?|simplify(?: that)?)[.!?]*$/iu', $message) === 1) {
            if (is_string($state['last_question'] ?? null)) {
                $message = $state['last_question'];
            } else {
                return $this->fallback('Please tell me which product, branch, order or support question you would like help with.', $state, $subject);
            }
        }
        $canonical = $this->language->canonical($message);
        $constraintFollowUp = false;
        $interpreted = $this->language->interpret($message);
        $removesBudget = preg_match('/\b(?:no budget(?: limit)?|any price|remove (?:the )?budget)\b/iu', $canonical) === 1;
        $restoresBrand = preg_match('/\b(intel|amd|nvidia)\s+(?:is\s+)?(?:okay|ok|fine|allowed)\s+(?:now|again)?\b/iu', $canonical, $restoredBrand) === 1;
        if (($state['active'] ?? null) === 'product' && config('ai_integration.advice_enabled')
            && ($interpreted['maximum_php'] !== null || $interpreted['excluded_brands'] !== [] || $removesBudget || $restoresBrand)
            && (preg_match('/^(?:(?:my|the|actually|make it|what about|and|i meant)\s+)*(?:budget|under|below|not|without|exclude|except)\b/iu', $canonical) === 1 || $removesBudget || $restoresBrand)) {
            $previous = Product::query()->customerEligible()->with('category')
                ->whereIn('id', array_slice($state['references']['product'] ?? [], 0, 6))->get();
            $categoryName = $previous->isNotEmpty() && $previous->pluck('category_id')->unique()->count() === 1
                ? $previous->first()->category->name : ($state['catalog_constraints']['category'] ?? null);
            if (is_string($categoryName)) {
                $constraintFollowUp = true;
                if ($interpreted['maximum_php'] !== null) {
                    $state['catalog_constraints']['maximum_php'] = $interpreted['maximum_php'];
                }
                if ($removesBudget) {
                    unset($state['catalog_constraints']['maximum_php']);
                }
                if ($interpreted['excluded_brands'] !== []) {
                    $state['catalog_constraints']['excluded_brands'] = $interpreted['excluded_brands'];
                }
                if ($restoresBrand) {
                    $state['catalog_constraints']['excluded_brands'] = array_values(array_filter(
                        $state['catalog_constraints']['excluded_brands'] ?? [],
                        fn (string $brand): bool => mb_strtolower($brand) !== mb_strtolower($restoredBrand[1]),
                    ));
                }
                $message = 'recommend '.$categoryName;
                if (isset($state['catalog_constraints']['maximum_php'])) {
                    $message .= ' under PHP '.$state['catalog_constraints']['maximum_php'];
                }
                foreach ($state['catalog_constraints']['excluded_brands'] ?? [] as $brand) {
                    $message .= ', not '.$brand;
                }
                unset($state['product_clarification']);
            }
        }
        if (($state['active'] ?? null) === 'product'
            && preg_match('/\b(?:first|second|third|1st|2nd|3rd)\s+(?:one|option|product)|^(?:first|second|third|1st|2nd|3rd)[.!?]*$/iu', $canonical, $ordinal) === 1) {
            $position = preg_match('/first|1st/iu', $ordinal[0]) ? 0 : (preg_match('/second|2nd/iu', $ordinal[0]) ? 1 : 2);
            $id = $state['references']['product'][$position] ?? null;
            $selected = $id === null ? null : Product::query()->customerEligible()->whereKey($id)->first();
            if ($selected === null) {
                return $this->fallback('Which product do you mean? Please provide its name or model.', $state, $subject);
            }
            $message = preg_replace('/\b(?:first|second|third|1st|2nd|3rd)(?:\s+(?:one|option|product))?/iu', $selected->name, $canonical) ?? $canonical;
            unset($state['product_clarification']);
        }
        if (($state['active'] ?? null) === 'product'
            && preg_match('/^\d+(?:\.\d+)?\s*(?:TB|GB)\s*[?.!]*$/iu', $message) === 1) {
            $previous = Product::query()->customerEligible()->with('category')
                ->whereIn('id', array_slice($state['references']['product'] ?? [], 0, 6))->get();
            if ($previous->isNotEmpty() && $previous->pluck('category_id')->unique()->count() === 1) {
                $message = $previous->first()->category->name.' '.trim($message, '?.!');
                unset($state['product_clarification']);
            }
        }
        $advice = app(ChatbotCatalogAdvice::class)->supports($message);
        if (($state['active'] ?? null) === 'faq' && is_string($state['faq_question'] ?? null)
            && ! $this->chatbot->containsSensitiveInput($message)
            && preg_match('/\bbf[\s\W_]*\d+\b/iu', $message) !== 1) {
            $previous = $state['faq_question'];
            $followUp = match ($previous) {
                'How do I use PC Builder?' => preg_match('/\bcomponents?\b/iu', $message) === 1,
                'How do I select components in PC Builder?' => preg_match('/\b(?:change|selection|usbon|palitan|ilisan|gipili|napili|ginpili)\b/iu', $message) === 1,
                'How do saved PC builds work?' => preg_match('/(?:reserv|reserb).*stock|stock.*(?:reserv|reserb)/iu', $message) === 1,
                'How do PC Builder templates work?' => preg_match('/final.*(?:price|presyo)|(?:price|presyo).*final|saved.*budget/iu', $message) === 1,
                'Where can I view PC Builder compatibility results?' => preg_match('/\b(?:unknown|draft|warning)\b|missing.*evidence|kulang.*ebidensya/iu', $message) === 1,
                'How do I search for products?' => preg_match('/\b(?:filter|brand|category|tag|salain)\b/iu', $message) === 1,
                "How can I view a product's details?" => preg_match('/\b(?:where|asa|diin|saan)\b.*\b(?:price|presyo|details)\b/iu', $message) === 1,
                'How can I check my order status?' => preg_match('/\b(?:where|asa|diin|saan)\b.*\bhistory\b/iu', $message) === 1,
                'Do you offer real-time delivery tracking?' => preg_match('/live.*(?:courier|location)|(?:courier|location).*live/iu', $message) === 1,
                default => false,
            };
            if ($followUp) {
                $message = $previous === 'How do I use PC Builder?'
                    ? 'How do I select components in PC Builder?' : $previous;
            }
        }
        if ($advice) {
            $interpreted = $this->language->interpret($message);
            if ($interpreted['excluded_brands'] !== []) {
                $state['catalog_constraints']['excluded_brands'] = $interpreted['excluded_brands'];
            }
            if ($interpreted['maximum_php'] !== null) {
                $state['catalog_constraints']['maximum_php'] = $interpreted['maximum_php'];
            }
        }

        if (! $this->chatbot->containsSensitiveInput($message)) {
            $explicitLanguage = $this->language->explicit($languageMessage);
            if ($explicitLanguage !== null) {
                $state['language_preference'] = $explicitLanguage;
            }
            $state['language'] = $state['language_preference'] ?? $this->language->select($languageMessage, $state['language'] ?? null);
            if (trim($message) !== '' && $this->language->withoutLanguageRequest($message) === '') {
                return $this->fallback($explicitLanguage !== null ? 'Language preference saved.' : 'Language preference unchanged.', $state, $subject);
            }
        }

        if ($this->chatbot->containsSensitiveInput($message) || (! $advice && $this->categorizer->isBlocked($message))) {
            $result = $this->chatbot->respond($message, $customer, language: $state['language'] ?? $this->language->select($languageMessage));

            return ['message' => $result['message'], 'source' => $result['source'], 'context_token' => null];
        }

        if (config('ai_integration.enabled') && config('ai_integration.advice_enabled')
            && ($state['active'] ?? null) === 'product'
            && preg_match('/^(?:(?:do you have|show me|can you suggest|anything|something|any|and|what about)\s+)*(?:something\s+)?(?:cheaper|less expensive|under\s+(?:PHP\s*|₱\s*)?[\d,]+(?:\.\d{1,2})?|below\s+(?:PHP\s*|₱\s*)?[\d,]+(?:\.\d{1,2})?)\s*[?.!]*$/iu', $this->language->canonical($message)) === 1) {
            $previous = Product::query()->customerEligible()->with('category')
                ->whereIn('id', array_slice($state['references']['product'] ?? [], 0, 6))->get();
            if ($previous->isNotEmpty() && $previous->pluck('category_id')->unique()->count() === 1) {
                if (preg_match('/\b(?:under|below)\s*(?:PHP\s*|₱\s*)?([\d,]+(?:\.\d{1,2})?)/iu', $this->language->canonical($message), $budget) === 1) {
                    $maximum = (float) str_replace(',', '', $budget[1]);
                } else {
                    $maximum = (float) $previous->min(fn (Product $product): float => (float) ($product->discount_price ?? $product->price)) - 0.01;
                }
                if ($maximum > 0) {
                    $message = 'recommend '.$previous->first()->category->name.' under PHP '.number_format($maximum, 2, '.', '');
                    foreach ($state['catalog_constraints']['excluded_brands'] ?? [] as $brand) {
                        if (in_array($brand, ['intel', 'amd', 'nvidia'], true)) {
                            $message .= ', not '.$brand;
                        }
                    }
                    $advice = true;
                    $constraintFollowUp = true;
                    unset($state['product_clarification']);
                }
            } else {
                return $this->fallback('Which component category should I use for that budget? Please name the component and your budget in PHP.', $state, $subject);
            }
        }

        $acknowledged = $this->language->isAcknowledgement($message);
        if (preg_match('/^(?:hi|hello|hey|thanks|thank you|salamat|salamat gid|salamat po)[.!\s]*$/iu', $message) === 1) {
            return $this->fallback('Hello! Ask me about a Battlefront product, your order, or store information.', $state, $subject);
        }
        if ($acknowledged && ($state['pending'] ?? []) !== []) {
            return $this->fallback($this->router->clarification($state['pending']), $state, $subject);
        }

        $selected = $this->selectedTopic($message);
        if ($selected !== null && str_starts_with($selected, 'faq:') && ! isset($state['pending'][$selected]) && isset($state['pending']['faq'])) {
            $selected = 'faq';
        }
        if ($selected !== null && isset($state['pending'][$selected])) {
            $message = $state['pending'][$selected];
        }
        unset($state['pending']);

        $routing = $this->router->execute($message, resolveCatalogTerms: true);
        if (! $advice && $routing['choices'] !== []) {
            $state['pending'] = $routing['choices'];

            return $this->fallback($this->router->clarification($routing['choices']), $state, $subject);
        }

        $productId = null;
        $category = $routing['category'];
        if (isset($state['product_clarification']) && $category === ChatbotQueryCategory::Product
            && $this->products->matchesCatalogTerms($message)
            && $this->products->clarificationChoices($state['references']['product'] ?? [], $message) === []) {
            unset($state['product_clarification']);
        }
        if (! $advice && ($state['active'] ?? null) === 'product' && isset($state['product_clarification'])
            && in_array($category, [ChatbotQueryCategory::Product, ChatbotQueryCategory::Unsupported], true)
            && ! $this->categorizer->requiresLiveFacts($message)) {
            $references = $state['references']['product'] ?? [];
            $choices = $this->products->clarificationChoices($references, $acknowledged ? null : $message);
            if (! $acknowledged && count($choices) === 1) {
                $productId = $choices[0]['id'];
                $message = $state['product_clarification'].' of '.$choices[0]['name'];
                $category = ChatbotQueryCategory::Product;
            } elseif ($acknowledged || $choices !== [] || $category === ChatbotQueryCategory::Unsupported) {
                $choices = $choices !== [] ? $choices : $this->products->clarificationChoices($references);
                $state['references']['product'] = array_column($choices, 'id');
                if ($choices === []) {
                    unset($state['product_clarification']);

                    return $this->fallback('Those products are no longer available in the current catalog. Please enter another product name or model.', $state, $subject);
                }

                return $this->fallback($this->fallbackRenderer->productClarification(array_column($choices, 'name')), $state, $subject);
            }
        }

        if ($acknowledged) {
            return $this->fallback('Please tell me which product, branch, order or support question you would like help with.', $state, $subject);
        }

        $matchingMessage = $this->language->canonical($message);
        $isFollowUp = ! $advice && preg_match('/\b(?:it|its|that|this|there|earlier|previous)\b|^(?:how much|and the)\b|^(?:price|available)\s*[?!.]*$/iu', $matchingMessage) === 1;

        if ($isFollowUp && $this->hasExplicitEntity($message, $category)) {
            $isFollowUp = false;
        }

        if (($state['active'] ?? null) === 'store'
            && in_array($category, [ChatbotQueryCategory::Unsupported, ChatbotQueryCategory::Store], true)
            && ! $this->categorizer->requiresLiveFacts($message)
            && preg_match('/^(?:what|how) about\b/iu', $matchingMessage) === 1) {
            $namedCity = Branch::query()->published()->pluck('city')->first(
                fn (string $city): bool => str_contains(Str::lower($message), Str::lower(Str::before($city, ' City'))),
            );
            if ($namedCity !== null) {
                $message = ($state['store_intent'] ?? 'location').' of '.$namedCity.' branch';
                $category = ChatbotQueryCategory::Store;
                $isFollowUp = false;
            }
        }

        if ($isFollowUp) {
            $target = $category === ChatbotQueryCategory::Unsupported
                ? ($state['active'] ?? null)
                : $category->value;
            if (preg_match('/\b(?:earlier|previous) (?:product|order|branch|store)\b/iu', $matchingMessage) === 1) {
                $target = preg_match('/\border\b/iu', $matchingMessage) === 1 ? 'order'
                    : (preg_match('/\bproduct\b/iu', $matchingMessage) === 1 ? 'product' : 'store');
            } elseif ($target !== ($state['active'] ?? null)) {
                return $this->fallback('Which product, branch, or order do you mean? Please include its name or order reference.', $state, $subject);
            }

            $references = $state['references'][$target ?? ''] ?? [];
            if (count($references) !== 1) {
                return $this->fallback('Which product, branch, or order do you mean? Please include its name or order reference.', $state, $subject);
            }

            if ($target === 'product') {
                $productId = $references[0];
                $message = 'Product inquiry: '.$message;
            } elseif ($target === 'store') {
                $message = rtrim($message, '.?!').' at '.$references[0].' branch';
            } elseif ($target === 'order') {
                $message = rtrim($message, '.?!').' order '.$references[0];
            }
        } elseif ($category === ChatbotQueryCategory::Order
            && preg_match('/\bbf[\s\W_]*\d+\b/iu', $message) !== 1
            && ($state['active'] ?? null) === 'order'
            && count($state['references']['order'] ?? []) === 1) {
            $message = rtrim($message, '.?!').' '.$state['references']['order'][0];
        }

        $resolved = false;
        $result = $this->chatbot->respond($message, $customer, function (ChatbotQueryCategory $category, array $context) use (&$state, &$resolved, $message): void {
            $resolved = true;
            $state['active'] = $category->value;
            $state['last_question'] = mb_substr($message, 0, 2000);
            if ($category === ChatbotQueryCategory::Product) {
                unset($state['model_constraints'], $state['pending_catalog']);
                $categories = array_values(array_unique(array_column($context['products'], 'category')));
                if (count($categories) === 1) {
                    $state['catalog_constraints']['category'] = $categories[0];
                } else {
                    unset($state['catalog_constraints']['category']);
                }
                $state = $this->rememberProductReferences($state, $context);
                unset($state['product_clarification']);
                if (count($context['products']) > 1) {
                    $text = $this->language->canonical($message);
                    $price = preg_match('/\b(?:price|prices|pricing|cost|how much)\b/iu', $text) === 1;
                    $stock = preg_match('/\b(?:stock|available|availability)\b/iu', $text) === 1;
                    if ($price || $stock) {
                        $state['product_clarification'] = $price && $stock ? 'price and stock availability' : ($price ? 'price' : 'stock availability');
                    }
                }
            } elseif ($category === ChatbotQueryCategory::Store) {
                $state['references']['store'] = array_slice(array_column($context['branches'], 'city'), 0, 5);
                $state['store_intent'] = match (true) {
                    preg_match('/\b(?:hours|open|close|time)\b/iu', $this->language->canonical($message)) === 1 => 'opening hours',
                    preg_match('/\b(?:contact|phone|email|telephone)\b/iu', $this->language->canonical($message)) === 1 => 'contact information',
                    default => 'location',
                };
            } elseif ($category === ChatbotQueryCategory::Order) {
                $state['references']['order'] = array_column($context['orders'], 'reference');
            } elseif ($category === ChatbotQueryCategory::Faq && count($context['knowledge'] ?? []) === 1) {
                $state['faq_question'] = $context['knowledge'][0]['question_pattern'];
            }
        }, $productId, $state['language'], componentReferences: $state['component_references'] ?? []);

        if (! $resolved) {
            $state['active'] = $result['category']->value;
            unset($state['references'][$state['active']]);
            if ($state['active'] === 'product' && ! $constraintFollowUp) {
                unset($state['catalog_constraints']['category']);
            }
        }

        if ($result['source'] === 'gemini' && $state['active'] === 'product'
            && preg_match('/\b(?:stock|available|availability)\b/iu', $this->language->canonical($message)) === 1) {
            $result['message'] .= ' '.app(ChatbotLocalizedResponse::class)->text('Live inventory information covers the Sagay branch only.', $state['language']);
        }

        return [
            'message' => $result['message'],
            'source' => $result['source'],
            'context_token' => $this->tokens->encode($state, $subject),
            'presentation' => $result['presentation'],
        ];
    }

    /** @param array<string, mixed> $state
     * @return array{message: string, source: 'fallback', context_token: string, presentation: array<string, mixed>} */
    private function respondToBranch(string $message, ?User $customer, array $state, string $subject): array
    {
        $budget = app(ChatbotProviderBudget::class);
        $budget->reset();
        $started = hrtime(true);
        app(ChatbotFailureTrace::class)->scope = 'public';
        try {
            $branchAvailability = app(ChatbotBranchAvailability::class);
            $intent = $branchAvailability->localIntent($message, $state);
            $context = app(ChatbotCatalogSelection::class)->select($this->language->canonical($message), $intent, $state);
            $state['active'] = 'product';
            $state['last_question'] = mb_substr($message, 0, 2000);
            if (isset($context['selection_constraints'])) {
                $state['model_constraints'] = $context['selection_constraints'];
            }
            if (in_array($context['clarification_code'] ?? null, ['unknown_branch', 'ambiguous_branch'], true)) {
                $state['pending_catalog'] = ['reason' => $context['clarification_code'], 'intent' => $intent, 'alternatives' => false, 'cheaper' => false];
            } else {
                unset($state['pending_catalog']);
            }
            $state = $this->rememberProductReferences($state, $context);
            unset($state['pending'], $state['product_clarification']);
            $explicitLanguage = $this->language->explicit($message);
            if ($explicitLanguage !== null) {
                $state['language_preference'] = $explicitLanguage;
            }
            $state['language'] = $state['language_preference'] ?? $this->language->select($message, $state['language'] ?? null);
            $text = $this->fallbackRenderer->execute(ChatbotQueryCategory::Product, $message, $context) ?? 'Please provide a product name or model.';
            $result = ['category' => ChatbotQueryCategory::Product, 'source' => 'fallback',
                'message' => app(ChatbotLocalizedResponse::class)->text($text, $state['language'], array_column($context['products'], 'name'))];

            return ['message' => $result['message'], 'source' => $result['source'], 'context_token' => $this->tokens->encode($state, $subject),
                'presentation' => app(ChatbotPresentation::class)->build($result, $context, $message, $customer, $state['language'])];
        } finally {
            $budget->recordTurn((int) ((hrtime(true) - $started) / 1_000_000), 'fallback');
            $budget->releaseTurn();
        }
    }

    /** @param array<string, mixed> $state
     * @param array<string, mixed> $context
     * @return array<string, mixed> */
    private function rememberProductReferences(array $state, array $context): array
    {
        $products = $context['products'] ?? [];
        $resolvedProducts = Product::query()->customerEligible()->with('componentProfile')
            ->when(array_column($products, 'id') !== [],
                fn ($query) => $query->whereIn('id', array_column($products, 'id')),
                fn ($query) => $query->whereIn('name', array_column($products, 'name')))
            ->limit(6)->get(['id', 'name'])
            ->sortBy(fn (Product $product): int => (int) array_search($product->name, array_column($products, 'name'), true));
        $state['references']['product'] = $resolvedProducts->pluck('id')->all();
        foreach ($resolvedProducts->groupBy(fn (Product $product): string => $product->componentProfile->component_type ?? '') as $type => $components) {
            if ($type !== '') {
                $state['component_references'][$type] = $components->pluck('id')->all();
            }
        }

        return $state;
    }

    private function selectedTopic(string $message): ?string
    {
        return match (Str::of($this->language->canonical($message))->lower()->trim()->rtrim('.?!')->toString()) {
            'product', 'products', 'price', 'pricing', 'product pricing', 'product information' => 'product',
            'order', 'my order', 'order status' => 'order',
            'store', 'branch', 'store information' => 'store',
            'payment', 'payment guidance', 'payment methods' => 'faq:payment_methods',
            'proof', 'payment proof', 'payment verification' => 'faq:payment_proof',
            'delivery fee', 'delivery guidance', 'delivery cost' => 'faq:delivery_quote',
            'return', 'returns', 'return guidance' => 'faq:returns',
            'refund', 'refunds', 'refund guidance' => 'faq:refunds',
            'cancel', 'cancellation', 'cancellation guidance' => 'faq:cancellation',
            'faq', 'delivery', 'payment or delivery guidance', 'delivery information' => 'faq',
            default => null,
        };
    }

    private function hasExplicitEntity(string $message, ChatbotQueryCategory $category): bool
    {
        if ($category === ChatbotQueryCategory::Order) {
            return preg_match('/\bbf[\s\W_]*\d+\b/iu', $message) === 1;
        }

        $normalized = Str::of($message)->lower()->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')->squish()->toString();
        if ($category === ChatbotQueryCategory::Store) {
            return Branch::query()->pluck('city')->contains(function (string $city) use ($normalized): bool {
                $city = Str::lower(Str::before($city, ' City'));

                return str_contains(" {$normalized} ", " {$city} ");
            });
        }

        if ($category === ChatbotQueryCategory::Product) {
            return $this->products->namesProduct($message) || $this->products->hasSearchTerms($message);
        }

        return $category === ChatbotQueryCategory::Faq
            && preg_match('/\b(?:it|its|that|this|there|earlier|previous)\b/iu', $this->language->canonical($message)) !== 1;
    }

    /** @param array<string, mixed> $state
     * @return array{message: string, source: string, context_token: string}
     */
    private function fallback(string $message, array $state, string $subject): array
    {
        return ['message' => app(ChatbotLocalizedResponse::class)->text($message, $state['language'] ?? 'en'), 'source' => 'fallback', 'context_token' => $this->tokens->encode($state, $subject)];
    }
}
