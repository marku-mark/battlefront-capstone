<?php

namespace App\Services\Chatbot;

use App\Repositories\Catalog\CatalogSearchIntent;

class PublicChatbotQuery
{
    private const TERMS = [
        'product', 'products', 'catalog', 'price', 'stock', 'availability', 'available',
        'budget', 'affordable', 'cheap', 'gaming', 'graphics', 'card', 'gpu', 'cpu',
        'processor', 'motherboard', 'memory', 'ram', 'storage', 'ssd', 'hdd', 'nvme',
        'power', 'supply', 'psu', 'case', 'cooler', 'monitor', 'keyboard', 'mouse',
        'headset', 'speaker', 'network', 'router', 'laptop', 'desktop', 'computer',
        'compatible', 'compatibility', 'recommend', 'suggest', 'alternative',
        'specifications', 'specs', 'details', 'socket', 'ddr4', 'ddr5', 'am4', 'am5', 'intel', 'amd', 'ryzen', 'nvidia',
        'geforce', 'radeon', 'rtx', 'gtx', 'order', 'status', 'tracking', 'payment',
        'delivery', 'pickup', 'refund', 'return', 'cancellation', 'store', 'branch',
        'location', 'hours', 'contact', 'warranty', 'repair', 'gcash', 'maya', 'cod',
        'editing', 'video', 'rendering', 'streaming', 'office', 'school', 'programming',
        'portable', 'quiet', 'wireless', 'wired', 'compare', 'comparison', 'difference',
        'cheaper', 'cheapest', 'capacity', 'performance',
        'choose', 'best', 'buy',
        'search', 'filter', 'browse', 'wishlist', 'save', 'saved', 'build', 'builder', 'pc',
        'account', 'history', 'real', 'time', 'share', 'sharing', 'template', 'templates', 'navigation',
    ];

    public function __construct(private ChatbotLanguage $language) {}

    public function minimize(string $message): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($this->language->canonical($message))) ?: [];
        $terms = array_values(array_unique(array_intersect($words, [...self::TERMS, ...CatalogSearchIntent::publicTerms()])));

        return implode(' ', array_slice($terms, 0, 20));
    }
}
