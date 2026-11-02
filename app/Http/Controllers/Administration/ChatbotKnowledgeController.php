<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Actions\Chatbot\MatchChatbotKnowledge;
use App\Enums\ChatbotCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SaveChatbotKnowledgeRequest;
use App\Models\ChatbotKnowledge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatbotKnowledgeController extends Controller
{
    public function __construct(
        private ClassifyChatbotInquiry $boundary,
        private MatchChatbotKnowledge $matcher,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $knowledge = ChatbotKnowledge::query()
            ->select([
                'id',
                'category',
                'question_pattern',
                'response_template',
                'priority',
                'is_active',
                'translations',
            ])
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('question_pattern', 'like', '%'.$search.'%')
                ->orWhere('response_template', 'like', '%'.$search.'%')))
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (ChatbotKnowledge $item): array => $this->knowledgeData($item));

        return Inertia::render('Administration/ChatbotKnowledge/Index', [
            'knowledge' => $knowledge,
            'filters' => ['q' => $filters['q'] ?? null],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Administration/ChatbotKnowledge/Create');
    }

    public function store(SaveChatbotKnowledgeRequest $request): RedirectResponse
    {
        ChatbotKnowledge::query()->create([
            ...$request->faqData(),
            'category' => ChatbotCategory::Faq,
            'priority' => 0,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Chatbot FAQ created.'),
        ]);

        return to_route('administration.chatbot-knowledge.index');
    }

    public function show(ChatbotKnowledge $chatbotKnowledge): Response
    {
        return Inertia::render('Administration/ChatbotKnowledge/Show', [
            'knowledge' => $this->knowledgeData($chatbotKnowledge),
        ]);
    }

    public function edit(ChatbotKnowledge $chatbotKnowledge): Response
    {
        return Inertia::render('Administration/ChatbotKnowledge/Edit', [
            'knowledge' => $this->knowledgeData($chatbotKnowledge),
        ]);
    }

    public function update(
        SaveChatbotKnowledgeRequest $request,
        ChatbotKnowledge $chatbotKnowledge,
    ): RedirectResponse {
        $chatbotKnowledge->update([
            ...$request->faqData(),
            'category' => ChatbotCategory::Faq,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Chatbot FAQ updated.'),
        ]);

        return to_route('administration.chatbot-knowledge.show', $chatbotKnowledge);
    }

    /**
     * @return array{
     *     id: int,
     *     category: string,
     *     category_label: string,
     *     question_pattern: string,
     *     response_template: string,
     *     priority: int,
     *     eligible_for_faq: bool,
     *     eligibility_reason: string,
     *     is_active: bool
     * }
     */
    private function knowledgeData(ChatbotKnowledge $chatbotKnowledge): array
    {
        $classification = $this->boundary->execute($chatbotKnowledge->question_pattern);
        $eligible = $chatbotKnowledge->category === ChatbotCategory::Faq && $classification['eligible_for_faq']
            && $this->matcher->hasMeaningfulTerms($chatbotKnowledge->question_pattern) && trim($chatbotKnowledge->response_template) !== '';

        $reason = match (true) {
            $chatbotKnowledge->category !== ChatbotCategory::Faq => 'Legacy record. Edit into an eligible FAQ to use it in chatbot answers.',
            ! $classification['eligible_for_faq'] => $classification['reason'],
            ! $this->matcher->hasMeaningfulTerms($chatbotKnowledge->question_pattern) => 'Enter a specific support question with meaningful wording.',
            trim($chatbotKnowledge->response_template) === '' => 'Add an approved answer before reactivating this FAQ.',
            default => 'Managed support FAQ.',
        };

        return [
            'eligible_for_faq' => $eligible,
            'eligibility_reason' => $reason,
            'id' => $chatbotKnowledge->id,
            'category' => $chatbotKnowledge->category->value,
            'category_label' => $chatbotKnowledge->category->label(),
            'question_pattern' => $chatbotKnowledge->question_pattern,
            'response_template' => $chatbotKnowledge->response_template,
            'priority' => $chatbotKnowledge->priority,
            'is_active' => $chatbotKnowledge->is_active,
            'translations' => $chatbotKnowledge->translations ?? [],
        ];
    }
}
