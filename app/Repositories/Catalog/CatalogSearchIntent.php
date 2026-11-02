<?php

namespace App\Repositories\Catalog;

use Illuminate\Support\Str;

class CatalogSearchIntent
{
    /** @var array<string, list<string>> */
    private const CATEGORIES = [
        'Processor' => ['cpu', 'cpus', 'processor', 'processors', 'central processing unit'],
        'RAM' => ['ram', 'memory', 'computer memory', 'system memory', 'random access memory', 'dram', 'memory module', 'memory modules'],
        'Motherboard' => ['motherboard', 'motherboards', 'mobo', 'mainboard', 'main board', 'system board', 'mother board'],
        'Graphics Card' => ['gpu', 'gpus', 'graphics card', 'graphics cards', 'video card', 'video cards', 'videocard', 'graphics processing unit', 'graphic card'],
        'Storage' => ['storage', 'storage drive', 'storage drives', 'disk drive', 'disk drives'],
        'Power Supply' => ['psu', 'power supply', 'power supplies', 'power supply unit'],
        'PC Case' => ['case', 'cases', 'pc case', 'computer case', 'cpu case', 'casing', 'chassis', 'computer chassis', 'pc chassis'],
        'Cooling Components' => ['cooling', 'cooler', 'coolers', 'cpu cooler', 'cpu cooling', 'cpu fan', 'case fan', 'fans'],
        'Monitor' => ['monitor', 'monitors', 'display', 'computer display'],
        'Peripherals' => ['peripherals', 'peripheral', 'input devices'],
        'Networking' => ['networking', 'network equipment'],
        'Audio Equipment' => ['audio', 'audio equipment'],
        'Cables & Adapters' => ['cables', 'cables and adapters', 'cables & adapters'],
        'Printer' => ['printer', 'printers', 'printing machine', 'printing machines', 'print machine', 'printer machine', 'printing'],
        'Laptops & Desktops' => ['computers', 'computer', 'pcs'],
        'Bundles & Packages' => ['bundle', 'bundles', 'package', 'packages', 'computer package', 'computer set', 'pc bundle'],
        'CCTV & Security' => ['cctv', 'security', 'surveillance', 'security camera', 'security cameras', 'cctv camera'],
        'Cleaning & Maintenance Supplies' => ['cleaning', 'cleaning supplies', 'maintenance supplies'],
        'Consumer Electronics' => ['consumer electronics'],
        'DIY Electronics & Microcontrollers' => ['diy electronics', 'microcontroller', 'microcontrollers'],
        'Furniture & Hardware Fixtures' => ['furniture', 'hardware fixtures'],
        'Laptop Bags & Stands' => ['laptop accessories', 'laptop bag', 'laptop bags', 'laptop bags and stands'],
        'Office & Packaging Supplies' => ['office supplies', 'packaging', 'packaging supplies'],
        'Power Accessories' => ['power accessories'],
        'Solar & Lighting Products' => ['solar', 'solar products', 'lighting', 'solar and lighting'],
        'Vending & Coin-Op Machine Parts' => ['vending', 'vending parts', 'coin op', 'coin operated', 'coin slot'],
        'Service' => ['service', 'services', 'checkup', 'check up'],
    ];

