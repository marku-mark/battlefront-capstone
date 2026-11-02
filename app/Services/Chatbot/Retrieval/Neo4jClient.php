<?php

namespace App\Services\Chatbot\Retrieval;

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
     * @return list<array{product_id:int,revision:int}> */
    public function candidates(array $ids, ?int $seedRevision = null): array
    {
        $data = $this->query('MATCH (seed:BattlefrontProduct)-[:HAS_FACT]->(fact:BattlefrontFact)<-[:HAS_FACT]-(p:BattlefrontProduct) WHERE seed.mysql_id IN $ids AND seed.visible = true AND ($seedRevision IS NULL OR seed.revision = $seedRevision) AND p.visible = true RETURN DISTINCT p.mysql_id, p.revision LIMIT 20', ['ids' => array_slice(array_values(array_filter($ids, fn ($id): bool => $id > 0)), 0, 20), 'seedRevision' => $seedRevision], true);
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
        foreach (['socket', 'memory_type', 'form_factor', 'interface'] as $key) {
            $value = $document['specifications'][$key] ?? null;
            if (is_string($value) && strlen($value) <= 80) {
                $facts[] = ['key' => $key, 'value' => $value];
            }
        }
        $this->query('MERGE (p:BattlefrontProduct {mysql_id: $id}) WITH p WHERE coalesce(p.revision, 0) <= $revision SET p.revision = $revision, p.visible = $visible, p.name = $name, p.component_type = $type, p.specification_revision = $spec_revision, p.source_hash = $hash WITH p OPTIONAL MATCH (p)-[old:HAS_FACT]->() DELETE old WITH DISTINCT p FOREACH (fact IN $facts | MERGE (f:BattlefrontFact {kind: fact.key, value: fact.value}) MERGE (p)-[:HAS_FACT]->(f))', ['id' => $id, 'revision' => $revision, 'visible' => $document !== null, 'name' => $document['name'] ?? null, 'type' => $document['component_type'] ?? null, 'spec_revision' => $document['specification_revision'] ?? null, 'hash' => $document['hash'] ?? null, 'facts' => $facts], false);
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
            $response = Http::acceptJson()->withBasicAuth((string) config('ai_integration.neo4j.username'), (string) config('ai_integration.neo4j.password'))->connectTimeout(2)->timeout(5)->withoutRedirecting()->post($url.'/db/'.$database.'/query/v2', ['statement' => $statement, 'parameters' => $parameters === [] ? (object) [] : $parameters, 'accessMode' => $read ? 'Read' : 'Write']);
            if (! $response->successful() || ! is_array($response->json()) || $response->json('errors')) {
                throw new RuntimeException('Graph unavailable.');
            }

            return $response->json();
        } catch (\Throwable) {
            throw new RuntimeException('Graph unavailable.');
        }
    }
}
