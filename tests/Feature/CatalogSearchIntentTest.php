<?php

use App\Repositories\Catalog\CatalogSearchIntent;

test('common part names resolve to actual catalog categories', function (string $query, string $category, string $remaining) {
    expect(app(CatalogSearchIntent::class)->resolve($query))->toBe(['category' => $category, 'query' => $remaining]);
})->with([
    [' CPU ', 'Processor', ''], ['processor', 'Processor', ''], ['CPU AMD', 'Processor', 'amd'],
    ['ram 16GB', 'RAM', '16gb'], ['memory', 'RAM', ''], ['mobo', 'Motherboard', ''],
    ['computer RAM', 'RAM', ''], ['computer RAM 16GB', 'RAM', '16gb'],
    ['mainboard', 'Motherboard', ''], ['gpu', 'Graphics Card', ''], ['video card', 'Graphics Card', ''],
    ['storage', 'Storage', ''], ['SSD', 'Storage', 'ssd'], ['solid state drive 1TB', 'Storage', 'ssd 1tb'],
    ['HDD', 'Storage', 'hdd'], ['hard disk', 'Storage', 'hdd'], ['NVMe 1TB', 'Storage', 'nvme 1tb'],
    ['flash drive', 'Storage', 'flash'], ['psu', 'Power Supply', ''], ['power supply unit 650W', 'Power Supply', '650w'],
    ['chassis', 'PC Case', ''], ['pc case', 'PC Case', ''], ['cpu cooler', 'Cooling Components', ''],
    ['cpu fan', 'Cooling Components', ''], ['cooling', 'Cooling Components', ''], ['monitor', 'Monitor', ''],
    ['display', 'Monitor', ''], ['keyboard', 'Peripherals', 'keyboard'], ['mouse', 'Peripherals', 'mouse'],
    ['router', 'Networking', 'router'], ['headset', 'Audio Equipment', 'headset'], ['cables', 'Cables & Adapters', ''],
    ['printer', 'Printer', ''], ['laptop', 'Laptops & Desktops', 'laptop'], ['desktop', 'Laptops & Desktops', 'desktop'],
    ['printing machine', 'Printer', ''], ['printing', 'Printer', ''], ['ink bottle', 'Printer', 'ink'],
    ['printer toner', 'Printer', 'toner'], ['printer cable', 'Printer', 'cable'],
    ['random access memory', 'RAM', ''], ['SO-DIMM', 'RAM', 'sodimm'], ['DDR5 16GB', 'RAM', 'ddr5 16gb'],
    ['memory card', 'Storage', 'card'], ['hard disk drive 2TB', 'Storage', 'hdd 2tb'], ['thumb drive', 'Storage', 'flash'],
    ['cpu case', 'PC Case', ''], ['system board', 'Motherboard', ''],
    ['bundle', 'Bundles & Packages', ''], ['CCTV', 'CCTV & Security', ''], ['network video recorder', 'CCTV & Security', 'nvr'],
    ['digital video recorder', 'CCTV & Security', 'dvr'], ['cctv cable', 'CCTV & Security', 'cable'],
    ['UPS', 'Power Accessories', 'ups'], ['uninterruptible power supply', 'Power Accessories', 'ups'],
    ['automatic voltage regulator', 'Power Accessories', 'avr'], ['battery backup', 'Power Accessories', 'ups'],
    ['printer ink', 'Printer', 'ink'], ['toner', 'Printer', 'toner'], ['laptop bag', 'Laptop Bags & Stands', ''],
    ['laptop stand', 'Laptop Bags & Stands', 'stand'], ['laptop backpack', 'Laptop Bags & Stands', 'backpack'],
    ['cleaning supplies', 'Cleaning & Maintenance Supplies', ''], ['electric blower', 'Cleaning & Maintenance Supplies', 'blower'],
    ['TV stick', 'Consumer Electronics', 'tv stick'], ['microcontrollers', 'DIY Electronics & Microcontrollers', ''],
    ['NodeMCU', 'DIY Electronics & Microcontrollers', 'nodemcu'], ['gaming chair', 'Furniture & Hardware Fixtures', 'chair'],
    ['office supplies', 'Office & Packaging Supplies', ''], ['bond paper', 'Office & Packaging Supplies', 'paper'],
    ['solar light', 'Solar & Lighting Products', 'solar'], ['piso wifi', 'Vending & Coin-Op Machine Parts', 'piso'],
    ['water timer', 'Vending & Coin-Op Machine Parts', 'timer'], ['checkup', 'Service', ''],
    ['Wi-Fi adapter', 'Networking', 'adapter'], ['wireless repeater', 'Networking', 'repeater'],
]);

