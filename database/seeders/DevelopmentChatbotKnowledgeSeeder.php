<?php

namespace Database\Seeders;

use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

class DevelopmentChatbotKnowledgeSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (! App::environment(['local', 'testing'])) {
            return;
        }

        /** @var array<string, string> $faqs */
        $faqs = [
            'How do I use PC Builder?' => 'Open PC Builder from the store navigation. Select components, review the build checks and current total, then save your build or continue through the available cart actions. The chatbot does not select parts or guarantee compatibility.',
            'How do I select components in PC Builder?' => 'Open PC Builder and choose a component category, then select an available component. Review its details, quantities and the build checks before continuing. Missing or draft specifications do not establish compatibility.',
            'How do saved PC builds work?' => 'Sign in with a customer account to save and load builds in PC Builder. Use the saved build sharing action to generate a public link; anyone with that link can view the shared build. Saving does not reserve stock or lock prices. Current availability and prices are checked again when purchasing.',
            'How do PC Builder templates work?' => 'Choose an available predefined template in PC Builder to load its components, then review or change your selections. Templates are shown only when their reviewed specifications, build checks and stock satisfy the current rules. A template may be unavailable; its saved budget is not a guaranteed checkout price.',
            'Where can I view PC Builder compatibility results?' => 'Review the checks in PC Builder after selecting components. Read each warning and missing-evidence result. Unknown or draft evidence is not verified compatibility, and incomplete builds are not complete compatible systems. These checks are not an installation or stability guarantee; the chatbot cannot judge your exact parts.',
            'How can I check my order status?' => 'Sign in and open your order history to view the latest status for your order.',
            'Do you offer real-time delivery tracking?' => 'Real-time delivery tracking is not available. Sign in and check your order history for the latest order status.',
            'How do I search for products?' => 'Open the product catalog and use search. You can narrow results by category, brand, or tag.',
            'How can I view a product\'s details?' => 'Open a product from the catalog to view its description, listed price, and availability.',
        ];

        foreach ($faqs as $question => $answer) {
            ChatbotKnowledge::query()->firstOrCreate(
                ['question_pattern' => $question],
                ['category' => ChatbotCategory::Faq, 'response_template' => $answer, 'priority' => 0, 'is_active' => true],
            );
        }
    }
}
