<?php

namespace App\Services\Chatbot;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

class ChatbotMemory
{
    public function enabled(string $subject): bool
    {
        return (bool) config('ai_integration.memory.enabled', false)
            && ! str_starts_with($subject, 'api:guest:');
    }

    /** @param array<string, mixed> $reference
     * @return array<string, mixed>
     */
    public function load(array $reference, string $subject): array
    {
        if (! $this->enabled($subject) || ! is_string($reference['id'] ?? null) || ! is_int($reference['version'] ?? null)) {
            return [];
        }
        $record = DB::table('chatbot_conversations')->where('id', $reference['id'])
            ->where('subject_hash', hash('sha256', $subject))->where('version', $reference['version'])
            ->where('expires_at', '>', now())->where('delete_after', '>', now())->first();
        if ($record === null) {
            return [];
        }
        try {
            $state = json_decode(Crypt::decryptString($record->state), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return [];
        }

        return is_array($state) ? [...$state, '_memory' => $reference] : [];
    }

    /** @param array<string, mixed> $state
     * @return array{id: string, version: int}|null
     */
    public function save(array $state, string $subject): ?array
    {
        $reference = $state['_memory'] ?? null;
        unset($state['_memory']);
        $encrypted = Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR));
        if (strlen($encrypted) > 32768) {
            return null;
        }
        if (is_array($reference) && is_string($reference['id'] ?? null) && is_int($reference['version'] ?? null)) {
            $updated = DB::table('chatbot_conversations')->where('id', $reference['id'])
                ->where('subject_hash', hash('sha256', $subject))->where('version', $reference['version'])
                ->where('expires_at', '>', now())->where('delete_after', '>', now())
                ->update(['state' => $encrypted, 'version' => $reference['version'] + 1,
                    'expires_at' => now()->addMinutes(15), 'updated_at' => now()]);

            return $updated === 1 ? ['id' => $reference['id'], 'version' => $reference['version'] + 1] : null;
        }
        $id = (string) Str::uuid();
        DB::table('chatbot_conversations')->insert([
            'id' => $id, 'subject_hash' => hash('sha256', $subject), 'version' => 1,
            'state' => $encrypted, 'messages' => Crypt::encryptString('[]'),
            'expires_at' => now()->addMinutes(15),
            'delete_after' => now()->addDays(max(1, min(30, (int) config('ai_integration.memory.retention_days', 7)))),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'version' => 1];
    }

    /** Local history is never returned as model context.
     * @param  array<string, mixed>  $state
     */
    public function recordTurn(array $state, string $subject, string $message, string $answer): void
    {
        $reference = $state['_memory'] ?? null;
        if (! $this->enabled($subject) || ! is_array($reference)
            || ! in_array($state['active'] ?? null, ['product', 'store', 'faq'], true)
            || preg_match('/@|\b\d[\d\s+()-]{7,}\d\b|\b(?:password|address|email|phone|account|order|token|secret)\b/iu', $message.' '.$answer) === 1) {
            return;
        }
        DB::transaction(function () use ($reference, $subject, $message, $answer): void {
            $query = DB::table('chatbot_conversations')->where('id', $reference['id'])
                ->where('subject_hash', hash('sha256', $subject))->where('version', $reference['version'])
                ->where('expires_at', '>', now())->where('delete_after', '>', now());
            $record = (clone $query)->lockForUpdate()->first();
            if ($record === null) {
                return;
            }
            try {
                $messages = json_decode(Crypt::decryptString($record->messages), true, flags: JSON_THROW_ON_ERROR);
            } catch (DecryptException|JsonException) {
                $messages = [];
            }
            $messages = is_array($messages) ? $messages : [];
            $messages[] = ['user' => Str::limit($message, 1000, ''), 'assistant' => Str::limit($answer, 4000, ''), 'at' => now()->toIso8601String()];
            $limit = max(1, min(20, (int) config('ai_integration.memory.max_messages', 20)));
            $query->update(['messages' => Crypt::encryptString(json_encode(array_slice($messages, -$limit), JSON_THROW_ON_ERROR))]);
        });
    }
}
