<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Str;

class ChatbotLanguage
{
    public const LANGUAGES = ['en', 'hil', 'ceb', 'tl'];

    /** Phrase aliases affect matching only; original messages remain available to entity resolvers. */
    private const ALIASES = [
        '\b(?:walay budget limit|walay limit sa budget|dili na limitahan ang budget|wala nang budget limit|walang budget limit|wala na sang budget limit|wala na limit sa budget)\b' => 'no budget',
        '\b(?:bisan ano nga presyo|kahit anong presyo|bisan ano nga price)\b' => 'any price',
        '\bcpu(?:s)?\b(?!-|\s+(?:cooler|fan|cooling|thermal|paste|holder|stand|case)\b)' => 'processor',
        '\b(?:paano|unsaon) (?:gamitin|gamiton|paggamit|paggamit sa|gamiton ang|gamitin ang|paggamit sa ang) (?:ang |sa )?pc builder\b(?!\s+templates)' => 'how do i use pc builder',
        '\b(?:paano|unsaon) (?:pumili|magpili|pagpili) (?:ng |sang |og )?components (?:sa|in) pc builder\b' => 'how do i select components in pc builder',
        '\b(?:paano|unsaon) (?:mag save|mag-save|pag save|pag-save) (?:ng |sang |og )?(?:pc )?build\b' => 'how do saved pc builds work',
        '\b(?:paano|unsaon) (?:gamitin|gamiton|paggamit) (?:ang |sa )?pc builder templates\b' => 'how do pc builder templates work',
        '\b(?:saan|diin|asa) (?:makikita|makita) (?:ang )?pc builder compatibility (?:results|resulta)\b' => 'where can i view pc builder compatibility results',
        '\b(?:paano|unsaon) (?:makita|pag tan aw|pagtan-aw|pagtan aw) (?:ang |sa )?detalye (?:ng |sang |sa )?produkto\b' => 'how do i view product details',
        '\b(?:naa moy|may ara kamo|mayroon ba kayong) real[ -]time delivery tracking\b' => 'do you offer real-time delivery tracking',
        '\b(?:paano|unsaon) (?:ko )?(?:makita|pagtan-aw|pagtan aw) (?:ang |sa )?(?:akong |akon |aking )?order (?:history|status)\b' => 'how can i check my order status',
        '\b(?:irekomenda|rekomendahi|rekomendasyon|isugyot|mag recommend|ma suggest|ano ang best|unsa ang best|ano ang maayo)\b' => 'recommend',
        '\b(?:(?:ang|yung|katong) )?(?:mas barato|mas mura)\b' => 'cheaper',
        '\b(?:barato|mura|abot kaya)\b' => 'affordable',
        '\b(?:ubos sa|ilalom sa|mas mababa sa|mas manubo sa|indi maglapaw sa|dili molapas sa|hindi lalampas sa)\b' => 'under',
        '\b(?:pasok|kasya|sulod) sa (?:budget(?: na| nga)? )?((?:PHP\s*|\x{20B1}\s*)?[\d,]+(?:\.\d{1,2})?)\b' => 'budget up to $1',
        '\bbudget (?:nako|natin|namin|naton) (?:ay|nga|na)?\s*((?:PHP\s*|\x{20B1}\s*)?[\d,]+(?:\.\d{1,2})?)\b' => 'budget up to $1',
        '\b(?:ayaw|dili|indi|huwag|hindi)\s+(?:ug |sang |ng )?(intel|amd|nvidia)\b' => 'not $1',
        '\b(?:para sa|alang sa)\b' => 'for',
        '\b(?:ikumpara|itandi|ikump ara)\b' => 'compare',
        '\b(?:kaangay|pareho|kapareho)\b' => 'similar to',
        '\b(?:sang una|kaganina|kanina|kagina|ganiha)\b' => 'earlier',
        '\b(?:what would (?:that|this|it) set me back|what does (?:it|that) cost|how much is (?:it|that))\b' => 'price it',
        '\b(?:paano (?:ko )?(?:makikita|tingnan|i check)|diin (?:ko )?makita|asa (?:nako )?makita) (?:ang )?(?:akon |akong |aking |my )?order (?:history|status)\b' => 'how can i check my order status',
        '\b(?:paano|unsaon) (?:mag search|maghanap|mangita) (?:ng |ug |og |sang )?(?:products|produkto)\b' => 'how do i search for products',
        '\b(?:what do you sell|what can i buy|what do you have for sale)\b' => 'what products do you have',
        '\bhow much (?:is|for) (?:the )?(?:delivery|shipping)(?: fee| cost)?(?: (?:to|in) [\p{L}\p{N}-]+(?: [\p{L}\p{N}-]+){0,2})?\b' => 'delivery fee',
        '\b(?:pila|magkano|tagpila)(?: ang| po ang| po)? (?:delivery|shipping|pagpadala)(?: fee| cost)?(?: (?:sa|to) [\p{L}\p{N}-]+(?: [\p{L}\p{N}-]+){0,2})?\b' => 'delivery fee',
        '\b(?:can (?:you|we|i) (?:get|have) delivery|can you deliver|do you deliver|is delivery available)(?: (?:to|in|sa) ([\p{L}\p{N}-]+(?: [\p{L}\p{N}-]+){0,2}))?\b' => 'do you deliver to $1',
        '\b(?:what areas|where) do you deliver(?: to)?\b' => 'delivery options',
        '\bhow does delivery work\b' => 'delivery options',
        '\b(?:may|meron|mayroon) (?:ba )?(?:kayo(?:ng)? )?(?:delivery|pagdeliver|pag-deliver)(?: (?:sa|to) ([\p{L}\p{N}-]+(?: [\p{L}\p{N}-]+){0,2}))?\b' => 'do you deliver to $1',
        '\b(?:may|meron|mayroon) delivery (?:ba )?(?:kayo(?:ng)? )?(?:sa|to) ([\p{L}\p{N}-]+(?: [\p{L}\p{N}-]+){0,2})\b' => 'do you deliver to $1',
        '\bmay (.+?) ba kayo\b' => 'available $1',
        '\bmay (.+?) ba\b' => 'available $1',
        '\bmay (.+?) kayo\b' => 'available $1',
        '\b(?:pwede|puwede|mahimo)(?: po)? (?:ba|bang) (?:magpa-?deliver|i-?deliver|delivery)(?: kayo)? (?:sa|to) ([\p{L}\p{N}-]+(?: [\p{L}\p{N}-]+){0,2})\b' => 'do you deliver to $1',
        '\b(?:pwede|puwede|mahimo)(?: po)? ba (?:i-?lalamove|magpa-?lalamove)(?: ngayon| karon| subong)?\b' => 'do you deliver via lalamove today',
        '\b(?:naa|naay|aduna) (?:ba )?(?:moy|mo )?delivery(?: (?:sa|to) ([\p{L}\p{N}-]+(?: [\p{L}\p{N}-]+){0,2}))?\b' => 'do you deliver to $1',
        '\b(?:may ara|ara) (?:ba )?(?:kamo )?delivery(?: (?:sa|sang|to) ([\p{L}\p{N}-]+(?: [\p{L}\p{N}-]+){0,2}))?\b' => 'do you deliver to $1',
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
        '\b(?:(?:do you|does your (?:shop|store)) (?:carry|sell|stock)|have you got|(?:have you )?got any)\b' => 'available',
        '\b(?:may stock(?: (?:pa|ba))*(?: (?:kayo|kayong|kamo))?(?: (?:ng|sang))?|naay stock(?: sa)?|may ara(?: (?:pa|gid|bala))*(?: kamo)?(?: sang| nga)?|ara kamo(?: sang)?|(?:naa|aduna)(?: pa)?(?: ba)? (?:moy|mo)|(?:meron|mayroon)(?: (?:pa|rin|din))?(?: ba)? (?:kayong|kayo))\b' => 'available',
        '\b(?:merong|mayroong|naay)\b' => 'available',
        '\bba kayo\b' => 'available',
        '\b(?:may ara pa|ara pa|naa pa)\b' => 'available',
        '\b(?:ano (?:ang )?(?:mga )?(?:baligya|produkto) (?:ninyo|niyo)|unsa inyong (?:baligya|mga produkto)|anong (?:mga )?products (?:meron|mayroon) kayo)\b' => 'what products do you have',
        '\b(?:tagpila|magkano|pila)(?: (?:ang|po|sang|ka))?\b' => 'price',
        '\b(?:balik bayad|ibalik ang bayad|uli sa bayad)\b' => 'refund',
        '\b(?:bayad|pagbayad|pagbabayad|magbayad|mubayad|mobayad)\b' => 'payment',
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
        $message = preg_replace('/\b(mo|naa|aduna|wala|dili|unsa)[\x{0027}\x{2018}\x{2019}\x{02BC}]y\b/iu', '$1y', $message) ?? $message;
        $message = preg_replace_callback('/\b(ako|ikaw|siya|kami|kayo|sila|ito|iyan|iyon)[\x{0027}\x{2018}\x{2019}\x{02BC}]y\b/iu', fn (array $match): string => $match[1].' ay', $message) ?? $message;
        $message = preg_replace('/\b(ano|sino|saan)[\x{0027}\x{2018}\x{2019}\x{02BC}]ng\b/iu', '$1ng', $message) ?? $message;
        $message = preg_replace('/\bsa[\x{0027}\x{2018}\x{2019}\x{02BC}]ng\b/iu', 'sang', $message) ?? $message;
        $message = preg_replace_callback('/\b(don|doesn|didn|isn|aren|wasn|weren|couldn|wouldn|shouldn)[\x{0027}\x{2018}\x{2019}\x{02BC}]t\b/iu', fn (array $match): string => match (mb_strtolower($match[1])) {
            'don' => 'do not', 'doesn' => 'does not', 'didn' => 'did not', 'isn' => 'is not',
            'aren' => 'are not', 'wasn' => 'was not', 'weren' => 'were not',
            'couldn' => 'could not', 'wouldn' => 'would not', default => 'should not',
        }, $message) ?? $message;
        $message = $this->withoutClarificationPrefix($this->withoutLanguageRequest($message));
        $message = preg_replace('/\b(?:pls|plz|please|kindly|hello|hi|po)\b[,:]?/iu', ' ', $message) ?? $message;
        $message = Str::squish($message);
        $message = preg_replace_callback('/\b(?:prcie|prce|stok|availble|avalable|laptp|keybord|moniter|paymnt|delivry|oder)\b/iu', fn (array $match): string => [
            'prcie' => 'price', 'prce' => 'price', 'stok' => 'stock', 'availble' => 'available', 'avalable' => 'available',
            'laptp' => 'laptop', 'keybord' => 'keyboard', 'moniter' => 'monitor', 'paymnt' => 'payment', 'delivry' => 'delivery', 'oder' => 'order',
        ][mb_strtolower($match[0])], $message) ?? $message;
        $message = preg_replace_callback('/\b((?:under|below|budget(?: is| of)?|PHP)\s+(?:PHP\s*)?)(\d+(?:\.\d+)?)\s*k\b/iu', fn (array $match): string => $match[1].((float) $match[2] * 1000), $message) ?? $message;
        $message = preg_replace_callback('/\b[a-z]{5,16}\b/iu', function (array $match): string {
            $word = mb_strtolower($match[0]);
            $terms = ['monitor', 'keyboard', 'available', 'availability', 'delivery', 'payment', 'processor', 'motherboard', 'headset', 'laptop'];
            if (in_array($word, $terms, true)) {
                return $match[0];
            }
            $candidates = array_values(array_filter($terms, fn (string $term): bool => $word[0] === $term[0] && levenshtein($word, $term) === 1));

            return count($candidates) === 1 ? $candidates[0] : $match[0];
        }, $message) ?? $message;
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
        $message = $this->withoutNegatedLanguageRequest($message);
        if (! preg_match_all('/\b(?:answer|reply|respond|speak|use|in|sabat|tubag|sagot|sumagot)(?:\s+(?:in|sa|lang|using))?\s+(english|hiligaynon|ilonggo|bisaya|cebuano|tagalog|filipino)\b/iu', $message, $matches)) {
            return null;
        }

        return match (Str::lower(end($matches[1]))) {
            'hiligaynon', 'ilonggo' => 'hil',
            'bisaya', 'cebuano' => 'ceb',
            'tagalog', 'filipino' => 'tl',
            default => 'en',
        };
    }