    /** @var array<string, array<string, string>> */
    private const SPECIFIC_PARTS = [
        'Storage' => ['ssd' => 'ssd', 'ssds' => 'ssd', 'solid state drive' => 'ssd', 'solid state drives' => 'ssd', 'solid-state drive' => 'ssd', 'hdd' => 'hdd', 'hdds' => 'hdd', 'hard drive' => 'hdd', 'hard drives' => 'hdd', 'hard disk' => 'hdd', 'hard disk drive' => 'hdd', 'hard disk drives' => 'hdd', 'nvme' => 'nvme', 'flash drive' => 'flash', 'flashdrive' => 'flash', 'usb drive' => 'flash', 'thumb drive' => 'flash', 'pen drive' => 'flash', 'pendrive' => 'flash', 'memory card' => 'card', 'card reader' => 'card reader', 'sd card' => 'card', 'external enclosure' => 'enclosure', 'ssd enclosure' => 'enclosure', 'case for ssd' => 'enclosure', 'case for hard drive' => 'external case'],
        'RAM' => ['sodimm' => 'sodimm', 'so dimm' => 'sodimm', 'laptop ram' => 'laptop', 'laptop memory' => 'laptop', 'ddr3' => 'ddr3', 'ddr4' => 'ddr4', 'ddr5' => 'ddr5'],
        'Peripherals' => ['keyboard' => 'keyboard', 'keyboards' => 'keyboard', 'mouse' => 'mouse', 'mice' => 'mouse', 'webcam' => 'web camera', 'web camera' => 'web camera', 'wireless keyboard' => 'wireless keyboard', 'wireless mouse' => 'wireless mouse', 'mechanical keyboard' => 'mechanical keyboard', 'keyboard and mouse' => 'keyboard mouse', 'mouse pad' => 'mousepad', 'mousepad' => 'mousepad', 'numeric keypad' => 'keypad'],
        'Cooling Components' => ['fan' => 'fan', 'aio' => 'aio', 'liquid cooler' => 'liquid'],
        'Networking' => ['router' => 'router', 'routers' => 'router', 'network switch' => 'switch', 'switch hub' => 'switch', 'wifi adapter' => 'adapter', 'wi fi adapter' => 'adapter', 'wireless adapter' => 'adapter', 'access point' => 'access point', 'range extender' => 'extender', 'wifi extender' => 'extender', 'wireless repeater' => 'repeater', 'modem' => 'modem', 'media converter' => 'media converter', 'fiber optic' => 'fiber'],
        'Audio Equipment' => ['headset' => 'headset', 'headphones' => 'headphone', 'speaker' => 'speaker', 'microphone' => 'microphone'],
        'Laptops & Desktops' => ['laptop' => 'laptop', 'desktop' => 'desktop'],
        'Monitor' => ['tv' => 'tv', 'television' => 'tv', 'smart tv' => 'tv', 'tv bracket' => 'bracket', 'tv wall mount' => 'mount'],
        'CCTV & Security' => ['nvr' => 'nvr', 'network video recorder' => 'nvr', 'dvr' => 'dvr', 'digital video recorder' => 'dvr', 'video balun' => 'balun', 'cctv cable' => 'cable'],
        'Power Accessories' => ['ups' => 'ups', 'uninterruptible power supply' => 'ups', 'battery backup' => 'ups', 'ups battery' => 'battery ups', 'battery for ups' => 'battery ups', 'avr' => 'avr', 'automatic voltage regulator' => 'avr', 'surge protector' => 'surge', 'power strip' => 'extension', 'extension cord' => 'extension', 'laptop charger' => 'charger', 'charger for laptop' => 'charger', 'notebook power adapter' => 'notebook power adapter'],
        'Printer' => ['printer ink' => 'ink', 'ink' => 'ink', 'ink cartridge' => 'ink', 'ink bottle' => 'ink', 'ink refill' => 'ink', 'printer cartridge' => 'ink', 'toner' => 'toner', 'printer toner' => 'toner', 'printer cable' => 'cable'],
        'Laptop Bags & Stands' => ['laptop stand' => 'stand', 'laptop pouch' => 'pouch', 'laptop backpack' => 'backpack'],
        'Cleaning & Maintenance Supplies' => ['cleaning solution' => 'cleaning', 'electric blower' => 'blower', 'air blower' => 'blower'],
        'Consumer Electronics' => ['tv stick' => 'tv stick', 'android tv stick' => 'tv stick'],
        'DIY Electronics & Microcontrollers' => ['nodemcu' => 'nodemcu', 'esp8266' => 'esp8266', 'orange pi' => 'orange pi'],
        'Furniture & Hardware Fixtures' => ['gaming chair' => 'chair', 'chair' => 'chair', 'crimping tool' => 'crimping'],
        'Office & Packaging Supplies' => ['bond paper' => 'paper', 'copy paper' => 'paper', 'junction box' => 'junction box', 'cd r' => 'cd'],
        'Solar & Lighting Products' => ['solar light' => 'solar', 'solar floodlight' => 'solar', 'led signage' => 'signage'],
        'Vending & Coin-Op Machine Parts' => ['pisowifi' => 'piso', 'piso wifi' => 'piso', 'water timer' => 'timer', 'water pump' => 'pump', 'video game timer' => 'timer'],
    ];

