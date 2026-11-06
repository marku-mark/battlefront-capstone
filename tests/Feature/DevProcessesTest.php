<?php

use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

test('development process previews include shopping workers and recovery without starting them', function (bool $herd) {
    Process::fake();

    try {
        $exitCode = Artisan::call($herd ? 'dev:herd' : 'dev:list', $herd ? ['--list' => true] : ['--json' => true]);
        $processes = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->keyBy('name');

        expect($exitCode)->toBe(0);
        expect($processes->get('queue')['command'])->toBe('php artisan queue:listen --queue=default --tries=3 --timeout=60');
        expect($processes->has('payments'))->toBeFalse();
        expect($processes->get('scheduler')['command'])->toBe('php artisan schedule:work');
        expect($processes->has('vite'))->toBeTrue();
        expect($processes->has('server'))->toBe(! $herd);
        Process::assertNothingRan();
    } finally {
        DevCommands::except();
    }
})->with(['standard development' => false, 'Herd development' => true]);
