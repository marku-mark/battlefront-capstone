<?php

namespace App\Http\Requests\Administration;

use App\Actions\Chatbot\ValidateChatbotFaq;
use App\Models\ChatbotKnowledge;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveChatbotKnowledgeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('access-administration') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'question_pattern' => ['bail', 'required', 'string', 'max:255'],
            'response_template' => ['bail', 'required', 'string'],
            'translations' => ['sometimes', 'array:ceb,tl,hil'],
            'translations.*' => ['array:question_pattern,response_template,reviewed,source_question,source_answer'],
            'translations.*.question_pattern' => ['nullable', 'string', 'max:255'],
            'translations.*.response_template' => ['nullable', 'string', 'max:8000'],
            'translations.*.reviewed' => ['required', 'boolean'],
            'translations.*.source_question' => ['nullable', 'string', 'max:255'],
            'translations.*.source_answer' => ['nullable', 'string'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['ceb', 'tl', 'hil'] as $language) {
                if (! $this->boolean('translations.'.$language.'.reviewed')) {
                    continue;
                }
                $translation = $this->input('translations.'.$language, []);
                if (! is_array($translation) || empty($translation['question_pattern']) || empty($translation['response_template'])
                    || ($translation['source_question'] ?? null) !== $this->input('question_pattern')
                    || ($translation['source_answer'] ?? null) !== $this->input('response_template')) {
                    $validator->errors()->add('translations.'.$language.'.reviewed', 'Review both translated fields against the current English question and answer.');
                }
            }
            if ($validator->errors()->has('question_pattern')) {
                return;
            }
            /** @var ChatbotKnowledge|null $record */
            $record = $this->route('chatbotKnowledge');
            $recordId = $record instanceof ChatbotKnowledge ? $record->id : null;
            $error = app(ValidateChatbotFaq::class)->execute($this->string('question_pattern')->toString(), $recordId);
            if ($error !== null) {
                $validator->errors()->add('question_pattern', $error);
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'question_pattern.required' => 'Enter a question pattern.',
            'question_pattern.max' => 'The question pattern must not exceed 255 characters.',
            'response_template.required' => 'Enter a response template.',
        ];
    }

    /** @return array<string, mixed> */
    public function faqData(): array
    {
        $data = $this->safe()->only(['question_pattern', 'response_template', 'translations']);
        if (isset($data['translations'])) {
            foreach ($data['translations'] as &$translation) {
                $translation['reviewed'] = filter_var($translation['reviewed'], FILTER_VALIDATE_BOOLEAN);
            }
            unset($translation);
        }

        return $data;
    }
}
