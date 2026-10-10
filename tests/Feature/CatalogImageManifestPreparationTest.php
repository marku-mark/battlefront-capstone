<?php

use Symfony\Component\Process\Process;

test('the private category workbooks prepare one stable job per source row', function () {
    if (PHP_OS_FAMILY !== 'Windows' || ! is_file(storage_path('app/imports/product_catalog/Processor.xlsx'))) {
        $this->markTestSkipped('The private Windows catalog workbooks are unavailable.');
    }

    $manifestPath = sys_get_temp_dir().'/battlefront-manifest-'.bin2hex(random_bytes(8)).'.json';

    try {
        $command = new Process([
            'powershell',
            '-NoProfile',
            '-File',
            base_path('database/PrepareCatalogImageManifest.ps1'),
            '-ManifestPath',
            $manifestPath,
        ]);
        $command->run();

        expect($command->isSuccessful())->toBeTrue();
        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $jobs = collect($manifest['entries'])->keyBy('product_code');

        expect($jobs)->toHaveCount(654)
            ->and($jobs['SIX001601001999888']['category'])->toBe('Networking')
            ->and($jobs['150']['image_path'])->toBe('products/service/150.webp')
            ->and($jobs['80520996']['import_issues'])->toBe(['missing_quantity'])
            ->and($jobs['10041']['name'])->toContain('™');
    } finally {
        if (is_file($manifestPath)) {
            unlink($manifestPath);
        }
    }
});

test('preparation preserves progress when the private workbooks have not changed', function () {
    if (PHP_OS_FAMILY !== 'Windows' || ! is_file(storage_path('app/imports/product_catalog/Processor.xlsx'))) {
        $this->markTestSkipped('The private Windows catalog workbooks are unavailable.');
    }

    $manifestPath = sys_get_temp_dir().'/battlefront-manifest-'.bin2hex(random_bytes(8)).'.json';
    $arguments = [
        'powershell',
        '-NoProfile',
        '-File',
        base_path('database/PrepareCatalogImageManifest.ps1'),
        '-ManifestPath',
        $manifestPath,
    ];

    try {
        $first = new Process($arguments);
        $first->run();
        expect($first->isSuccessful())->toBeTrue();

        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifest['entries'][0]['status'] = 'complete';
        $manifest['entries'][0]['source_quantity'] = $manifest['entries'][0]['quantity'];
        $manifest['entries'][0]['quantity_source'] = 'development_demo';
        $manifest['entries'][0]['quantity'] = '11';
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        $second = new Process($arguments);
        $second->run();

        expect($second->isSuccessful())->toBeTrue()
            ->and(json_decode(file_get_contents($manifestPath), true))->toBe($manifest);
    } finally {
        if (is_file($manifestPath)) {
            unlink($manifestPath);
        }
    }
});

test('preparation refuses a manifest whose source fingerprint has changed', function () {
    if (PHP_OS_FAMILY !== 'Windows' || ! is_file(storage_path('app/imports/product_catalog/Processor.xlsx'))) {
        $this->markTestSkipped('The private Windows catalog workbooks are unavailable.');
    }

    $manifestPath = sys_get_temp_dir().'/battlefront-manifest-'.bin2hex(random_bytes(8)).'.json';
    $arguments = [
        'powershell',
        '-NoProfile',
        '-File',
        base_path('database/PrepareCatalogImageManifest.ps1'),
        '-ManifestPath',
        $manifestPath,
    ];

    try {
        $first = new Process($arguments);
        $first->run();
        expect($first->isSuccessful())->toBeTrue();

        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifest['entries'][0]['source_sha256'] = str_repeat('0', 64);
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        $second = new Process($arguments);
        $second->run();

        expect($second->isSuccessful())->toBeFalse()
            ->and(json_decode(file_get_contents($manifestPath), true)['entries'][0]['source_sha256'])->toBe(str_repeat('0', 64));
    } finally {
        if (is_file($manifestPath)) {
            unlink($manifestPath);
        }
    }
});
