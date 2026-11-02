import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { nextTick, reactive, ref, watch } from 'vue';
import { compileScript, parse } from '@vue/compiler-sfc';

const source = readFileSync(
    new URL('./ChatbotKnowledgeForm.vue', import.meta.url),
    'utf8',
);
const { descriptor } = parse(source);
compileScript(descriptor, { id: 'faq-question-preview-test' });
const scriptSource = descriptor.scriptSetup.content.replace(
    /^import .*;$/gm,
    '',
);

function setup(post) {
    const props = reactive({ knowledge: null });
    const preview = {
        processing: false,
        errors: {},
        clearErrors() {
            this.errors = {};
        },
        post,
    };
    const state = runInNewContext(
        `(() => {
        ${scriptSource}
        return { question, previewResult, previewError, checkQuestion };
    })()`,
        {
            reactive,
            ref,
            watch,
            defineProps: () => props,
            useHttp: () => preview,
            ChatbotKnowledgePreviewController: { url: () => '/preview' },
        },
    );
    state.question.value = 'How do I search for products?';
    return { ...state, props, preview };
}

test('checking a draft uses its current question and displays the returned eligibility', async () => {
    const result = { eligible_for_faq: true, matches: [] };
    const state = setup(async (url) => {
        assert.equal(url, '/preview');
        return result;
    });
    await state.checkQuestion();
    assert.equal(state.preview.message, state.question.value);
    assert.deepEqual(state.previewResult.value, result);
});

test('a response for an older draft does not replace current question feedback', async () => {
    let resolve;
    const state = setup(
        () =>
            new Promise((done) => {
                resolve = done;
            }),
    );
    const request = state.checkQuestion();
    state.question.value = 'What is the laptop price?';
    resolve({ eligible_for_faq: true, matches: [] });
    await request;
    assert.equal(state.previewResult.value, null);
});

test('a failed check shows an actionable message without clearing the draft', async () => {
    const state = setup(async () => {
        throw new Error('Network failure');
    });
    await state.checkQuestion();
    assert.equal(
        state.previewError.value,
        'Unable to check this question. Please try again.',
    );
    assert.equal(state.question.value, 'How do I search for products?');
});

test('an old validation failure is cleared after the draft changes', async () => {
    let reject;
    const state = setup(
        () =>
            new Promise((_, fail) => {
                reject = fail;
            }),
    );
    const request = state.checkQuestion();
    state.question.value = 'How can I view past orders?';
    state.preview.errors.message = 'Old question error';
    reject(new Error('Validation failed'));
    await request;
    assert.deepEqual(state.preview.errors, {});
    assert.equal(state.previewError.value, '');
});

test('switching legacy records hydrates the question while unchanged props preserve an edited draft', async () => {
    const state = setup(async () => ({}));
    state.props.knowledge = {
        id: 1,
        question_pattern: 'How do I search for products?',
    };
    await nextTick();
    state.question.value = 'Edited draft question';
    await nextTick();
    state.props.knowledge = {
        id: 1,
        question_pattern: 'How do I search for products?',
    };
    await nextTick();
    assert.equal(state.question.value, 'Edited draft question');
    state.props.knowledge = {
        id: 2,
        question_pattern: 'How can I check my order status?',
    };
    await nextTick();
    assert.equal(state.question.value, 'How can I check my order status?');
});
