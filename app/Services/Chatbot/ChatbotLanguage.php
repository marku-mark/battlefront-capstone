<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Str;

class ChatbotLanguage
{
    public const LANGUAGES = ['en', 'hil', 'ceb', 'tl'];

    /** Phrase aliases affect matching only; original messages remain available to entity resolvers. */
    private const ALIASES = [
        '\b(?:what would (?:that|this|it) set me back|what does (?:it|that) cost|how much is (?:it|that))\b' => 'price it',
        '\b(?:paano (?:ko )?(?:makikita|tingnan|i check)|diin (?:ko )?makita|asa (?:nako )?makita) (?:ang )?(?:akon |akong |aking |my )?order (?:history|status)\b' => 'how can i check my order status',
        '\b(?:paano|unsaon) (?:mag search|maghanap|mangita) (?:ng |ug |sang )?(?:products|produkto)\b' => 'how do i search for products',
        '\b(?:what do you sell|what can i buy|what do you have for sale)\b' => 'what products do you have',
        '\b(?:pila|magkano|tagpila) (?:ang )?(?:delivery|shipping|pagpadala)(?: fee| cost)?\b' => 'delivery fee',
        '\b(?:diin|asa|nasaan|saan) (?:na )?(?:ang )?(?:akon|akong|aking) (?:order|gin order)\b' => 'my order status',
        '\b(?:diin|asa|nasaan|saan) (?:na )?(?:ang )?order (?:ko|nako)\b' => 'my order status',
        '\b(order|payment|refund|return) (?:ko|nako)\b' => 'my $1',
        '\b(?:akon|akong|aking) (order|payment|refund|return)\b' => 'my $1',
        '\b(?:paano|pano|paano po|unsaon|paano bala) (?:magbayad|mag bayad|pagbayad|bayaran)\b' => 'how do i pay',
        '\b(?:how (?:can|do) i|can i) (?:pay|settle up)\b' => 'how do i pay',
        '\b(?:paano|unsaon) (?:i )?cancel\b' => 'how to cancel',
        '\b(?:pwede|puwede|mahimo) (?:ko )?(?:bang |ba )?(?:i ?balik|ibalik|magbalik)\b' => 'how to return',
        '\b(?:pwede|puwede|mahimo) (?:ko )?(?:bang |ba )?(?:i ?cancel|kanselahin|kanselahon)\b' => 'how to cancel',
        '\b(?:diin kamo dampi|diin kamo|diin ang (?:inyo )?tindahan|asa inyong tindahan|asa mo dapit|saan kayo located|saan ang (?:inyong )?tindahan)\b' => 'store location',
        '\b(?:anong oras|ano oras|ano nga oras|unsang orasa) (?:kamo |mo |kayo )?(?:magbukas|bukas|mag abri|abri|moabli|abli)\b' => 'opening hours',
        '\b(?:anong oras|ano nga oras|unsang orasa) (?:kamo |mo |kayo )?(?:magsara|mag sara|mosira|sirado)\b' => 'closing hours',
        '\b(?:ano|unsa) (?:ang )?(?:inyo|inyong) (?:contact|numero|number)\b' => 'contact number',
        '\b(?:may ara (?:pa )?(?:kamo|kamo sang)|may stock pa|may stock ba|naa (?:pa )?moy|naa pa ba|meron (?:pa )?ba (?:kayong|kayo)|mayroon ba kayong)\b' => 'available',
        '\b(?:may ara pa|ara pa|naa pa)\b' => 'available',
        '\b(?:ano (?:ang )?(?:mga )?(?:baligya|produkto) (?:ninyo|niyo)|unsa inyong (?:baligya|mga produkto)|anong (?:mga )?products (?:meron|mayroon) kayo)\b' => 'what products do you have',
        '\b(?:tagpila|magkano|pila)(?: (?:ang|po|sang|ka))?\b' => 'price',
        '\b(?:balik bayad|ibalik ang bayad|uli sa bayad)\b' => 'refund',
        '\b(?:bayad|pagbayad|pagbabayad)\b' => 'payment',
        '\b(?:napamatud an|na verify|na verified|na beripika)\b' => 'verified',
        '\b(?:pagpadala|paghatod)\b' => 'delivery',
        '\b(?:kanselahin|kanselahon|kanselasyon)\b' => 'cancel',
        '\b(?:ibalik|isauli|iuli|pagbalik)\b' => 'return',
        '\b(?:kag|ug|at saka|pati|tsaka)\b' => 'and',
        '\b(?:ini|sina|sini|kana|niana|niini|ito|iyan|iyon|nito|niyan|nun)\b' => 'it',
        '\b(?:didto|dira|doon|diyan)\b' => 'there',
        '\b(?:kamusta naman|paano naman|kumusta ang)\b' => 'what about',
    ];

    public function canonical(string $message): string
    {
        $message = $this->withoutClarificationPrefix($this->withoutLanguageRequest($message));
        foreach (self::ALIASES as $pattern => $replacement) {
            $message = preg_replace('/'.$pattern.'/iu', $replacement, $message) ?? $message;
        }

        return Str::squish($message);
    }