    public function withoutLanguageRequest(string $message): string
    {
        $cleaned = preg_replace('/\b(?:please\s+)?(?:answer|reply|respond|speak|use|in|sabat|tubag|sagot|sumagot)(?:\s+(?:in|sa|lang|using))?\s+(?:english|hiligaynon|ilonggo|bisaya|cebuano|tagalog|filipino)\b[.!]?/iu', '', $this->withoutNegatedLanguageRequest($message)) ?? $message;
        if ($cleaned !== $message && preg_match('/^(?:[\s,;:.!?]|\b(?:and|but|instead|then)\b)*$/iu', $cleaned)) {
            return '';
        }

        return $cleaned === $message ? trim($message) : trim($cleaned, " \t\n\r\0\x0B,;:.!?");
    }

    private function withoutNegatedLanguageRequest(string $message): string
    {
        return preg_replace('/\b(?:please\s+)?(?:(?:do not|don.t|hindi|dili|indi)\s+(?:(?:answer|reply|respond|speak|use)\s+)?|not\s+)(?:in\s+)?(?:english|hiligaynon|ilonggo|bisaya|cebuano|tagalog|filipino)\b/iu', '', $message) ?? $message;
    }

    public function detect(string $message): ?string
    {
        $patterns = [
            'hil' => ['may ara', 'ara kamo', 'diin', 'kag', 'sang', 'akon', 'sini', 'subong', 'indi', 'kagina', 'manubo'],
            'ceb' => ['naa', 'naay', 'aduna', 'moy', 'asa', 'unsa', 'unsaon', 'akong', 'nako', 'inyong', 'inyong', 'karon', 'dili', 'ganiha', 'rekomendahi'],
            'tl' => ['magkano', 'paano', 'nasaan', 'meron', 'merong', 'mayroon', 'mayroong', 'ba kayo', 'aking', 'kayong', 'niyan', 'po', 'hindi', 'irekomenda', 'mura'],
            'en' => ['how', 'where', 'what', 'which', 'do you', 'have you', 'got any', 'can i', 'recommend', 'compare', 'show me', 'help me'],
        ];
        $scores = [];
        foreach ($patterns as $language => $phrases) {
            foreach ($phrases as $phrase) {
                if (preg_match('/\b'.preg_quote($phrase, '/').'\b/iu', $message) === 1) {
                    $weight = in_array($phrase, ['may ara', 'ara kamo', 'diin', 'mayroong', 'merong', 'magkano', 'unsaon', 'rekomendahi', 'do you', 'have you', 'got any', 'can i'], true) ? 2 : 1;
                    $scores[$language] = ($scores[$language] ?? 0) + $weight;
                }
            }
        }
        if ($scores === []) {
            return null;
        }
        arsort($scores);
        $languages = array_keys($scores);
        $top = reset($scores);
        $next = array_values($scores)[1] ?? 0;

        return $top > $next ? $languages[0] : null;
    }

