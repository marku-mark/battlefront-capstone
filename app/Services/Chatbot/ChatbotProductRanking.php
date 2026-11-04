<?php

namespace App\Services\Chatbot;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

class ChatbotProductRanking
{
    private const PRIMARY_SQL = <<<'SQL'
EXISTS (SELECT 1 FROM categories WHERE categories.id = products.category_id AND (
    (categories.name = 'Laptops & Desktops' AND LOWER(products.name) NOT LIKE '%barebone%' AND LOWER(products.name) NOT LIKE '%bundle%' AND LOWER(products.name) NOT LIKE '%no ram%' AND LOWER(products.name) NOT LIKE '%no hdd%' AND LOWER(products.name) NOT LIKE '%no ssd%' AND LOWER(products.name) NOT LIKE '%charger%' AND LOWER(products.name) NOT LIKE '%adapter%' AND LOWER(products.name) NOT LIKE '%laptop bag%' AND LOWER(products.name) NOT LIKE '%laptop stand%')
    OR (categories.name = 'Printer' AND LOWER(products.name) LIKE 'printer %' AND LOWER(products.name) NOT LIKE 'ink %' AND LOWER(products.name) NOT LIKE 'toner %' AND LOWER(products.name) NOT LIKE 'cartridge %' AND LOWER(products.name) NOT LIKE '% ink bottle%' AND LOWER(products.name) NOT LIKE '% ink refill%' AND LOWER(products.name) NOT LIKE '% cartridge%' AND LOWER(products.name) NOT LIKE '%printer cable%' AND LOWER(products.name) NOT LIKE '%printer head%' AND LOWER(products.name) NOT LIKE '%print head%')
    OR (categories.name = 'Processor' AND LOWER(products.name) NOT LIKE '%cooler%' AND LOWER(products.name) NOT LIKE '%heatsink%' AND LOWER(products.name) NOT LIKE '%thermal paste%' AND LOWER(products.name) NOT LIKE '%bracket%')
    OR (categories.name = 'Motherboard' AND LOWER(products.name) NOT LIKE '%bracket%' AND LOWER(products.name) NOT LIKE '%adapter%' AND LOWER(products.name) NOT LIKE '%backplate%')
    OR (categories.name = 'RAM' AND LOWER(products.name) NOT LIKE '%heatsink%' AND LOWER(products.name) NOT LIKE '%adapter%')
    OR (categories.name = 'Graphics Card' AND LOWER(products.name) NOT LIKE '%bracket%' AND LOWER(products.name) NOT LIKE '%support holder%' AND LOWER(products.name) NOT LIKE '%riser%' AND LOWER(products.name) NOT LIKE '%adapter%')
    OR (categories.name = 'Storage' AND LOWER(products.name) NOT LIKE '%enclosure%' AND LOWER(products.name) NOT LIKE '%external case%' AND LOWER(products.name) NOT LIKE '%card reader%' AND LOWER(products.name) NOT LIKE '%adapter%' AND LOWER(products.name) NOT LIKE '%cable%')
    OR (categories.name = 'Power Supply' AND LOWER(products.name) NOT LIKE '%cable%' AND LOWER(products.name) NOT LIKE '%adapter%' AND LOWER(products.name) NOT LIKE '%tester%')
    OR (categories.name = 'PC Case' AND LOWER(products.name) NOT LIKE '%bracket%' AND LOWER(products.name) NOT LIKE '%screw%' AND LOWER(products.name) NOT LIKE '%dust filter%')
    OR (categories.name = 'Cooling Components' AND LOWER(products.name) NOT LIKE '%thermal paste%' AND LOWER(products.name) NOT LIKE '%thermal grease%' AND LOWER(products.name) NOT LIKE '%bracket%')
    OR (categories.name = 'Monitor' AND LOWER(products.name) NOT LIKE '%bracket%' AND LOWER(products.name) NOT LIKE '%wall mount%' AND LOWER(products.name) NOT LIKE '%monitor stand%' AND LOWER(products.name) NOT LIKE '%cable%' AND LOWER(products.name) NOT LIKE '%adapter%')
))
SQL;

    public const FAMILIES = [
        'Laptops & Desktops' => 'computer', 'Printer' => 'printer', 'Processor' => 'processor',
        'Motherboard' => 'motherboard', 'RAM' => 'memory', 'Graphics Card' => 'graphics',
        'Storage' => 'storage', 'Power Supply' => 'power_supply', 'PC Case' => 'case',
        'Cooling Components' => 'cooling', 'Monitor' => 'monitor',
    ];