    /** Public, curated catalog vocabulary only; never derive these words from customer input.
     * @return list<string>
     */
    public static function publicTerms(): array
    {
        $phrases = array_keys(self::CATEGORIES);
        foreach (self::CATEGORIES as $aliases) {
            $phrases = [...$phrases, ...$aliases];
        }
        foreach (self::SPECIFIC_PARTS as $aliases) {
            $phrases = [...$phrases, ...array_keys($aliases), ...array_values($aliases)];
        }

        return array_values(array_unique(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(implode(' ', $phrases)), flags: PREG_SPLIT_NO_EMPTY) ?: []));
    }

    /** @return list<array{objectID: string, type: 'synonym', synonyms: list<string>}> */
    public static function algoliaSynonyms(): array
    {
        return [
            ['objectID' => 'catalog-processor', 'type' => 'synonym', 'synonyms' => ['cpu', 'processor', 'central processing unit']],
            ['objectID' => 'catalog-memory', 'type' => 'synonym', 'synonyms' => ['ram', 'computer memory', 'random access memory']],
            ['objectID' => 'catalog-motherboard', 'type' => 'synonym', 'synonyms' => ['motherboard', 'mobo', 'mainboard', 'system board']],
            ['objectID' => 'catalog-graphics-card', 'type' => 'synonym', 'synonyms' => ['gpu', 'graphics card', 'video card']],
            ['objectID' => 'catalog-solid-state-drive', 'type' => 'synonym', 'synonyms' => ['ssd', 'solid state drive']],
            ['objectID' => 'catalog-hard-disk-drive', 'type' => 'synonym', 'synonyms' => ['hdd', 'hard drive', 'hard disk drive']],
            ['objectID' => 'catalog-flash-drive', 'type' => 'synonym', 'synonyms' => ['flash drive', 'thumb drive', 'pen drive', 'usb drive']],
            ['objectID' => 'catalog-printer-machine', 'type' => 'synonym', 'synonyms' => ['printer', 'printing machine', 'print machine']],
        ];
    }

    /** @return array{category: string, query: string}|null */
    public function resolve(string $query): ?array
    {
        if ($this->quotedSearch($query) !== null) {
            return null;
        }

        $query = $this->normalize($query);
        foreach (['please ', 'can you show me ', 'i am looking for ', 'looking for ', 'show me ', 'find me ', 'i need ', 'i want ', 'do you have ', 'search for '] as $prefix) {
            if (str_starts_with($query, $prefix)) {
                $query = substr($query, strlen($prefix));
            }
        }
        $query = preg_replace('/^(?:a|an|the)\s+|\s+please$/u', '', $query) ?? $query;
        $aliases = [];
        foreach (self::CATEGORIES as $category => $terms) {
            $aliases[$this->normalize($category)] = ['category' => $category, 'query' => ''];
            foreach ($terms as $term) {
                $aliases[$this->normalize($term)] = ['category' => $category, 'query' => ''];
            }
        }
        foreach (self::SPECIFIC_PARTS as $category => $terms) {
            foreach ($terms as $term => $replacement) {
                $aliases[$this->normalize($term)] = ['category' => $category, 'query' => $replacement];
            }
        }
        uksort($aliases, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $words = explode(' ', $query);
        $withOffset = array_search('with', $words, true);
        $best = null;
        $bestRank = null;
        $bestOffset = PHP_INT_MAX;
        $ambiguous = false;
        foreach ($aliases as $alias => $intent) {
            $aliasWords = explode(' ', $alias);
            if (count($aliasWords) > count($words)) {
                continue;
            }
            for ($offset = 0; $offset <= count($words) - count($aliasWords); $offset++) {
                $cost = 0;
                foreach ($aliasWords as $index => $word) {
                    $cost += $this->wordDistance($words[$offset + $index], $word);
                }
                if ($cost > 2) {
                    continue;
                }
                $remaining = array_merge(array_slice($words, 0, $offset), array_slice($words, $offset + count($aliasWords)));
                if (in_array('for', $remaining, true)) {
                    continue;
                }
                $remaining = array_values(array_diff($remaining, ['with', 'and']));
                while (in_array($remaining[0] ?? null, ['a', 'an', 'the'], true)) {
                    array_shift($remaining);
                }
                $candidate = $intent;
                $candidate['query'] = Str::squish($intent['query'].' '.implode(' ', $remaining));
                $relationRank = $withOffset !== false && $offset < $withOffset ? 1 : 0;
                $rank = [count($aliasWords), $cost === 0 ? 1 : 0, -$cost, $relationRank];
                if ($bestRank === null || $rank > $bestRank) {
                    $best = $candidate;
                    $bestRank = $rank;
                    $bestOffset = $offset;
                    $ambiguous = false;
                } elseif ($rank === $bestRank && $candidate !== $best) {
                    if ($candidate['category'] !== $best['category']) {
                        $ambiguous = true;
                    } elseif ($offset < $bestOffset) {
                        $best = $candidate;
                        $bestOffset = $offset;
                    }
                }
            }
        }

        return $ambiguous ? null : $best;
    }

    public function normalizeQuery(string $query): string
    {
        return preg_replace('/\b(\d+)\s+(gb|tb|mhz|ghz|w|mm)\b/iu', '$1$2', Str::squish($query)) ?? $query;
    }

    /** @return array{query: string, phrases: list<string>, terms: list<string>}|null */
    public function quotedSearch(string $query): ?array
    {
        preg_match_all('/"([^"]+)"/', $query, $matches);
        $phrases = array_values(array_filter(array_map('trim', $matches[1]), static fn (string $phrase): bool => $phrase !== ''));
        if ($phrases === []) {
            return null;
        }

        $remaining = preg_replace('/"[^"]*"/', ' ', $query) ?? $query;
        $remaining = $this->normalizeQuery($remaining);
        $terms = preg_split('/\s+/u', $remaining, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            'query' => implode(' ', [...array_map(static fn (string $phrase): string => '"'.$phrase.'"', $phrases), ...$terms]),
            'phrases' => $phrases,
            'terms' => $terms,
        ];
    }

    private function normalize(string $query): string
    {
        $query = Str::lower(Str::squish(preg_replace('/(?<=[a-z])[-_](?=[a-z])/iu', ' ', $query) ?? $query));

        return $this->normalizeQuery($query);
    }

    private function wordDistance(string $query, string $word): int
    {
        if ($query === $word) {
            return 0;
        }
        if (strlen($word) < 4 || strlen($query) < 4 || preg_match('/[^a-z]/', $query.$word)) {
            return 3;
        }
        $distance = levenshtein($query, $word);
        $limit = strlen($word) >= 10 && strlen($query) >= 9 ? 2 : 1;
        if (strlen($word) >= 5 && $distance <= $limit) {
            return $distance;
        }
        if (strlen($query) === strlen($word)) {
            for ($i = 0; $i < strlen($query) - 1; $i++) {
                $swapped = $query;
                $swapped[$i] = $query[$i + 1];
                $swapped[$i + 1] = $query[$i];
                if ($swapped === $word) {
                    return 1;
                }
            }
        }

        return 3;
    }
}