test('ordinary model names and partial words do not trigger category aliases', function (string $query) {
    expect(app(CatalogSearchIntent::class)->resolve($query))->toBeNull();
})->with(['AMD Ryzen 5600', 'RTX 4060', 'caseology', 'ramen', 'storagebox', 'gpy', 'cpi', 'ssx', 'DDR6', 'adapter', 'USB', 'USB A', 'USB-A', 'A8-7680', '650W', '16GB']);

test('longer category words tolerate one typo without losing suffixes', function (string $query, string $category, string $remaining) {
    expect(app(CatalogSearchIntent::class)->resolve($query))->toBe(['category' => $category, 'query' => $remaining]);
})->with([
    ['proccessor AMD', 'Processor', 'amd'], ['procesor', 'Processor', ''], ['motheboard', 'Motherboard', ''],
    ['moniter', 'Monitor', ''], ['monitr', 'Monitor', ''], ['monitro', 'Monitor', ''],
    ['keybaord RGB', 'Peripherals', 'keyboard rgb'], ['storag', 'Storage', ''], ['storge', 'Storage', ''],
    ['cpu coler', 'Cooling Components', ''], ['memory crad', 'Storage', 'card'], ['power suply 650W', 'Power Supply', '650w'],
    ['laptop stnad', 'Laptop Bags & Stands', 'stand'], ['cleanign supplies', 'Cleaning & Maintenance Supplies', ''],
    ['surveilance', 'CCTV & Security', ''], ['micrcontrollers', 'DIY Electronics & Microcontrollers', ''],
]);

test('long phrases resolve the most specific part while retaining brand and capacity', function (string $query, string $category, string $remaining) {
    expect(app(CatalogSearchIntent::class)->resolve($query))->toBe(['category' => $category, 'query' => $remaining]);
})->with([
    ['please show me a wireless keyboard with RGB', 'Peripherals', 'wireless keyboard rgb'],
    ['Epson printer', 'Printer', 'epson'], ['looking for a 1 TB solid state drive', 'Storage', 'ssd 1tb'],
    ['I need an AMD Ryzen processor', 'Processor', 'amd ryzen'], ['16 GB RAM DDR4', 'RAM', '16gb ddr4'],
    ['laptop with 16 GB RAM', 'Laptops & Desktops', 'laptop 16gb ram'],
    ['laptop charger for laptop', 'Power Accessories', 'charger laptop'],
    ['charger for laptop', 'Power Accessories', 'charger'], ['laptop charger', 'Power Accessories', 'charger'],
    ['replacement battery for UPS', 'Power Accessories', 'battery ups replacement'],
    ['I need a case for SSD', 'Storage', 'enclosure'], ['TV wall mount', 'Monitor', 'mount'],
    ['Cooling Components', 'Cooling Components', ''], ['CCTV & Security', 'CCTV & Security', ''],
    ['microcontolers', 'DIY Electronics & Microcontrollers', ''],
    ['wireles keybaord RGB', 'Peripherals', 'wireless keyboard rgb'],
]);

test('unknown accessory relationships do not force a misleading device category', function () {
    expect(app(CatalogSearchIntent::class)->resolve('cable for monitor'))->toBeNull();
});

test('exact phrases take precedence over fuzzy aliases and equally exact device intents stay ambiguous', function () {
    $resolver = app(CatalogSearchIntent::class);

    expect($resolver->resolve('moniter motherboard'))->toBe(['category' => 'Motherboard', 'query' => 'moniter'])
        ->and($resolver->resolve('printer monitor'))->toBeNull();
    expect($resolver->resolve('laptop with 16GB RAM'))->toBe(['category' => 'Laptops & Desktops', 'query' => 'laptop 16gb ram']);
});

test('category intent preserves model codes and interface labels in the remaining query', function () {
    expect(app(CatalogSearchIntent::class)->resolve('A8-7680 processor'))->toBe(['category' => 'Processor', 'query' => 'a8-7680'])
        ->and(app(CatalogSearchIntent::class)->resolve('USB A motherboard'))->toBe(['category' => 'Motherboard', 'query' => 'usb a']);
});

test('Algolia synonym vocabulary keeps non-equivalent storage standards separate', function () {
    $synonyms = collect(CatalogSearchIntent::algoliaSynonyms())->keyBy('objectID');

    expect($synonyms['catalog-solid-state-drive']['synonyms'])->toContain('ssd', 'solid state drive')
        ->and($synonyms['catalog-hard-disk-drive']['synonyms'])->toContain('hdd', 'hard drive')
        ->and($synonyms['catalog-solid-state-drive']['synonyms'])->not->toContain('hdd', 'nvme', 'm.2');
});