    public function select(string $message, ?string $previous = null): string
    {
        return $this->explicit($message) ?? $this->detect($message) ?? (in_array($previous, self::LANGUAGES, true) ? $previous : 'en');
    }

    /** @return array{attributes: list<string>, maximum_php: float|null, excluded_brands: list<string>, capacity: string|null} */
    public function interpret(string $message): array
    {
        $query = $this->canonical($message);
        $attributes = [];
        foreach (['price' => 'price|prices|pricing|cost|how much', 'stock' => 'stock|stocks|available|availability', 'details' => 'specs|specifications|details|description'] as $attribute => $words) {
            if (preg_match('/\b(?:'.$words.')\b/iu', $query) === 1) {
                $attributes[] = $attribute;
            }
        }
        preg_match('/\b(?:under|below|less than|budget(?: is| of)?)\s*(?:PHP\s*|\x{20B1}\s*)?([\d,]+(?:\.\d{1,2})?)(?![\p{L}\p{N}])/iu', $query, $budget);
        preg_match_all('/\b(?:not|without|exclud(?:e|ing)|except)\s+([\p{L}\p{N}-]+)\b/iu', $query, $excluded);
        $excludedBrands = array_values(array_unique(array_filter(array_map('strtolower', $excluded[1]), fn (string $brand): bool => ! in_array($brand, ['available', 'stock', 'budget', 'under', 'below', 'price', 'products', 'product'], true))));
        preg_match('/\b\d+(?:\.\d+)?\s*(?:TB|GB)\b/iu', $query, $capacity);

        return ['attributes' => $attributes, 'maximum_php' => isset($budget[1]) ? (float) str_replace(',', '', $budget[1]) : null,
            'excluded_brands' => $excludedBrands, 'capacity' => $capacity[0] ?? null];
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
            && preg_match('/\b(?:battlefront|your shop|inyong shop|inyo nga shop|purchase|bought|parcel|checkout|tindahan|products?|catalog|gpu|cpu|motherboard|ram|ssd|payment|delivery|pickup|refund|order|monitor|keyboard|mouse|headset|processor|storage|laptop|desktop|warranty|repair|account|compare|price|stock|budget|portable|gaming|office|school|editing|streaming|workstation)\b/iu', $this->canonical($message)) === 1;
    }
}
