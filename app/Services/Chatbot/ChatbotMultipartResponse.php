<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Actions\Chatbot\Context\ResolveChatbotRuleGuidance;
use App\Actions\Chatbot\Context\ResolveFaqContext;
use App\Actions\Chatbot\Context\ResolveStoreContext;
use App\Actions\Chatbot\RenderChatbotFallback;
use App\Enums\ChatbotQueryCategory;
use App\Models\User;
use App\Repositories\Catalog\CatalogSearchIntent;
use Illuminate\Support\Facades\Log;

class ChatbotMultipartResponse
{
    /** @return list<array<string, mixed>> */
    public function categoryList(string $message): array
    {
        $language = app(ChatbotLanguage::class);
        $query = $language->canonical(str_replace(["\r\n", "\r", "\n"], ';', $message));
        $branchIntent = app(ChatbotBranchAvailability::class)->catalogQuery($query) !== $query
            ? app(ChatbotBranchAvailability::class)->localIntent($message) : ['branch' => ''];
        if ($branchIntent['branch'] !== '') {
            $query = app(ChatbotBranchAvailability::class)->catalogQuery($query);
        }
        $query = $language->withoutStockTiming($query);
        $query = preg_replace('/^(?:price(?: and stock)?|stock|details|specifications) of\s+/iu', '', trim($query)) ?? $query;
        $query = preg_replace('/^(?:please\s+)?(?:do you have|can i have|i need|i want|i am looking for|looking for|show me|available)\s+/iu', '', trim($query)) ?? $query;
        $query = preg_replace('/(\bbetween\s+(?:PHP\s*|\x{20B1}\s*)?[\d,.]+\s*k?)\s+and\s+(?=(?:PHP\s*|\x{20B1}\s*)?\d)/iu', '$1 __price_and__ ', $query) ?? $query;
        $clauses = preg_split('/\s*(?:,(?!\d)|;|\R|&|\s\/\s|\b(?:and|at|kag|ug|og|pati|tsaka)\b)\s*/iu', trim($query, ' ?.!')) ?: [];
        $parts = [];
        foreach (array_filter($clauses) as $clause) {
            $clause = $language->withoutStockTiming(str_replace('__price_and__', 'and', $clause));
            if (preg_match('/^(?:not|without|except|exclude|under|below|above|budget)\b/iu', trim($clause))) {
                return [];
            }
            $clause = trim(preg_replace('/^(?:available|do you have|show me|price of|stock of|details of|specifications of)\s+/iu', '', $clause) ?? $clause, ' ?.!');
            $catalog = app(CatalogSearchIntent::class)->resolveStandalone($clause);
            if ($catalog === null && ! preg_match('/\b(?:recommend|compare|compatible|for|order|payment|refund)\b/iu', $clause)) {
                $catalog = app(CatalogSearchIntent::class)->resolve($clause);
            }
            if ($catalog === null && preg_match('/^wi[ -]?fi$/iu', $clause) === 1) {
                $catalog = ['category' => 'Networking', 'query' => $clause];
            }
            if ($catalog === null) {
                return [];
            }
            $parts[$catalog['category'].'|'.$catalog['query']] = [
                'outcome' => 'supported', 'intent' => 'discovery', 'language' => 'en',
                'category_key' => ChatbotProductRanking::FAMILIES[$catalog['category']] ?? 'other', 'search_query' => $clause, 'product_mentions' => [],
                'attributes' => ['price', 'stock'], 'maximum_php' => null, 'excluded_brands' => [],
                'brand' => '', 'capacity' => '', 'use_case' => '', 'reference' => 'new',
                'position' => 0, 'clarification' => 'none',
                'branch' => $branchIntent['branch'], 'availability' => $branchIntent['branch'] !== '' ? 'in_stock' : 'any',
            ];
        }

        $parts = array_values($parts);
        $types = array_unique(array_map($this->heading(...), $parts));

        return count($parts) >= 2 && count($types) >= 2 ? $parts : [];
    }

    /** @param array<string, mixed> $intent */
    public function heading(array $intent): string
    {
        $query = $intent['search_query'] ?? '';
        $catalog = app(CatalogSearchIntent::class)->resolve($query);
        $canonical = app(ChatbotLanguage::class)->canonical($query);
        foreach (['Wi-Fi' => '/\bwi[ -]?fi\b/iu', 'Keyboards' => '/\bkeyboards?\b/iu', 'Mouse pads' => '/\bmouse[ -]?pads?\b/iu', 'Mouse' => '/\b(?:mouse|mice)\b/iu', 'SSD' => '/\bssd\b/iu', 'HDD' => '/\bhdd\b/iu', 'Headsets' => '/\bheadsets?\b/iu'] as $label => $pattern) {
            if (preg_match($pattern, $canonical) === 1) {
                return $label;
            }
        }

        return match ($catalog['category'] ?? null) {
            'Motherboard' => 'Motherboards',
            'Monitor' => 'Monitors',
            default => $catalog['category'] ?? match ($intent['intent'] ?? '') {
                'support' => 'Support', 'store' => 'Store information',
                'compatibility' => 'Compatibility', default => 'Products',
            },
        };
    }

