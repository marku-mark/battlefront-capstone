<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('chatbot:prune-memory')]
#[Description('Permanently delete chatbot memory past its retention deadline')]
class PruneChatbotMemory extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $deleted = 0;
        do {
            $ids = DB::table('chatbot_conversations')->where('delete_after', '<=', now())->limit(500)->pluck('id');
            $deleted += DB::table('chatbot_conversations')->whereIn('id', $ids)->delete();
        } while ($ids->count() === 500);
        $this->info("Deleted {$deleted} expired conversations.");

        return self::SUCCESS;
    }
}
