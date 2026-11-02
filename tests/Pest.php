<?php

use App\Ai\Agents\ChatbotIntentAgent;
use App\Ai\Agents\ChatbotResponseAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        config()->set('inertia.ssr.enabled', false);
        // Chatbot verification must never consume the configured Gemini quota.
        config()->set('ai.providers.gemini.key', 'testing-only');
        config()->set([
            'ai_integration.enabled' => false,
            'ai_integration.offline' => false,
            'ai_integration.faq.enabled' => false,
            'ai_integration.localization_enabled' => false,
            'ai_integration.sync_enabled' => false,
            'ai_integration.advice_enabled' => false,
            'ai_integration.memory.enabled' => false,
            'ai_integration.qdrant.enabled' => false,
            'ai_integration.neo4j.enabled' => false,
            'ai_integration.gemini.validation_enabled' => false,
            'ai_integration.gemini.max_calls' => 1,
            'ai_integration.gemini.scope_key' => 'testing-only',
            'ai_integration.gemini.response_key' => 'testing-only',
            'ai_integration.gemini.validation_key' => 'testing-only',
            'ai_integration.embedding.key' => 'testing-only',
            'ai_integration.qdrant.key' => 'testing-only',
            'ai_integration.neo4j.password' => 'testing-only',
        ]);
        ChatbotResponseAgent::fake()->preventStrayPrompts();
        ChatbotIntentAgent::fake()->preventStrayPrompts();
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/*' => fn () => throw new RuntimeException('Gemini HTTP requests must be explicitly faked in tests.'),
        ]);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** @return array{package_weight_kg: string, package_length_cm: string, package_width_cm: string, package_height_cm: string, package_measurement_source: string} */
function validPackageMeasurements(): array
{
    return [
        'package_weight_kg' => '1.000',
        'package_length_cm' => '10.00',
        'package_width_cm' => '10.00',
        'package_height_cm' => '10.00',
        'package_measurement_source' => 'estimated',
    ];
}

/** Reconstruct a retained legacy assignment without changing fresh migration expectations. */
function legacyItRoleId(): int
{
    $existing = DB::table('administration_roles')->where('code', 'it')->value('id');
    if ($existing !== null) {
        return (int) $existing;
    }
    $id = DB::table('administration_roles')->insertGetId(['code' => 'it', 'name' => 'IT Administrator', 'created_at' => now(), 'updated_at' => now()]);
    foreach (['users.manage', 'audit.view'] as $code) {
        DB::table('administration_role_permissions')->insert(['administration_role_id' => $id, 'administration_permission_id' => DB::table('administration_permissions')->where('code', $code)->value('id')]);
    }

    return $id;
}