    /** @param list<array<string, mixed>> $parts
     * @param array<string, mixed> $state
     * @return array<string, mixed> */
    public function respond(array $parts, bool $overflow, array $state, ?User $customer, string $language): array
    {
        $overflow = $overflow || count($parts) > 2;
        $sections = [];
        $savedSections = [];
        if (count($parts) === 1) {
            foreach ($state['multipart_sections'] ?? [] as $section) {
                $savedSections[$this->heading($section['intent'])] = $section;
            }
        }
        $groundedParts = [];
        $presentations = [];
        $canGenerate = ! $overflow && count($parts) >= 1;
        if ($overflow) {
            $sections[] = app(ChatbotLocalizedResponse::class)->text('I can handle up to two separate requests per message. Please send up to two products or questions first, then send the rest separately.', $language);
        } else {
            foreach ($parts as $index => $part) {
                $query = $part['search_query'];
                $category = ChatbotQueryCategory::Product;
                $context = [];
                $boundary = app(ClassifyChatbotInquiry::class);
                $resolved = false;
                if ($part['intent'] === 'private' || $boundary->execute($query)['category'] === ChatbotQueryCategory::Order
                    || $boundary->containsSensitiveInput($query)) {
                    $text = 'Please ask about your order separately so I can check your account securely.';
                } elseif ($part['outcome'] !== 'supported' || $part['clarification'] !== 'none') {
                    $text = 'Please clarify this part with a product name or a specific Battlefront question.';
                } elseif (app(ChatbotProviderBudget::class)->remainingSeconds() === 0) {
                    $text = 'This part could not be checked in time. Please send it again separately.';
                } else {
                    if ($part['intent'] === 'store') {
                        $category = ChatbotQueryCategory::Store;
                        $context = app(ResolveStoreContext::class)->execute($query);
                    } elseif ($part['intent'] === 'support') {
                        $category = ChatbotQueryCategory::Faq;
                        $rule = $boundary->execute($query)['rule_topic'];
                        $context = $rule !== null ? app(ResolveChatbotRuleGuidance::class)->execute($rule) : app(ResolveFaqContext::class)->execute($query, $language);
                    } elseif ($part['intent'] === 'compatibility') {
                        $context = app(ChatbotCatalogAdvice::class)->answer('compatible '.$query)['context'];
                    } elseif (preg_match('/^wi[ -]?fi[?.!]*$/iu', trim($query)) === 1) {
                        $context = ['products' => [], 'clarification' => 'Do you mean a Wi-Fi adapter, a router, or another networking product?'];
                    } else {
                        $partState = $state;
                        if ($part['reference'] === 'previous') {
                            foreach ($state['multipart_sections'] ?? [] as $section) {
                                if ($this->heading($section['intent']) === $this->heading($part)) {
                                    $partState['model_constraints'] = $section['constraints'];
                                    $partState['references']['product'] = $section['product_ids'] ?? [];
                                }
                            }
                        } else {
                            unset($partState['model_constraints'], $partState['references']['product']);
                        }
                        $context = app(ChatbotCatalogSelection::class)->select($query, $part, $partState);
                        if (isset($context['selection_constraints'])) {
                            $saved = app(ChatbotCompoundRequest::class)->bounded([$part])[0];
                            $saved['search_query'] = mb_substr($context['search_query'] ?? $saved['search_query'], 0, 250);
                            $saved['reference'] = 'new';
                            $savedSections[$this->heading($part)] = ['intent' => $saved, 'constraints' => $context['selection_constraints'],
                                'product_ids' => array_column($context['products'], 'id')];
                        }
                    }
                    if (isset($context['products'])) {
                        $context['products'] = array_slice($context['products'], 0, 3);
                    }
                    $text = $context['clarification'] ?? $context['shopping_message'] ?? app(RenderChatbotFallback::class)->execute($category, $query, $context)
                        ?? 'I could not find verified information for this part. Please ask it separately or contact the store.';
                    $resolved = $category === ChatbotQueryCategory::Product && ! isset($context['clarification']) && ! isset($context['shopping_message'])
                        && app(ChatbotGroundedResponse::class)->conversationalEvidence($context) !== [];
                }
                $canGenerate = $canGenerate && $resolved;
                $groundedParts[] = ['id' => ['a', 'b'][$index], 'heading' => $this->heading($part), 'context' => $context, 'intent' => [
                    ...array_intersect_key($part, array_flip(['minimum_php', 'minimum_inclusive', 'maximum_inclusive', 'sort', 'price_scope', 'brand', 'category_key', 'branch', 'availability', 'capacity', 'use_case'])),
                    'focus' => $part['intent'], 'requested_attributes' => $part['attributes'],
                    'required_product_ids' => in_array($part['intent'], ['comparison', 'compatibility'], true) ? array_column($context['products'] ?? [], 'id') : [],
                    'maximum_php' => isset($context['selection_constraints']) ? ($context['selection_constraints']['price']['maximum'] ?? null) : $part['maximum_php'],
                    'minimum_php' => $context['selection_constraints']['price']['minimum'] ?? null,
                    'resolved_constraints' => $context['selection_constraints'] ?? [],
                    'excluded_terms' => $context['selection_constraints']['excluded_brands'] ?? $part['excluded_brands'], 'language' => $language,
                ]];
                $text = preg_replace('/^Here are [^\n]+\.\s*/u', '', $text) ?? $text;
                $text = app(ChatbotLocalizedResponse::class)->text($text, $language, array_column($context['products'] ?? [], 'name'));
                $sections[] = $this->heading($part).":\n\n".$text;
                $presentation = app(ChatbotPresentation::class)->build([
                    'category' => $category, 'message' => $text,
                ], $context, in_array($part['intent'], ['comparison', 'recommendation', 'compatibility'], true) ? $part['intent'].' '.$query : $query, $customer, $language);
                $presentations[] = ['heading' => $this->heading($part), ...$presentation];
            }
        }
        unset($state['references']['product'], $state['model_constraints'], $state['pending_catalog'], $state['pending_clarification']);
        $state['multipart_sections'] = $overflow ? [] : array_slice(array_values($savedSections), -2);
        $state['language'] = $language;
        $state['active'] = ChatbotQueryCategory::Product->value;
        $result = ['category' => ChatbotQueryCategory::Product, 'message' => implode("\n\n", $sections), 'source' => 'fallback'];
        if ($canGenerate) {
            $draft = app(ChatbotAiAdapter::class)->grounded(implode('; ', array_column($parts, 'search_query')), [
                'parts' => $groundedParts,
                'interpreted_intent' => ['focus' => 'multipart', 'parts' => array_map(
                    fn (array $part): array => ['id' => $part['id'], ...$part['intent']], $groundedParts,
                )],
            ], $language, scopeChecked: true);
            if ($draft['successful']) {
                $result['message'] = $draft['text'];
                $result['source'] = 'gemini';
                foreach ($presentations as $index => &$section) {
                    $start = mb_strpos($draft['text'], $section['heading'].":\n\n");
                    if ($start !== false) {
                        $start += mb_strlen($section['heading'].":\n\n");
                        $end = isset($presentations[$index + 1]) ? mb_strpos($draft['text'], $presentations[$index + 1]['heading'].":\n\n", $start) : false;
                        $intro = trim($end === false ? mb_substr($draft['text'], $start) : mb_substr($draft['text'], $start, $end - $start));
                        if ($section['products'] !== []) {
                            $intro = app(ChatbotPresentation::class)->withoutProductFields($intro, $section['products'], $language);
                        }
                        if ($intro !== '') {
                            $section['intro'] = $intro;
                        }
                    }
                }
                unset($section);
            }
        }

        $presentation = app(ChatbotPresentation::class)->build($result, [], '', $customer, $language);
        if ($presentations !== []) {
            $presentation['intro'] = '';
            $presentation['sections'] = $presentations;
            foreach ($presentations as $section) {
                foreach ($section['actions'] as $action) {
                    if (! in_array($action['kind'], ['product', 'catalog'], true)
                        && ! in_array($action['kind'], array_column($presentation['actions'], 'kind'), true)) {
                        $presentation['actions'][] = $action;
                    }
                }
                $presentation['choices'] = array_slice([...$presentation['choices'], ...$section['choices']], 0, 6);
            }
            $result['message'] = app(ChatbotPresentation::class)->plainText($presentation, $result['message']);
        }

        Log::info('Chatbot multipart reply completed.', [
            'sections' => count($presentations), 'source' => $result['source'],
            'products' => array_sum(array_map(fn (array $section): int => count($section['products']), $presentations)),
            'fallback_reason' => $result['source'] === 'fallback' ? (app(ChatbotFailureTrace::class)->event['reason'] ?? ($overflow ? 'clarification' : 'unresolved_section')) : null,
        ]);

        return [...$result, 'state' => $state, 'presentation' => $presentation];
    }
}
