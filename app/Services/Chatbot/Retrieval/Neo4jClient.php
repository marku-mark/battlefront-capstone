<?php

namespace App\Services\Chatbot\Retrieval;

use App\Services\Chatbot\ChatbotProviderBudget;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Neo4jClient
{
    public function initialize(): void
    {
        $this->query('CREATE CONSTRAINT battlefront_product_id IF NOT EXISTS FOR (p:BattlefrontProduct) REQUIRE p.mysql_id IS UNIQUE', [], false);
        $this->query('CREATE CONSTRAINT battlefront_fact_identity IF NOT EXISTS FOR (f:BattlefrontFact) REQUIRE (f.kind, f.value) IS UNIQUE', [], false);
    }

    /** @param list<int> $ids
     * @param list<array{id: int, revision: int}> $seedRevisions
     * @return list<array{product_id:int,revision:int}> */
    public function candidates(array $ids, ?int $seedRevision = null, array $seedRevisions = []): array
    {
        $data = $this->query('MATCH (seed:BattlefrontProduct)-[:HAS_FACT]->(fact:BattlefrontFact)<-[:HAS_FACT]-(p:BattlefrontProduct) WHERE seed.mysql_id IN $ids AND seed.visible = true AND ($seedRevision IS NULL OR seed.revision = $seedRevision) AND (size($seedRevisions) = 0 OR any(expected IN $seedRevisions WHERE expected.id = seed.mysql_id AND expected.revision = seed.revision)) AND p.visible = true AND p.mysql_id <> seed.mysql_id AND (p.component_type = seed.component_type OR (p.component_type IS NULL AND seed.component_type IS NULL)) AND p.product_family = seed.product_family AND p.product_role = seed.product_role WITH p, count(DISTINCT fact) AS relevance RETURN p.mysql_id, p.revision ORDER BY relevance DESC, p.mysql_id LIMIT 20', ['ids' => array_slice(array_values(array_filter($ids, fn ($id): bool => $id > 0)), 0, 20), 'seedRevision' => $seedRevision, 'seedRevisions' => $seedRevisions], true);
        $result = [];
        foreach ($data['data']['values'] ?? [] as $row) {
            if (is_numeric($row[0] ?? null) && is_numeric($row[1] ?? null)) {
                $result[] = ['product_id' => (int) $row[0], 'revision' => (int) $row[1]];
            }
        }

        return $result;
    }

    /** @param array<string,mixed>|null $document */
    public function project(int $id, int $revision, ?array $document): void
    {
        $facts = [];
        foreach (['product_family', 'brand'] as $key) {
            if (is_string($document[$key] ?? null) && $document[$key] !== 'unknown' && $document[$key] !== '') {
                $facts[] = ['key' => $key, 'value' => $document[$key]];
            }
        }
        foreach (['socket', 'memory_type', 'form_factor', 'interface'] as $key) {
            $value = $document['specifications'][$key] ?? null;
            if (is_string($value) && strlen($value) <= 80) {
                $facts[] = ['key' => $key, 'value' => $value];
            }
        }
        $this->query('MERGE (p:BattlefrontProduct {mysql_id: $id}) WITH p WHERE coalesce(p.revision, 0) <= $revision SET p.revision = $revision, p.visible = $visible, p.name = $name, p.component_type = $type, p.product_family = $family, p.product_role = $role, p.specification_revision = $spec_revision, p.source_hash = $hash WITH p OPTIONAL MATCH (p)-[old:HAS_FACT]->() DELETE old WITH DISTINCT p FOREACH (fact IN $facts | MERGE (f:BattlefrontFact {kind: fact.key, value: fact.value}) MERGE (p)-[:HAS_FACT]->(f))', ['id' => $id, 'revision' => $revision, 'visible' => $document !== null, 'name' => $document['name'] ?? null, 'type' => $document['component_type'] ?? null, 'family' => $document['product_family'] ?? null, 'role' => $document['product_role'] ?? null, 'spec_revision' => $document['specification_revision'] ?? null, 'hash' => $document['hash'] ?? null, 'facts' => $facts], false);
    }

    /** @param array<string,mixed> $parameters
     * @return array<string,mixed> */
    private function query(string $statement, array $parameters, bool $read): array
    {
        $url = rtrim((string) config('ai_integration.neo4j.url'), '/');
        $database = (string) config('ai_integration.neo4j.database', 'neo4j');
        if (! config('ai_integration.neo4j.enabled') || ! preg_match('~^https://[a-zA-Z0-9.-]+$~D', $url) || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $database) || ! config('ai_integration.neo4j.password') || ! config('ai_integration.neo4j.username')) {
            throw new RuntimeException('Graph unavailable.');
        }
        try {
            $timeout = $read ? app(ChatbotProviderBudget::class)->retrievalTimeout() : 5;
            if ($timeout === 0) {
                throw new RuntimeException('Retrieval deadline exceeded.');
            }
            $response = Http::acceptJson()->withBasicAuth((string) config('ai_integration.neo4j.username'), (string) config('ai_integration.neo4j.password'))->connectTimeout(min(2, $timeout))->timeout($timeout)->withoutRedirecting()->post($url.'/db/'.$database.'/query/v2', ['statement' => $statement, 'parameters' => $parameters === [] ? (object) [] : $parameters, 'accessMode' => $read ? 'Read' : 'Write']);
            if (! $response->successful() || ! is_array($response->json()) || $response->json('errors')) {
                throw new RuntimeException('Graph unavailable.');
            }

            return $response->json();
        } catch (\Throwable) {
            throw new RuntimeException('Graph unavailable.');
        }
    }
}