    /** @var array<string, list<string>> */
    private const ACCESSORIES = [
        'Laptops & Desktops' => ['%charger%', '%adapter%', '%laptop bag%', '%laptop stand%'],
        'Printer' => ['ink %', 'toner %', 'cartridge %', '% ink bottle%', '% ink refill%', '% cartridge%', '%printer cable%', '%printer head%', '%print head%'],
        'Processor' => ['%cooler%', '%heatsink%', '%thermal paste%', '%bracket%'],
        'Motherboard' => ['%bracket%', '%adapter%', '%backplate%'],
        'RAM' => ['%heatsink%', '%adapter%'],
        'Graphics Card' => ['%bracket%', '%support holder%', '%riser%', '%adapter%'],
        'Storage' => ['%enclosure%', '%external case%', '%card reader%', '%adapter%', '%cable%'],
        'Power Supply' => ['%cable%', '%adapter%', '%tester%'],
        'PC Case' => ['%bracket%', '%screw%', '%dust filter%'],
        'Cooling Components' => ['%thermal paste%', '%thermal grease%', '%bracket%'],
        'Monitor' => ['%bracket%', '%wall mount%', '%monitor stand%', '%cable%', '%adapter%'],
    ];

    /** @return array{product_family: string, product_role: string} */
    public function classify(string $category, string $name): array
    {
        $family = self::FAMILIES[$category] ?? ($category === 'Laptop Bags & Stands' ? 'computer' : 'unknown');
        $accessory = in_array($category, ['Laptop Bags & Stands', 'Cables & Adapters', 'Power Accessories'], true);
        foreach ((array_key_exists($category, self::ACCESSORIES) ? self::ACCESSORIES[$category] : []) as $pattern) {
            if (preg_match('/^'.str_replace('%', '.*', preg_quote($pattern, '/')).'$/iu', $name)) {
                $accessory = true;
            }
        }
        $unknown = false;
        if ($category === 'Printer' && preg_match('/^\s*printer\s/iu', $name) !== 1) {
            $accessory = $accessory || preg_match('/\b(?:ink|toner|cartridge)\b/iu', $name) === 1;
            $unknown = ! $accessory;
        }
        if ($category === 'Laptops & Desktops' && preg_match('/\b(?:barebones?|bundle|no ram|no hdd|no ssd)\b/iu', $name)) {
            $unknown = true;
        }
        if ($category === 'Power Accessories' && preg_match('/\b(?:laptop|notebook)\b/iu', $name)) {
            $family = 'computer';
        }
        if ($family === 'computer' && ! $accessory) {
            $family = preg_match('/\b(?:laptop|notebook|macbook)\b/iu', $name) ? 'laptop'
                : (preg_match('/\b(?:desktop|system unit|pc|cpu)\b/iu', $name) ? 'desktop' : 'computer');
        }

        return ['product_family' => $family, 'product_role' => $accessory ? 'accessory' : ($family === 'unknown' || $unknown ? 'unknown' : 'main')];
    }

    public function accessoryRequest(string $query): bool
    {
        return preg_match('/\b(?:accessor(?:y|ies)|charger|ink|toner|cartridge|cable|adapter|adaptor|enclosure|reader|bracket|mount|bag|pouch|backpack|stand|riser|paste|grease|support holder)\b/iu', $query) === 1;
    }

    /** @return literal-string */
    public function primarySql(): string
    {
        return self::PRIMARY_SQL;
    }

    /** @param Builder<Product> $query */
    public function prioritize(Builder $query): void
    {
        $query->orderBy(new Expression('CASE WHEN '.$this->primarySql().' THEN 0 ELSE 1 END'));
    }

    /** @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>> */
    public function order(array $products, ?string $sort = null, bool $accessories = false, bool $diversify = false, bool $mixed = false): array
    {
        $ranks = array_flip(array_column($products, 'id'));
        $prices = app(ChatbotPriceIntent::class);
        usort($products, function (array $first, array $second) use ($sort, $accessories, $ranks, $mixed, $prices): int {
            $firstRole = $this->classify($first['category'], $first['name'])['product_role'];
            $secondRole = $this->classify($second['category'], $second['name'])['product_role'];
            $role = $mixed ? 0 : ((int) ($firstRole !== ($accessories ? 'accessory' : 'main')) <=> (int) ($secondRole !== ($accessories ? 'accessory' : 'main')));
            $price = $sort === null ? 0 : bccomp(
                $prices->money((string) ($first['discount_price'] ?? $first['price'])),
                $prices->money((string) ($second['discount_price'] ?? $second['price'])),
                2,
            ) * ($sort === 'desc' ? -1 : 1);

            return $role ?: $price ?: ($sort === null ? ($ranks[$first['id']] <=> $ranks[$second['id']]) : ($first['id'] <=> $second['id']));
        });
        if ($diversify && $sort === null) {
            $first = $rest = $seen = [];
            foreach ($products as $product) {
                $classification = $this->classify($product['category'], $product['name']);
                if ($classification['product_role'] === 'main' && ! isset($seen[$classification['product_family']])) {
                    $first[] = $product;
                    $seen[$classification['product_family']] = true;
                } else {
                    $rest[] = $product;
                }
            }
            $products = [...$first, ...$rest];
        }

        return $products;
    }
}
