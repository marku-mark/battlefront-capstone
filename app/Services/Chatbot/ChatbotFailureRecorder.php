<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\CategorizeChatbotQuery;
use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Ai\Agents\ChatbotUnderstandingAgent;
use App\Enums\ChatbotQueryCategory;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ChatbotFailureRecorder
{
    /** @param array<mixed> $productIds Untrusted decoded conversation references. */
    public function capture(string $question, string $answer, string $language, array $productIds, int $durationMs): void
    {
        if (! config('ai_integration.failure_review.enabled', true) || config('ai_integration.offline')) {
            return;
        }
        try {
            $trace = app(ChatbotFailureTrace::class);
            $categorizer = app(CategorizeChatbotQuery::class);
            if ($trace->event === null || $trace->scope === 'unsupported' || (! $trace->publicScopeConfirmed && $categorizer->isBlocked($question))) {
                return;
            }
            $category = $categorizer->execute($question);
            $scope = $trace->scope;
            if (app(ClassifyChatbotInquiry::class)->containsSensitiveInput($question.' '.$answer)
                || $category === ChatbotQueryCategory::Order
                || preg_match('/@|https?:\/\/|\b(?:password|otp|token|secret|address|email|phone|account|my order|aking order|akong order|akon order)\b|\bbf[\s_-]*\d+\b|\b\d[\d\s+()-]{7,}\d\b/iu', $question.' '.$answer)) {
                $scope = 'private';
            } elseif ($scope === 'unknown' && in_array($category, [ChatbotQueryCategory::Product, ChatbotQueryCategory::Store], true)) {
                $scope = 'public';
            }
            $language = in_array($language, ChatbotLanguage::LANGUAGES, true) ? $language : 'und';
            $productIds = $scope === 'public' ? array_slice(array_values(array_unique(array_filter($productIds, fn ($id): bool => is_int($id) && $id > 0))), 0, 6) : [];
            $names = $productIds === [] ? [] : array_values(Product::query()->customerEligible()->whereIn('id', $productIds)->get(['name'])->map(fn (Product $product): string => $product->name)->all());
            $sample = $scope === 'public' ? [
                'question' => $this->sanitize(mb_substr($question, 0, 1000), $names),
                'answer' => $this->sanitize(mb_substr($answer, 0, 4000), $names),
            ] : null;
            $promptHash = hash('sha256', (new ChatbotUnderstandingAgent)->instructions());
            $fingerprint = hash_hmac('sha256', json_encode([$trace->path, $trace->event, $scope, $language, $sample['question'] ?? null, $productIds, $promptHash], JSON_THROW_ON_ERROR), (string) config('app.key'));
            Cache::lock('chatbot:failure-recorder', 5)->get(function () use ($trace, $scope, $language, $sample, $productIds, $promptHash, $fingerprint, $durationMs): void {
                DB::transaction(function () use ($trace, $scope, $language, $sample, $productIds, $promptHash, $fingerprint, $durationMs): void {
                    $query = DB::table('chatbot_failure_events')->where('fingerprint', $fingerprint);
                    $existing = (clone $query)->lockForUpdate()->first();
                    if ($existing !== null && $existing->delete_after > now()->toDateTimeString()) {
                        $updates = ['last_seen_at' => now()];
                        if ($existing->status === 'resolved') {
                            $updates['status'] = 'open';
                            $updates['reviewed_at'] = null;
                        }
                        $query->increment('occurrences', 1, $updates);

                        return;
                    }
                    if ($existing !== null) {
                        $query->delete();
                    }
                    if (DB::table('chatbot_failure_events')->count() >= max(1, min(10000, (int) config('ai_integration.failure_review.max_records', 1000)))) {
                        return;
                    }
                    DB::table('chatbot_failure_events')->insert([
                        'id' => (string) Str::uuid(), 'fingerprint' => $fingerprint, 'language' => $language, 'scope' => $scope,
                        'path' => $trace->path, 'stage' => $trace->event['stage'], 'reason' => $trace->event['reason'],
                        'status' => 'open', 'occurrences' => 1,
                        'sample' => $sample === null ? null : Crypt::encryptString(json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                        'product_ids' => json_encode($productIds, JSON_THROW_ON_ERROR), 'prompt_hash' => $promptHash,
                        'pipeline_version' => 'failure-review-v1', 'duration_ms' => max(0, min($durationMs, 3600000)),
                        'first_seen_at' => now(), 'last_seen_at' => now(),
                        'delete_after' => now()->addDays(max(1, min(7, (int) config('ai_integration.failure_review.retention_days', 7)))),
                    ]);
                });
            });
        } catch (Throwable) {
            try {
                Log::warning('Chatbot failure recording unavailable.', ['failure' => 'storage_unavailable']);
            } catch (Throwable) {
                // Diagnostic failures must never replace a customer response.
            }
        }
    }

    /** Unknown words are masked, not merely checked for known personal-data patterns.
     * @param  list<string>  $names
     */
    private function sanitize(string $text, array $names): string
    {
        $words = 'do you have carry sell got any is are the a an in at of for with without not no yes and or but below above under over up to than most least expensive how much what which please kindly hello hi today now '
            .'meron merong mayroon mayroong may po opo ba kayong kayo ang ng mga pa rin din naman lang na ngayon magkano presyo magkakano hindi huwag pero hanggang '
            .'naa naay aduna moy mo inyong unsa asa pila tagpila ug og sa dili palihog karon pa bay akong '
            .'ara kamo sang kag bala gid bi diin ano akon indi subong tubtob nga ini '
            .'here matching products available current selling price lowest highest relevance main first selected these stock low out sagay php listed discount temporarily unavailable try again later could find matching provide name model clarify question unconfirmed information only';
        $public = app(PublicChatbotQuery::class)->minimize($text);
        preg_match_all('/(?:PHP|₱)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/iu', $text, $amounts);
        $allowed = preg_split('/[^\p{L}\p{N}._-]+/u', mb_strtolower($words.' '.$public.' '.implode(' ', $names).' '.implode(' ', $amounts[1]))) ?: [];
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags($text)) ?? '';

        return preg_replace_callback('/[\p{L}\p{N}]+(?:[._-][\p{L}\p{N}]+)*/u', fn (array $match): string => in_array(mb_strtolower($match[0]), $allowed, true) ? $match[0] : '[redacted]', $text) ?? '';
    }
}
