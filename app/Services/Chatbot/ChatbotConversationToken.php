<?php

namespace App\Services\Chatbot;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use JsonException;

class ChatbotConversationToken
{
    public function __construct(private ChatbotMemory $memory) {}

    /** @param array<string, mixed> $state */
    public function encode(array $state, string $subject): string
    {
        if ($this->memory->enabled($subject)) {
            try {
                $reference = $this->memory->save($state, $subject);
            } catch (QueryException) {
                Log::warning('Chatbot memory unavailable', ['operation' => 'write']);
                $reference = null;
            }

            return Crypt::encryptString(json_encode([
                'version' => 2,
                'subject' => hash('sha256', $subject),
                'expires_at' => now()->addMinutes(15)->timestamp,
                'state' => $reference ?? [],
            ], JSON_THROW_ON_ERROR));
        }
        unset($state['_memory']);

        return Crypt::encryptString(json_encode([
            'version' => 1,
            'subject' => hash('sha256', $subject),
            'expires_at' => now()->addMinutes(15)->timestamp,
            'state' => $state,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function decode(?string $token, string $subject): array
    {
        if ($token === null) {
            return [];
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return [];
        }

        if (! is_array($payload)
            || ! in_array($payload['version'] ?? null, [1, 2], true)
            || ! is_string($payload['subject'] ?? null)
            || ! hash_equals(hash('sha256', $subject), $payload['subject'])
            || ! is_int($payload['expires_at'] ?? null)
            || $payload['expires_at'] <= now()->timestamp
            || ! is_array($payload['state'] ?? null)) {
            return [];
        }

        try {
            return $payload['version'] === 2
                ? $this->memory->load($payload['state'], $subject)
                : $payload['state'];
        } catch (QueryException) {
            Log::warning('Chatbot memory unavailable', ['operation' => 'read']);

            return [];
        }
    }
}