    public function withoutClarificationPrefix(string $message): string
    {
        return preg_replace('/^\s*(?:i mean|i meant|i am referring to|ang ibig kong sabihin(?: ay)?|ang pasabot nako(?: kay)?|ang buot ko silingon(?: amo)?)\b[,:]?\s*/iu', '', $message) ?? $message;
    }

    public function isAcknowledgement(string $message): bool
    {
        return preg_match('/^(?:yes|yeah|yep|ok|okay|sure|huo(?: gid)?|oo(?: po| naman)?|opo|sige(?: po)?)$/iu', trim($this->withoutLanguageRequest($message), " \t\n\r\0\x0B.!?")) === 1;
    }

    public function normalized(string $message): string
    {
        return Str::of($this->canonical($message))->lower()->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')->squish()->toString();
    }

    public function explicit(string $message): ?string
    {
        if (preg_match('/\b(?:answer|reply|respond|speak|use|in|sabat|tubag|sagot|sumagot)(?:\s+(?:in|sa|lang|using))?\s+(english|hiligaynon|ilonggo|bisaya|cebuano|tagalog|filipino)\b/iu', $message, $matches) !== 1) {
            return null;
        }

        return match (Str::lower($matches[1])) {
            'hiligaynon', 'ilonggo' => 'hil',
            'bisaya', 'cebuano' => 'ceb',
            'tagalog', 'filipino' => 'tl',
            default => 'en',
        };
    }

    public function withoutLanguageRequest(string $message): string
    {
        return trim(preg_replace('/\b(?:please\s+)?(?:answer|reply|respond|speak|use|in|sabat|tubag|sagot|sumagot)(?:\s+(?:in|sa|lang|using))?\s+(?:english|hiligaynon|ilonggo|bisaya|cebuano|tagalog|filipino)\b[.!]?/iu', '', $message) ?? $message);
    }

    public function detect(string $message): ?string
    {
        $matches = [];
        foreach (['hil' => 'may ara|diin|kag|sang|akon|sini', 'ceb' => 'naa|moy|asa|unsa|unsaon|akong|nako|inyong|kana', 'tl' => 'magkano|paano|nasaan|meron|mayroon|aking|kayong|niyan|po'] as $language => $words) {
            if (preg_match('/\b(?:'.$words.')\b/iu', $message) === 1) {
                $matches[] = $language;
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        return $matches === [] && preg_match('/\b(?:how|where|what|which|is the|do you|can i)\b/iu', $message) === 1 ? 'en' : null;
    }

    public function select(string $message, ?string $previous = null): string
    {
        return $this->explicit($message) ?? $this->detect($message) ?? (in_array($previous, self::LANGUAGES, true) ? $previous : 'en');
    }

    public function blocked(string $message): bool
    {
        if ($this->isPcBuilderHelp($message)) {
            return false;
        }

        return preg_match('/\b(?:pinakamahusay|pinakamaganda|pinaka maayo|pinakamaayo|pinakanindot|ano ang best|unsa ang best|ano.*maayo.*(?:laptop|mouse|computer)|irekomenda|rekomendasyon|mag recommend|i recommend|ma suggest|bagay sa akin|compatible|compatibility|bagay ba|compatible ba|panahon bukas|balita|joke|biro|resipe|recipe|homework|takdang aralin|translate this|isalin (?:ito|it)|hubara|ignore (?:all|previous)|system prompt|developer message)\b/iu', $message) === 1;
    }

    /** @return list<string> */
    public function clauses(string $message): array
    {
        return preg_split('/\s+(?:and|also|plus|kag|ug|at saka|pati|tsaka)\s+|[;?]+\s*(?:(?:and|also|plus|kag|ug|at saka)\s+)?/iu', $this->withoutLanguageRequest($message), flags: PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public function isPcBuilderHelp(string $message): bool
    {
        return $this->pcBuilderHelpTopic($message) !== null;
    }

    public function pcBuilderHelpTopic(string $message): ?string
    {
        $text = trim($this->normalized($message));
        foreach ([
            'overview' => '/\A(?:how (?:do|can) i use|where can i open) pc builder\z/u',
            'components' => '/\Ahow (?:do|can) i (?:select|choose) components in pc builder\z/u',
            'saved' => '/\A(?:how do saved pc builds work|how (?:do|can) i (?:save|share|load) (?:my|a) pc build)\z/u',
            'templates' => '/\A(?:how do pc builder templates work|how (?:do|can) i (?:use|load) a pc builder template)\z/u',
            'checks' => '/\Awhere can i (?:view|see|find) pc builder compatibility results\z/u',
        ] as $topic => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $topic;
            }
        }

        return null;
    }

    public function classifierCandidate(string $message): bool
    {
        return ! $this->blocked($message)
            && preg_match('/\b(?:battlefront|your shop|inyong shop|inyo nga shop|purchase|bought|parcel|checkout|tindahan)\b/iu', $message) === 1;
    }
}
