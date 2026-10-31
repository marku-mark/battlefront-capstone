import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { setTimeout as delay } from 'node:timers/promises';

// Opt in with built assets, a running local app and an existing Chromium/Edge executable.
const baseUrl = process.env.BATTLEFRONT_UI_URL;
const executable = process.env.BATTLEFRONT_UI_BROWSER;
const widths = [320, 375, 390, 428, 768, 1024, 1440];

test(
    'focused chatbot replies and FAQ search remain readable',
    {
        skip: !baseUrl || !executable,
        timeout: 60000,
    },
    async (t) => {
        const browser = await openBrowser(t);
        const paginated = { data: [], total: 0, current_page: 1, last_page: 1 };
        async function waitFor(expression) {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (await browser.evaluate(expression)) return;
                await delay(25);
            }
            assert.fail(
                'Expected chatbot UI state: ' +
                    expression +
                    '; ' +
                    JSON.stringify(
                        await browser.evaluate(
                            `({text: document.querySelector('#battlefront-chat-panel')?.textContent, input: document.getElementById('floating-chatbot-message')?.value})`,
                        ),
                    ) +
                    '; requests: ' +
                    JSON.stringify(browser.jsonRequests) +
                    '; errors: ' +
                    browser.exceptions.join('; '),
            );
        }
        for (const theme of ['light', 'dark']) {
            await browser.show(
                'Administration/ChatbotKnowledge/Index',
                {
                    knowledge: paginated,
                    filters: { q: 'support' },
                },
                'admin',
                theme,
            );
            for (const width of [320, 768, 1440]) {
                await browser.resize(width);
                const layout = await browser.evaluate(`(() => {
                const input = document.getElementById('knowledge-search');
                const field = input.closest('.grid');
                const section = input.closest('section');
                const rect = section.getBoundingClientRect();
                const boxes = [field.querySelector('label'), input, field.querySelector('p')].map(el => el.getBoundingClientRect());
                return { left: boxes.map(box => box.left - rect.left), right: Math.max(...boxes.map(box => box.right)) <= rect.right - 23, bottom: rect.bottom - boxes.at(-1).bottom };
            })()`);
                assert.ok(
                    layout.left.every((value) => value >= 24 && value <= 26),
                );
                assert.equal(layout.right, true);
                assert.ok(layout.bottom >= 24);
                await assertFits(browser, 'FAQ search ' + width);
            }
            await browser.show('Branches/Index', { branches: [] }, true, theme);
            await browser.resize(375, 650);
            await browser.evaluate(
                `document.querySelector('[aria-controls="battlefront-chat-panel"]').click()`,
            );
            const answer = Array.from(
                { length: 24 },
                (_, i) =>
                    `Paragraph ${i + 1}: Current approved information with complete details.`,
            ).join('\n\n');
            browser.registerJson('POST', '/chatbot', {
                message: answer,
                source: 'fallback',
                context_token: 'fixture-token',
            });
            const submit = `(async () => { const input = document.getElementById('floating-chatbot-message'); input.value = 'monitor'; input.dispatchEvent(new Event('input', { bubbles: true })); await new Promise(resolve => setTimeout(resolve, 0)); document.querySelector('#battlefront-chat-panel form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); })()`;
            await browser.evaluate(submit);
            await waitFor(
                `document.querySelectorAll('[data-message-id]').length === 2`,
            );
            await delay(50);
            const reply = await browser.evaluate(`(() => {
            const item = document.querySelector('[data-message-id="2"]');
            const panel = item.closest('[aria-busy]');
            return { paragraphs: item.querySelectorAll('article > div > p').length, text: item.textContent, offset: item.getBoundingClientRect().top - panel.getBoundingClientRect().top, overflow: panel.scrollHeight > panel.clientHeight };
        })()`);
            assert.equal(reply.paragraphs, 24);
            assert.ok(reply.text.includes('Paragraph 24:'));
            assert.ok(reply.offset >= 0 && reply.offset < 25);
            assert.equal(reply.overflow, true);
            browser.registerJson(
                'POST',
                '/chatbot',
                {
                    message: answer,
                    source: 'fallback',
                    context_token: 'second-token',
                },
                200,
                300,
            );
            await browser.evaluate(submit);
            await waitFor(
                `document.querySelector('[aria-busy="true"]') !== null`,
            );
            await browser.evaluate(
                `document.querySelector('#battlefront-chat-panel [aria-busy]').scrollTop = 0`,
            );
            await delay(50);
            await waitFor(
                `document.querySelectorAll('[data-message-id]').length === 4`,
            );
            assert.equal(
                await browser.evaluate(
                    `document.querySelector('#battlefront-chat-panel [aria-busy]').scrollTop`,
                ),
                0,
            );
            await browser.evaluate(
                `document.querySelector('[aria-label="Close chat"]').click()`,
            );
            await browser.evaluate(
                `document.querySelector('[aria-controls="battlefront-chat-panel"]').click()`,
            );
            assert.equal(
                await browser.evaluate(
                    `document.querySelector('#battlefront-chat-panel [aria-busy]').scrollTop`,
                ),
                0,
            );
            await browser.evaluate(
                `[...document.querySelectorAll('#battlefront-chat-panel button')].find(b => b.textContent.trim() === 'New chat').click()`,
            );
            assert.equal(
                await browser.evaluate(
                    `document.querySelectorAll('[data-message-id]').length`,
                ),
                0,
            );
            browser.registerJson(
                'POST',
                '/chatbot',
                { message: 'Too many questions.' },
                429,
            );
            await browser.evaluate(submit);
            await waitFor(
                `!!document.querySelector('#battlefront-chat-panel [role="alert"]')`,
            );
            assert.equal(
                JSON.parse(browser.jsonRequests.at(-1).postData).context_token,
                null,
            );
            assert.equal(
                await browser.evaluate(
                    `document.querySelectorAll('[data-message-id]').length`,
                ),
                0,
            );
        }
        assert.deepEqual(browser.exceptions, []);
    },
);

test(
    'team email OTP gates creation and preserves the account form',
    { skip: !baseUrl || !executable, timeout: 60000 },
    async (t) => {
        const browser = await openBrowser(t);
        const props = {
            users: {
                data: [],
                total: 0,
                current_page: 1,
                last_page: 1,
                links: [],
            },
            permissions: [],
            grants: [],
            availableRoles: ['staff', 'manager', 'owner', 'it'],
            canManagePermissions: false,
            roleVersions: {},
            filters: {},
            canViewOrders: false,
            canAssignOrders: false,
            teamEmailVerification: null,
        };
        const createButton = `document.querySelector('main form button[type="submit"]')`;
        const button = (label) =>
            `[...document.querySelectorAll('main button')].find(b => b.textContent.trim() === ${JSON.stringify(label)})`;
        const input = (id, value) =>
            `(() => { const field = document.getElementById(${JSON.stringify(id)}); field.value = ${JSON.stringify(value)}; field.dispatchEvent(new Event('input', { bubbles: true })); })()`;
        async function waitFor(expression, message) {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (await browser.evaluate(expression)) return;
                await delay(25);
            }
            assert.fail(message + '; ' + browser.exceptions.join('; '));
        }
        for (const theme of ['light', 'dark']) {
            await browser.show('Administration/Team', props, 'admin', theme);
            for (const width of [320, 1440]) {
                await browser.resize(width);
                await assertFits(
                    browser,
                    'Team verification ' + theme + ' ' + width,
                );
            }
            assert.equal(
                await browser.evaluate(`${createButton}.disabled`),
                true,
            );
        }
        const verification = {
            email: 'staff@example.test',
            status: 'verification_required',
            expires_at: new Date(Date.now() + 300000).toISOString(),
            verified_until: null,
            attempts_remaining: 5,
            delivery_status: 'sent',
            resend_available_at: new Date(Date.now() + 60000).toISOString(),
        };
        const sendPath = '/administration/team/email-verification';
        const verifyPath = sendPath + '/verify';
        browser.registerJson('POST', sendPath, {
            message: 'Check the recipient inbox.',
            data: verification,
        });
        await browser.evaluate(input('team-email', verification.email));
        await browser.evaluate(
            input('team-password', 'private-form-test-password'),
        );
        await browser.evaluate(`${button('Verify email')}.click()`);
        await waitFor(
            `!!document.getElementById('team-email-code')`,
            'The code field appears inline',
        );
        assert.deepEqual(JSON.parse(browser.jsonRequests[0].postData), {
            email: verification.email,
        });
        assert.equal(await browser.evaluate(`${createButton}.disabled`), true);
        assert.match(
            await browser.evaluate(
                `${button('Resend in 60s')}?.textContent ?? document.querySelector('main').textContent`,
            ),
            /Resend in \d+s/,
        );
        const invalid = 'The code is invalid. Check your inbox.';
        browser.registerJson(
            'POST',
            verifyPath,
            {
                message: 'Verification failed.',
                errors: { code: [invalid] },
                data: { ...verification, attempts_remaining: 4 },
            },
            422,
        );
        await browser.evaluate(input('team-email-code', '001234'));
        await browser.evaluate(`${button('Confirm code')}.click()`);
        await waitFor(
            `document.getElementById('team-email-error').textContent.includes(${JSON.stringify(invalid)})`,
            'An invalid code shows inline feedback',
        );
        assert.equal(
            JSON.parse(browser.jsonRequests[1].postData).code,
            '001234',
        );
        assert.equal(await browser.evaluate(`${createButton}.disabled`), true);
        const verified = {
            ...verification,
            status: 'verified',
            verified_until: new Date(Date.now() + 600000).toISOString(),
        };
        browser.registerJson('POST', verifyPath, {
            message: 'Email verified.',
            data: verified,
        });
        await browser.evaluate(`${button('Confirm code')}.click()`);
        await waitFor(
            `!${createButton}.disabled`,
            'Successful verification enables creation',
        );
        assert.equal(
            await browser.evaluate(
                `document.getElementById('team-password').value === 'private-form-test-password'`,
            ),
            true,
        );

        browser.registerJson('DELETE', sendPath, { data: null });
        await browser.evaluate(input('team-email', 'changed@example.test'));
        await waitFor(
            `${createButton}.disabled && !document.getElementById('team-email-code')`,
            'Changing email clears eligibility',
        );
        await waitFor(
            `!!${button('Verify email')} && !${button('Verify email')}.disabled`,
            'Clearing verification completes',
        );
        assert.equal(browser.jsonRequests.at(-1).method, 'DELETE');
        assert.deepEqual(JSON.parse(browser.jsonRequests.at(-1).postData), {
            email: verification.email,
        });

        await browser.show(
            'Administration/Team',
            { ...props, teamEmailVerification: verified },
            'admin',
        );
        await waitFor(
            `!${createButton}.disabled`,
            'A page reload recovers the server proof',
        );
        assert.equal(
            await browser.evaluate(
                `document.getElementById('team-email').value`,
            ),
            verification.email,
        );
        await browser.resize(320);
        await assertFits(browser, 'Verified Team email at 320px');
        if (process.env.BATTLEFRONT_TEAM_PREVIEW) {
            const screenshot = await browser.command('Page.captureScreenshot', {
                format: 'png',
                captureBeyondViewport: false,
            });
            await writeFile(
                process.env.BATTLEFRONT_TEAM_PREVIEW,
                Buffer.from(screenshot.data, 'base64'),
            );
        }
        await browser.show(
            'Administration/Team',
            {
                ...props,
                teamEmailVerification: {
                    ...verified,
                    verified_until: new Date(Date.now() - 1000).toISOString(),
                },
            },
            'admin',
        );
        await waitFor(
            `document.querySelector('main').textContent.includes('Email verification expired')`,
            'Expired proof requires verification again',
        );
        assert.equal(await browser.evaluate(`${createButton}.disabled`), true);
        await browser.show(
            'Administration/Team',
            {
                ...props,
                teamEmailVerification: {
                    ...verification,
                    delivery_status: 'failed',
                    expires_at: null,
                },
            },
            'admin',
        );
        assert.ok(
            await browser.evaluate(
                `document.querySelector('main').textContent.includes('Email delivery failed')`,
            ),
        );
        assert.equal(await browser.evaluate(`${createButton}.disabled`), true);
        await browser.show('Administration/Team', props, 'admin');
        browser.registerJson(
            'POST',
            sendPath,
            { message: 'Please wait before trying again.', retry_after: 60 },
            429,
        );
        await browser.evaluate(input('team-email', verification.email));
        await browser.evaluate(`${button('Verify email')}.click()`);
        await waitFor(
            `document.querySelector('main').textContent.includes('Resend in') && document.querySelector('main').textContent.includes('Please wait')`,
            'Request limits show a cooldown and retry feedback',
        );
        assert.equal(await browser.evaluate(`${createButton}.disabled`), true);
        assert.deepEqual(browser.exceptions, []);
    },
);

test(
    'email OTP verification preserves leading zeros, cooldown, and recovery feedback',
    { skip: !baseUrl || !executable, timeout: 60000 },
    async (t) => {
        const browser = await openBrowser(t);
        const verification = {
            status: 'verification_required',
            masked_email: 'c***@example.test',
            verification_expires_at: new Date(
                Date.now() + 1800000,
            ).toISOString(),
            expires_at: new Date(Date.now() + 300000).toISOString(),
            resend_available_at: new Date(Date.now() - 1000).toISOString(),
            attempts_remaining: 5,
            delivery_status: 'sent',
            retry_after: 0,
        };
        const props = { verification, status: '' };
        const codeInput = `document.querySelector('#code')`;
        const verifyButton = `document.querySelectorAll('form')[0].querySelector('button[type=submit]')`;
        const resendButton = `document.querySelectorAll('form')[1].querySelector('button[type=submit]')`;
        async function waitFor(expression, message) {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (await browser.evaluate(expression)) return;
                await delay(25);
            }
            assert.fail(message + '; ' + browser.exceptions.join('; '));
        }

        for (const theme of ['light', 'dark']) {
            await browser.show('auth/VerifyEmail', props, false, theme);
            for (const width of [320, 1440]) {
                await browser.resize(width);
                assert.deepEqual(
                    await browser.evaluate(`(() => {
                        const controls = [...document.querySelectorAll('#app input, #app button, #app label, #app p, #app h1, #app a')];
                        return controls.filter((element) => {
                            const rect = element.getBoundingClientRect();
                            return rect.width && rect.height && (rect.left < -1 || rect.right > innerWidth + 1);
                        }).map((element) => element.id || element.textContent.trim());
                    })()`),
                    [],
                    `Verification content fits ${theme} at ${width}px`,
                );
            }
            assert.equal(
                await browser.evaluate(
                    `document.documentElement.classList.contains('dark')`,
                ),
                theme === 'dark',
            );
        }
        assert.deepEqual(
            await browser.evaluate(
                `({ type: ${codeInput}.type, inputmode: ${codeInput}.inputMode, autocomplete: ${codeInput}.autocomplete, maxlength: ${codeInput}.maxLength })`,
            ),
            {
                type: 'text',
                inputmode: 'numeric',
                autocomplete: 'one-time-code',
                maxlength: 6,
            },
        );
        assert.equal(
            await browser.evaluate(
                `document.querySelector('a[href="/login"]').textContent.trim()`,
            ),
            'Return to login',
        );

        const invalid = 'The code is invalid. Check your email and try again.';
        browser.registerForm('/email/verify', {
            ...props,
            verification: { ...verification, attempts_remaining: 4 },
            errors: { code: invalid },
        });
        await browser.evaluate(`(() => {
            const input = ${codeInput};
            input.value = '001234';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.form.requestSubmit();
        })()`);
        await waitFor(
            `document.querySelector('#code-error').textContent.includes(${JSON.stringify(invalid)}) && ${codeInput}.getAttribute('aria-invalid') === 'true'`,
            'Invalid codes show inline feedback',
        );
        assert.equal(
            JSON.parse(browser.formRequests[0].postData).code,
            '001234',
        );
        assert.equal(await browser.evaluate(`${codeInput}.value`), '001234');
        assert.ok(
            await browser.evaluate(
                `document.querySelector('#code-guidance').textContent.includes('4 attempts remaining')`,
            ),
        );

        await browser.show(
            'auth/VerifyEmail',
            {
                ...props,
                verification: {
                    ...verification,
                    resend_available_at: new Date(
                        Date.now() + 1000,
                    ).toISOString(),
                },
            },
            false,
            'light',
        );
        assert.equal(await browser.evaluate(`${resendButton}.disabled`), true);
        assert.match(
            await browser.evaluate(`${resendButton}.textContent.trim()`),
            /^Resend code in \d+s$/,
        );
        await waitFor(
            `${resendButton}.disabled === false`,
            'The resend button enables after the visible cooldown',
        );
        browser.registerForm('/email/resend', {
            ...props,
            status: 'A new verification code has been sent. Check your inbox.',
            verification: {
                ...verification,
                resend_available_at: new Date(Date.now() + 60000).toISOString(),
            },
        });
        await browser.evaluate(
            `document.querySelectorAll('form')[1].requestSubmit()`,
        );
        await waitFor(
            `!!document.querySelector('[role=status]') && ${resendButton}.disabled`,
            'Resending confirms delivery and restarts cooldown',
        );
        assert.equal(
            new URL(browser.formRequests[1].url).pathname,
            '/email/resend',
        );

        for (const [changes, guidance, resendDisabled = false] of [
            [
                { expires_at: new Date(Date.now() - 1000).toISOString() },
                'This code has expired.',
            ],
            [{ attempts_remaining: 0 }, 'You have used all five attempts.'],
            [
                { delivery_status: 'failed', expires_at: null },
                'Email delivery was unsuccessful.',
            ],
            [
                {
                    delivery_status: 'rate_limited',
                    expires_at: null,
                    resend_available_at: new Date(
                        Date.now() + 60000,
                    ).toISOString(),
                },
                'Code requests are temporarily limited.',
                true,
            ],
        ]) {
            await browser.show(
                'auth/VerifyEmail',
                {
                    ...props,
                    verification: { ...verification, ...changes },
                },
                false,
                'light',
            );
            assert.ok(
                await browser.evaluate(
                    `document.querySelector('[role=alert], [role=status]').textContent.includes(${JSON.stringify(guidance)})`,
                ),
            );
            assert.equal(
                await browser.evaluate(`${verifyButton}.disabled`),
                true,
            );
            assert.equal(
                await browser.evaluate(`${resendButton}.disabled`),
                resendDisabled,
            );
        }
        assert.deepEqual(browser.exceptions, []);
    },
);

test(
    'branch management forms and directories fit both themes and keep publication explicit',
    {
        skip: !baseUrl || !executable,
    },
    async (t) => {
        const browser = await openBrowser(t);
        const branch = {
            id: 7,
            name: 'Battlefront ' + 'LongStoreName'.repeat(15),
            city: 'New City',
            address: '12 Confirmed Street, Poblacion, Negros Occidental',
            email: 'verified-store@example.test',
            contact_number: '0912 345 6789',
            operating_hours: 'Mon–Sat, 9 AM–5 PM',
            latitude: null,
            longitude: null,
            is_published: false,
            is_operational: false,
            operational_notice: null,
        };
        for (const theme of ['light', 'dark']) {
            for (const [component, props, audience] of [
                [
                    'Administration/Branches/Index',
                    {
                        branches: {
                            data: [branch],
                            total: 1,
                            current_page: 1,
                            last_page: 1,
                        },
                    },
                    'admin',
                ],
                ['Administration/Branches/Create', {}, 'admin'],
                ['Administration/Branches/Edit', { branch }, 'admin'],
                ['Administration/Branches/Show', { branch }, 'admin'],
                [
                    'Branches/Index',
                    {
                        branches: [
                            {
                                ...branch,
                                is_published: true,
                                city: 'Location' + 'x'.repeat(90),
                            },
                        ],
                    },
                    false,
                ],
            ]) {
                await browser.show(component, props, audience, theme);
                for (const width of [320, 375, 768, 1440]) {
                    await browser.resize(width);
                    await assertFits(
                        browser,
                        component + ' ' + theme + ' ' + width,
                    );
                }
            }
        }

        await browser.show('Administration/Branches/Create', {}, 'admin');
        assert.equal(
            await browser.evaluate(
                `!!document.querySelector('[data-sidebar="menu"] a[href="/administration/branches"]')`,
            ),
            true,
        );
        assert.equal(
            await browser.evaluate(
                `new FormData(document.querySelector('main form')).get('is_published')`,
            ),
            '0',
        );
        assert.equal(
            await browser.evaluate(
                `document.querySelector('#address').required`,
            ),
            false,
        );
        await browser.evaluate(
            `document.querySelector('#is-published').click()`,
        );
        assert.equal(
            await browser.evaluate(
                `new FormData(document.querySelector('main form')).get('is_published')`,
            ),
            '1',
        );
        assert.equal(
            await browser.evaluate(
                `document.querySelector('#address').required && document.querySelector('#operating-hours').required`,
            ),
            true,
        );

        await browser.show(
            'Administration/Branches/Edit',
            {
                branch: {
                    ...branch,
                    city: 'Sagay City',
                    is_published: true,
                    is_operational: true,
                },
            },
            'admin',
        );
        assert.equal(
            await browser.evaluate(
                `document.querySelector('#city').readOnly && document.querySelector('#is-published').disabled`,
            ),
            true,
        );
        assert.equal(
            await browser.evaluate(
                `new FormData(document.querySelector('main form')).get('is_published')`,
            ),
            '1',
        );
        assert.deepEqual(browser.exceptions, []);
    },
);

const longName =
    'Battlefront Ultra Performance Graphics Card With Extended Cooling';
const product = {
    id: 7,
    name: longName,
    brand: 'Battlefront',
    category: { id: 3, name: 'Graphics Cards & Accessories' },
    shipping_handling: {
        label: 'Standard handling',
        surcharge: '0.00',
        preparation_days: 1,
    },
    price: '123456.78',
    discount_price: '99999.99',
    description: 'Detailed specifications for computer hardware.',
    image_url:
        'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="200" height="100"%3E%3Crect width="200" height="100" fill="gray"/%3E%3C/svg%3E',
    inventory: {
        id: 17,
        quantity: 8,
        reorder_level: 10,
        last_updated: '2026-10-12T08:00:00Z',
        status: 'low_stock',
    },
    tags: [],
    is_featured: true,
};
const cart = {
    items: [
        {
            id: 11,
            quantity: 2,
            product: { ...product, category: product.category.name },
            line_total: '199999.98',
            availability: { status: 'available', available_quantity: 8 },
        },
    ],
    item_count: 1,
    total_quantity: 2,
    total: '199999.98',
    has_conflicts: false,
};
const order = {
    id: 7,
    reference: 'BF-000007',
    created_at: '2026-10-12T08:00:00Z',
    can_cancel: true,
    status: { value: 'pending', label: 'Pending' },
    recipient: { name: 'Customer', contact_number: '09171234567' },
    customer: { name: 'Customer', email: 'customer@example.test' },
    fulfillment: { value: 'pickup', label: 'Pickup' },
    payment: {
        method: {
            value: 'cash',
            label: 'Cash (Pay at Pickup)',
            requires_proof: false,
        },
        status: { value: 'pending', label: 'Pending' },
        notice: 'Payment is collected at pickup.',
        rejection_reasons: [],
        can_resubmit_proof: false,
    },
    shipment: null,
    delivery_quote: null,
    product_subtotal: '199999.98',
    delivery_fee: '0.00',
    items: [
        {
            id: 11,
            product,
            quantity: 2,
            unit_price: '99999.99',
            line_total: '199999.98',
        },
    ],
    item_count: 1,
    total_quantity: 2,
    total: '199999.98',
    cancellation_blocked_reason: null,
};
const checkout = {
    cart,
    customer: { name: 'Customer', default_delivery_address: null },
    pickupLocation: {
        name: 'Battlefront Sagay City',
        address: 'A, E Marañon Street, Poblacion II, Sagay City',
    },
    fulfillmentMethods: [
        { value: 'pickup', label: 'Pickup' },
        { value: 'delivery', label: 'Delivery' },
    ],
    paymentMethods: [
        {
            value: 'cash',
            label: 'Cash (Pay at Pickup)',
            available_for: ['pickup'],
            requires_proof: false,
        },
        {
            value: 'card_at_store',
            label: 'Debit/Credit Card (Pay at Store)',
            available_for: ['pickup'],
            requires_proof: false,
        },
        ...['gcash', 'maya'].map((value) => ({
            value,
            label: value === 'gcash' ? 'GCash' : 'Maya',
            available_for: ['pickup', 'delivery'],
            requires_proof: true,
            payment_account: {
                account_name: 'Demo receiving account',
                account_number: '09XX XXX XXXX',
                is_demo: true,
            },
        })),
        {
            value: 'cod',
            label: 'Cash on Delivery (COD)',
            available_for: ['delivery'],
            requires_proof: false,
        },
    ],
    pickupQuote: {
        product_subtotal: cart.total,
        delivery_fee: '0.00',
        total: cart.total,
    },
    deliveryQuotes: [
        {
            destination: 'Sagay City',
            carrier: 'lbc',
            product_subtotal: cart.total,
            delivery_fee: '80.00',
            total: '200079.98',
            base_fee: '80.00',
            handling_surcharge: '0.00',
            preparation_days: 1,
            packing_expectation: 'Standard packing',
            is_demo: true,
            assumption_label:
                'Battlefront-configured demonstration estimates, not official LBC quotations.',
            notice: 'Battlefront estimates, not live LBC quotations or tracking.',
            estimated_delivery_start: '2026-10-13',
            estimated_delivery_end: '2026-10-15',
        },
    ],
    deliveryLocations: [
        {
            code: '1804523000',
            destination: 'Sagay City',
            name: 'Sagay City',
            barangays: [{ code: '1804523018', name: 'Poblacion II' }],
        },
    ],
    deliveryAddressPrefill: {
        delivery_destination: 'Sagay City',
        delivery_barangay_code: '1804523018',
        delivery_address_details: '12 Mabini Street',
    },
};

async function openBrowser(t, { built = true } = {}) {
    const profile = await mkdtemp(join(tmpdir(), 'battlefront-responsive-'));
    const browser = spawn(
        executable,
        [
            '--headless',
            '--disable-gpu',
            '--no-first-run',
            '--no-default-browser-check',
            // Intercepted fixture documents lack the local server's IP metadata.
            '--disable-features=LocalNetworkAccessChecks',
            '--remote-debugging-port=0',
            `--user-data-dir=${profile}`,
            'about:blank',
        ],
        { windowsHide: true, stdio: 'ignore' },
    );
    let socket;
    t.after(async () => {
        if (socket?.readyState === WebSocket.OPEN) socket.close();
        browser.kill();
        await delay(200);
        assert.ok(
            profile.startsWith(join(tmpdir(), 'battlefront-responsive-')),
        );
        await rm(profile, {
            recursive: true,
            force: true,
            maxRetries: 5,
            retryDelay: 200,
        });
    });
    let port;
    for (let attempt = 0; attempt < 100; attempt++) {
        try {
            port = (
                await readFile(join(profile, 'DevToolsActivePort'), 'utf8')
            ).split('\n')[0];
            break;
        } catch {
            await delay(100);
        }
    }
    assert.ok(port, 'The configured browser must expose its debugging port');
    const tabs = await (await fetch(`http://localhost:${port}/json`)).json();
    socket = new WebSocket(
        tabs.find((tab) => tab.type === 'page').webSocketDebuggerUrl,
    );
    await new Promise((resolve, reject) => {
        socket.addEventListener('open', resolve, { once: true });
        socket.addEventListener('error', reject, { once: true });
    });
    let sequence = 0;
    const pending = new Map();
    const exceptions = [];
    const diagnostics = [];
    let intercept;
    socket.addEventListener('message', (event) => {
        const message = JSON.parse(event.data);
        if (message.id) {
            const request = pending.get(message.id);
            pending.delete(message.id);
            clearTimeout(request.timer);
            if (message.error)
                request.reject(new Error(JSON.stringify(message.error)));
            else request.resolve(message.result);
        } else if (message.method === 'Fetch.requestPaused') {
            void intercept(message.params).catch((error) => {
                if (error.message.includes('Invalid InterceptionId'))
                    diagnostics.push(error.message);
                else exceptions.push(error.message);
            });
        } else if (message.method === 'Runtime.exceptionThrown') {
            exceptions.push(
                message.params.exceptionDetails.exception?.description ??
                    message.params.exceptionDetails.text,
            );
        } else if (message.method === 'Log.entryAdded') {
            diagnostics.push(message.params.entry.text);
        } else if (message.method === 'Network.loadingFailed') {
            diagnostics.push(message.params.errorText);
        }
    });
    const command = (method, params = {}) =>
        new Promise((resolve, reject) => {
            const id = ++sequence;
            const timer = setTimeout(() => {
                pending.delete(id);
                reject(new Error(`Browser timed out: ${method}`));
            }, 15000);
            pending.set(id, { resolve, reject, timer });
            socket.send(JSON.stringify({ id, method, params }));
        });
    const evaluate = async (expression) => {
        const result = await command('Runtime.evaluate', {
            expression,
            awaitPromise: true,
            returnByValue: true,
        });
        assert.equal(
            result.exceptionDetails,
            undefined,
            JSON.stringify(result.exceptionDetails),
        );
        return result.result.value;
    };
    await command('Page.enable');
    await command('Runtime.enable');
    await command('Log.enable');
    await command('Network.enable');
    await command('Page.navigate', { url: baseUrl });
    for (let attempt = 0; attempt < 100; attempt++) {
        if (await evaluate(`!!document.querySelector('#app')?.children.length`))
            break;
        await delay(100);
    }
    const initialPage = await evaluate(
        `JSON.parse(document.querySelector('script[data-page="app"]').textContent)`,
    );
    let template = await (await fetch(baseUrl)).text();
    if (built) {
        const manifest = JSON.parse(
            await readFile(
                new URL('../../../public/build/manifest.json', import.meta.url),
                'utf8',
            ),
        );
        const app = manifest['resources/js/app.js'];
        const styles = [
            manifest['resources/css/app.css'].file,
            ...(app.css ?? []),
        ];
        template = template
            .replace(
                /<script\b[^>]*src="[^"]*(?:@vite\/client|resources\/js\/)[^"]*"[^>]*><\/script>/g,
                '',
            )
            .replace(
                /<link\b[^>]*href="[^"]*resources\/css\/app.css[^"]*"[^>]*>/g,
                '',
            )
            .replace(
                '</head>',
                styles
                    .map(
                        (file) =>
                            `<link rel="stylesheet" href="/build/${file}">`,
                    )
                    .join('') +
                    `<script type="module" src="/build/${app.file}"></script></head>`,
            );
    }
    let fixture;
    let fixtureNumber = 0;
    const navigationFixtures = new Map();
    const formFixtures = new Map();
    const jsonFixtures = new Map();
    const jsonRequests = [];
    const formRequests = [];
    const navigationRequests = [];
    const filterRequests = [];
    const appearanceRequests = [];
    const fixturePage = (current = fixture) => ({
        ...initialPage,
        component: current.component,
        flash: current.flash,
        scrollProps:
            current.component === 'Products/Index'
                ? {
                      products: {
                          pageName: 'page',
                          currentPage: 1,
                          previousPage: null,
                          nextPage: null,
                      },
                  }
                : {},
        props: {
            ...initialPage.props,
            is_personalized: false,
            has_featured_fallback: false,
            guest_recommendation_scope: null,
            recommendations_enabled: true,
            ...current.props,
            appearance: current.appearance,
            auth: current.auth ?? { user: null, can: {} },
            errors: current.props.errors ?? {},
            notificationSummary: { unread_count: 0, recent: [] },
            responsiveFixtureNumber: current.number,
        },
        url: current.url ?? '/__responsive_fixture',
    });
    intercept = async ({
        requestId,
        request,
        resourceType,
        responseStatusCode,
    }) => {
        if (
            responseStatusCode &&
            resourceType === 'Document' &&
            new URL(request.url).pathname === '/__responsive_fixture'
        ) {
            const page = fixturePage();
            const encoded = JSON.stringify(page).replaceAll('<', '\\u003c');
            const html = template
                .replace(
                    /(<script data-page="app"[^>]*>)[\s\S]*?(<\/script>)/,
                    `$1${encoded}$2`,
                )
                .replace(
                    /<div data-server-rendered="true" id="app">[\s\S]*?(?=<\/body>)/,
                    '<div id="app"></div>',
                )
                .replace(
                    /data-authenticated="[^"]*"/,
                    `data-authenticated="${fixture.auth?.user ? 'true' : 'false'}"`,
                )
                .replace(
                    /data-appearance="[^"]*"/,
                    `data-appearance="${fixture.appearance}"`,
                )
                .replace(
                    /(<html\b[^>]*)(>)/,
                    (_, attributes, end) =>
                        attributes.replace(/\sclass="[^"]*"/, '') +
                        ` class="${fixture.appearance === 'dark' ? 'dark' : ''}"` +
                        end,
                );
            const observedHtml = html.replace(
                /(<script data-guest-appearance>[\s\S]*?)(<\/script>)/,
                '$1 window.appearanceBeforeApp = document.documentElement.classList.contains("dark"); $2',
            );
            assert.notEqual(
                html,
                template,
                'Replace the initial Inertia page with an isolated fixture',
            );
            await command('Fetch.fulfillRequest', {
                requestId,
                responseCode: 200,
                responseHeaders: [
                    { name: 'Content-Type', value: 'text/html; charset=utf-8' },
                ],
                body: Buffer.from(observedHtml).toString('base64'),
            });
        } else if (
            new URL(request.url).pathname.endsWith('/delivery-locations')
        ) {
            const url = new URL(request.url);
            const level = url.searchParams.get('level');
            const parent = url.searchParams.get('parent_code');
            const city = {
                code: '1804523000',
                name: 'Sagay City',
                destination: 'Sagay City',
                region_code: '1800000000',
                province_code: '1804500000',
                type: 'city',
                city_class: 'CC',
            };
            const locations =
                level === 'regions'
                    ? [{ code: '1800000000', name: 'Negros Island Region' }]
                    : level === 'provinces'
                      ? [
                            {
                                code: '1804500000',
                                name: 'Negros Occidental',
                                region_code: '1800000000',
                            },
                        ]
                      : level === 'cities' && parent === '1804500000'
                        ? [city]
                        : level === 'city'
                          ? [city]
                          : level === 'barangays'
                            ? checkout.deliveryLocations[0].barangays.map(
                                  (barangay) => ({
                                      ...barangay,
                                      city_code: city.code,
                                  }),
                              )
                            : [];
            await command('Fetch.fulfillRequest', {
                requestId,
                responseCode: 200,
                responseHeaders: [
                    { name: 'Content-Type', value: 'application/json' },
                ],
                body: Buffer.from(
                    JSON.stringify({
                        data: { version: '2026-03-31.v2', locations },
                    }),
                ).toString('base64'),
            });
        } else if (
            new URL(request.url).pathname.endsWith('/checkout/delivery-quote')
        ) {
            const quote = {
                ...checkout.deliveryQuotes[0],
                destination: '1804523000',
                destination_label: 'Sagay City',
                payment_methods: ['gcash', 'maya', 'cod'],
            };
            await command('Fetch.fulfillRequest', {
                requestId,
                responseCode: 200,
                responseHeaders: [
                    { name: 'Content-Type', value: 'application/json' },
                ],
                body: Buffer.from(
                    JSON.stringify({ data: { available: true, quote } }),
                ).toString('base64'),
            });
        } else if (
            request.method === 'PATCH' &&
            new URL(request.url).pathname === '/settings/appearance'
        ) {
            appearanceRequests.push(JSON.parse(request.postData));
            const page = await evaluate(
                "JSON.parse(JSON.stringify(document.querySelector('#app').__vue_app__.config.globalProperties.$page))",
            );
            page.props.appearance = JSON.parse(request.postData).appearance;
            fixture.appearance = page.props.appearance;
            page.flash = {
                toast: { type: 'success', message: 'Appearance updated.' },
            };
            await command('Fetch.fulfillRequest', {
                requestId,
                responseCode: 200,
                responseHeaders: [
                    { name: 'Content-Type', value: 'application/json' },
                    { name: 'X-Inertia', value: 'true' },
                ],
                body: Buffer.from(JSON.stringify(page)).toString('base64'),
            });
        } else if (
            request.method === 'GET' &&
            navigationFixtures.has(new URL(request.url).pathname)
        ) {
            navigationRequests.push(new URL(request.url).pathname);
            if (new URL(request.url).pathname === '/products')
                filterRequests.push(request.url);
            const page = fixturePage(
                navigationFixtures.get(new URL(request.url).pathname),
            );
            await command('Fetch.fulfillRequest', {
                requestId,
                responseCode: 200,
                responseHeaders: [
                    { name: 'Content-Type', value: 'application/json' },
                    { name: 'X-Inertia', value: 'true' },
                ],
                body: Buffer.from(JSON.stringify(page)).toString('base64'),
            });
        } else if (
            jsonFixtures.has(
                request.method + ' ' + new URL(request.url).pathname,
            )
        ) {
            jsonRequests.push(request);
            const { payload, status, delayMs } = jsonFixtures.get(
                request.method + ' ' + new URL(request.url).pathname,
            );
            if (delayMs) await delay(delayMs);
            await command('Fetch.fulfillRequest', {
                requestId,
                responseCode: status,
                responseHeaders: [
                    { name: 'Content-Type', value: 'application/json' },
                ],
                body: Buffer.from(JSON.stringify(payload)).toString('base64'),
            });
        } else if (
            request.method !== 'GET' &&
            formFixtures.has(new URL(request.url).pathname)
        ) {
            formRequests.push(request);
            const page = fixturePage(
                formFixtures.get(new URL(request.url).pathname),
            );
            await command('Fetch.fulfillRequest', {
                requestId,
                responseCode: 200,
                responseHeaders: [
                    { name: 'Content-Type', value: 'application/json' },
                    { name: 'X-Inertia', value: 'true' },
                ],
                body: Buffer.from(JSON.stringify(page)).toString('base64'),
            });
        } else if (request.method !== 'GET') {
            await command('Fetch.failRequest', {
                requestId,
                errorReason: 'Aborted',
            });
        } else if (
            new URL(request.url).pathname.endsWith('/notifications/summary')
        ) {
            await command('Fetch.fulfillRequest', {
                requestId,
                responseCode: 200,
                responseHeaders: [
                    { name: 'Content-Type', value: 'application/json' },
                ],
                body: Buffer.from('{"unread_count":0,"recent":[]}').toString(
                    'base64',
                ),
            });
        } else await command('Fetch.continueRequest', { requestId });
    };
    await command('Fetch.enable', {
        patterns: [
            { urlPattern: '*', requestStage: 'Request' },
            { urlPattern: '*/__responsive_fixture', requestStage: 'Response' },
        ],
    });
    return {
        command,
        evaluate,
        exceptions,
        diagnostics,
        navigationRequests,
        appearanceRequests,
        filterRequests,
        formRequests,
        jsonRequests,
        registerJson(method, url, payload, status = 200, delayMs = 0) {
            jsonFixtures.set(method + ' ' + url, { payload, status, delayMs });
        },
        registerForm(url, props) {
            formFixtures.set(url, { ...fixture, props });
        },
        registerPage(url, component, props, appearance, auth = fixture.auth) {
            navigationFixtures.set(url, {
                ...fixture,
                url,
                component,
                props,
                appearance,
                auth,
                flash: {},
            });
        },
        async show(
            component,
            props,
            customer = true,
            appearance = 'light',
            flash = {},
            capabilities = {},
        ) {
            if (!customer && appearance !== null) {
                await evaluate(
                    `localStorage.setItem('battlefront_guest_appearance', ${JSON.stringify(appearance)})`,
                );
            }
            fixture = {
                component,
                props,
                appearance,
                flash,
                number: ++fixtureNumber,
                auth: customer
                    ? {
                          user: {
                              id: 999999,
                              name: 'Responsive Customer',
                              email: 'fixture@example.test',
                              role:
                                  customer === 'admin'
                                      ? 'administrator'
                                      : 'customer',
                              appearance,
                          },
                          can: {
                              useCustomerCart: customer !== 'admin',
                              accessAdministration: customer === 'admin',
                              ...capabilities,
                          },
                      }
                    : undefined,
            };
            await command('Page.navigate', {
                url: `${baseUrl}/__responsive_fixture`,
            });
            for (let attempt = 0; attempt < 100; attempt++) {
                if (
                    await evaluate(
                        `!!document.querySelector('#app')?.__vue_app__ && !!document.querySelector('h1') && JSON.parse(document.querySelector('script[data-page="app"]').textContent).props.responsiveFixtureNumber === ${fixture.number}`,
                    )
                ) {
                    await evaluate('document.fonts.ready.then(() => true)');
                    return;
                }
                await delay(100);
            }
            const state = await evaluate(
                `({ title: document.title, url: location.href, heading: document.querySelector('h1')?.textContent, app: document.querySelector('#app')?.innerHTML.slice(0, 250) })`,
            );
            assert.fail(
                `The ${component} fixture did not mount: ${JSON.stringify(state)}; ${exceptions.join('; ')}; ${diagnostics.slice(-8).join('; ')}`,
            );
        },
        async resize(width, height = 900) {
            await command('Emulation.setDeviceMetricsOverride', {
                width,
                height,
                deviceScaleFactor: 1,
                mobile: false,
            });
            await delay(150);
        },
    };
}

async function assertFits(browser, label) {
    const clipped = await browser.evaluate(`(() => {
        const failures = [];
        for (const element of document.querySelectorAll('main h1, main h2, main h3, main p, main dd, main label, main input:not([type=hidden]), main button, main a, header nav')) {
            const rect = element.getBoundingClientRect();
            if (!rect.width || !rect.height) continue;
            let parent = element.parentElement;
            let scrollable = false;
            while (parent && parent !== document.body) {
                if (['auto', 'scroll'].includes(getComputedStyle(parent).overflowX)) { scrollable = true; break; }
                parent = parent.parentElement;
            }
            if (!scrollable && (rect.left < -1 || rect.right > innerWidth + 1)) failures.push(element.textContent.trim().slice(0,80) || element.id);
        }
        return failures;
    })()`);
    assert.deepEqual(
        clipped,
        [],
        `${label}: content must fit the viewport even when the shell clips overflow`,
    );
}

test(
    'return and refund screens keep compact summaries and role-specific forms usable',
    {
        skip: !baseUrl || !executable,
        timeout: 120000,
    },
    async (t) => {
        const browser = await openBrowser(t);
        const paginated = (data) => ({
            data,
            total: data.length,
            current_page: 1,
            last_page: 1,
            links: [],
        });
        const customerOrder = {
            ...order,
            can_cancel: false,
            can_request_return: true,
            items: [
                {
                    ...order.items[0],
                    can_request: true,
                    remaining_returnable: 1,
                },
            ],
            return_reason_options: { defective: 'Defective', other: 'Other' },
            return_eligibility: {
                received_at: null,
                deadline_at: null,
                window_days: 7,
                window_approved: false,
                deadline_passed: false,
                remaining_seconds: null,
                reasons: {},
            },
            return_instructions: {
                label: 'Return instructions',
                branch: {
                    name: 'Battlefront Sagay City',
                    city: 'Sagay City',
                    address: 'Verified return address',
                    contact_number: '09386476046',
                },
                message: 'Contact Battlefront before sending items by courier.',
            },
            return_requests: [
                {
                    id: 1,
                    product: { name: longName },
                    quantity: 1,
                    status: { value: 'submitted', label: 'Awaiting receipt' },
                    reason_label: 'Defective',
                    reason: 'The item needs inspection.',
                    submitted_at: '2026-10-23T06:30:00Z',
                    can_withdraw: true,
                },
                {
                    id: 2,
                    product: { name: 'Returned keyboard' },
                    quantity: 1,
                    status: {
                        value: 'refund_processing',
                        label: 'Refund processing',
                    },
                    reason_label: 'Defective',
                    submitted_at: '2026-10-23T06:30:00Z',
                    can_withdraw: false,
                    return_decision: 'accepted',
                    resolution: {
                        type: 'refund',
                        summary: 'Agreed refund for the returned keyboard.',
                    },
                    refund: {
                        approved_amount: '200.00',
                        confirmed_paid_amount: '50.00',
                        remaining_amount: '150.00',
                        payment_recorded: true,
                    },
                },
            ],
            refund_payments: [
                {
                    id: 9,
                    amount: '50.00',
                    payment_method: 'cash',
                    paid_at: '2026-10-23T08:30:00Z',
                    confirmation_method: 'approver',
                },
            ],
        };
        const returned = {
            id: 1,
            product_name: longName,
            quantity: 1,
            customer_name: 'Customer',
            purchase_order_id: 7,
            condition: 'opened',
            requester_id: 12,
            requester_name: 'Receiving staff',
            reviewer_name: null,
            reason: 'Inspection requested',
            status: 'pending',
            age_days: 2,
            held_quantity: 1,
            refundable_item_value: '200.00',
            has_product_photo: false,
            can_upload_evidence: true,
        };
        const refund = {
            ...returned,
            id: 2,
            product_name: 'Returned keyboard',
            status: 'accepted',
            resolution_type: 'refund',
            resolution_summary: 'Agreed refund for the returned keyboard.',
            disposition: 'restock',
            held_quantity: 0,
            refund_id: 31,
            approved_amount: '200.00',
            verified_paid_amount: '50.00',
            awaiting_review_amount: '0.00',
            disputed_amount: '0.00',
            refund_balance: '150.00',
            unrecorded_refund_amount: '150.00',
            payment_submission_key: 'preview-submission-key',
            can_upload_evidence: false,
        };
        async function preview(name) {
            if (!process.env.BATTLEFRONT_RETURN_PREVIEW) return;
            const { data } = await browser.command('Page.captureScreenshot', {
                format: 'png',
            });
            await writeFile(
                join(process.env.BATTLEFRONT_RETURN_PREVIEW, name + '.png'),
                Buffer.from(data, 'base64'),
            );
        }
        for (const theme of ['light', 'dark']) {
            await browser.show(
                'Orders/Show',
                { order: customerOrder, isConfirmation: false },
                true,
                theme,
            );
            const overview = `document.querySelector('[aria-labelledby="return-requests-heading"]')`;
            assert.deepEqual(
                await browser.evaluate(
                    `${overview}.querySelectorAll('article dl dt').length`,
                ),
                3,
            );
            assert.match(
                await browser.evaluate(`${overview}.textContent`),
                /Confirmed paid/,
            );
            assert.doesNotMatch(
                await browser.evaluate(`${overview}.className`),
                /surface-panel|bg-card/,
            );
            for (const width of [320, 375, 768, 1440]) {
                await browser.resize(width);
                await assertFits(browser, `customer returns ${theme} ${width}`);
                if ([375, 1440].includes(width))
                    await preview(`${theme}-customer-${width}`);
            }
            const details = `${overview}.querySelector('details')`;
            assert.equal(await browser.evaluate(`${details}.open`), false);
            await browser.evaluate(
                `${details}.querySelector('summary').focus()`,
            );
            for (const type of ['keyDown', 'keyUp'])
                await browser.command('Input.dispatchKeyEvent', {
                    type,
                    key: ' ',
                    code: 'Space',
                    windowsVirtualKeyCode: 32,
                });
            assert.equal(await browser.evaluate(`${details}.open`), true);
            assert.match(
                await browser.evaluate(`${details}.textContent`),
                /not active/,
            );
            await browser.evaluate(
                `${overview}.querySelector('button').click()`,
            );
            assert.equal(
                await browser.evaluate(
                    `document.querySelector('[role=dialog] h2').textContent.trim()`,
                ),
                'Request a return',
            );
            await browser.evaluate(
                `(() => { const select = document.querySelector('#return-item'); select.value = '11'; select.dispatchEvent(new Event('change', { bubbles: true })); const reason = document.querySelector('#return-reason'); reason.value = 'defective'; reason.dispatchEvent(new Event('change', { bubbles: true })); })()`,
            );
            await browser.evaluate(
                `document.querySelector('[role=dialog] button[type=submit]').click()`,
            );
            assert.equal(
                await browser.evaluate(
                    `document.querySelector('[role=dialog] h2').textContent.trim()`,
                ),
                'Confirm return request',
            );
            assert.match(
                await browser.evaluate(
                    `document.querySelector('[role=dialog]').textContent`,
                ),
                /only after payment confirmation/,
            );
            await browser.resize(375);
            assert.deepEqual(
                await browser.evaluate(
                    `(() => { const box = document.querySelector('[role=dialog]').getBoundingClientRect(); return [box.left >= 0, box.right <= innerWidth, box.height <= innerHeight]; })()`,
                ),
                [true, true, true],
            );

            for (const role of ['staff', 'manager', 'owner']) {
                const canReceive = role === 'staff';
                const props = {
                    returns: paginated([
                        returned,
                        { ...refund, can_record_payment: role === 'owner' },
                    ]),
                    customerRequests: paginated([
                        {
                            id: 10,
                            order_id: 7,
                            order_reference: 'BF-000007',
                            product_name: longName,
                            quantity: 1,
                            customer_name: 'Customer',
                            reason_label: 'Defective',
                            reason: 'Product inspection requested',
                            created_at: '2026-10-23T06:30:00Z',
                            can_receive: canReceive,
                        },
                    ]),
                    can_receive_returns: canReceive,
                    selected_order: null,
                    suppliers: [],
                    refundPayments: {},
                    evidence: {},
                    paymentEvidence: {},
                    filters: {},
                };
                const capabilities = {
                    viewOrders: true,
                    requestReturns: canReceive,
                    approveReturns: !canReceive,
                    recordRefundPayments: role === 'owner',
                    dispatchReturns: true,
                };
                await browser.show(
                    'Administration/CustomerReturns',
                    props,
                    'admin',
                    theme,
                    {},
                    capabilities,
                );
                assert.doesNotMatch(
                    await browser.evaluate(
                        `document.querySelector('main').textContent`,
                    ),
                    /Results update automatically as you type/,
                );
                assert.equal(
                    await browser.evaluate(
                        `document.querySelectorAll('main input[name=amount]').length`,
                    ),
                    role === 'owner' ? 1 : 0,
                );
                assert.equal(
                    await browser.evaluate(
                        `document.querySelectorAll('main select[name=decision]').length`,
                    ),
                    canReceive ? 0 : 1,
                );
                assert.equal(
                    await browser.evaluate(
                        `document.querySelector('main input[type=search], #return-search').getBoundingClientRect().top < document.querySelector('#customer-requests-heading').getBoundingClientRect().top`,
                    ),
                    true,
                );
                for (const width of [320, 375, 768, 1440]) {
                    await browser.resize(width);
                    await assertFits(
                        browser,
                        `administration returns ${role} ${theme} ${width}`,
                    );
                    if ([375, 1440].includes(width))
                        await preview(`${theme}-${role}-${width}`);
                }
                if (canReceive) {
                    const manual = `document.querySelector('main details')`;
                    assert.equal(
                        await browser.evaluate(`${manual}.open`),
                        false,
                    );
                    await browser.evaluate(
                        `${manual}.querySelector('summary').click()`,
                    );
                    assert.equal(
                        await browser.evaluate(
                            `${manual}.querySelector('[name=order_id]').required`,
                        ),
                        true,
                    );
                    await browser.evaluate(
                        `document.querySelector('[aria-labelledby=customer-requests-heading] form button').click()`,
                    );
                    assert.equal(
                        await browser.evaluate(
                            `document.querySelector('#receipt-condition-10').required`,
                        ),
                        true,
                    );
                    assert.equal(
                        await browser.evaluate(
                            `document.querySelector('[aria-labelledby=customer-requests-heading] input[type=file]').accept`,
                        ),
                        '.jpg,.jpeg,.png,.webp',
                    );
                    await browser.resize(320);
                    await assertFits(browser, `receipt form ${theme}`);
                } else if (role === 'owner') {
                    await browser.evaluate(
                        `(() => { const select = document.querySelector('main select[name=payment_method]'); select.value = 'cash'; select.dispatchEvent(new Event('change', { bubbles: true })); })()`,
                    );
                    assert.equal(
                        await browser.evaluate(
                            `document.querySelector('main input[name=payment_confirmed]').required`,
                        ),
                        true,
                    );
                    assert.equal(
                        await browser.evaluate(
                            `document.querySelector('main input[name=amount]').value`,
                        ),
                        '150.00',
                    );
                    await browser.evaluate(
                        `(() => { const select = document.querySelector('main select[name=payment_method]'); select.value = 'gcash'; select.dispatchEvent(new Event('change', { bubbles: true })); })()`,
                    );
                    assert.equal(
                        await browser.evaluate(
                            `document.querySelector('main input[name=proof]').required`,
                        ),
                        true,
                    );
                    assert.equal(
                        await browser.evaluate(
                            `document.querySelector('main input[name=proof]').multiple`,
                        ),
                        false,
                    );
                    await browser.resize(320);
                    await assertFits(browser, `refund form ${theme}`);
                }
            }
        }
        assert.deepEqual(browser.exceptions, []);
    },
);

test(
    'compact administration editors preserve drafts, request fields and visible validation errors',
    {
        skip: !baseUrl || !executable,
        timeout: 120000,
    },
    async (t) => {
        const browser = await openBrowser(t);
        const paginated = (data) => ({
            data,
            total: data.length,
            current_page: 1,
            last_page: 1,
        });
        const inventory = {
            products: paginated([
                {
                    ...product,
                    category: 'RAM',
                    product_code: 'RAM-007',
                    is_active: true,
                    stock_status: 'low_stock',
                    inventory: {
                        ...product.inventory,
                        quarantined_quantity: 2,
                    },
                },
            ]),
            filters: { q: null, category_id: null, stock: 'all' },
            filter_options: { categories: [] },
            low_stock_count: 1,
            out_of_stock_count: 0,
        };
        const staff = {
            viewInventory: true,
            requestInventory: true,
            requestInventoryAdjustment: true,
        };
        const trigger = `document.querySelector('main [data-slot="collapsible-trigger"]')`;
        const form = `document.querySelector('main article form')`;
        const expanded = `${trigger}.getAttribute('aria-expanded')`;
        async function space() {
            for (const type of ['keyDown', 'keyUp']) {
                await browser.command('Input.dispatchKeyEvent', {
                    type,
                    key: ' ',
                    code: 'Space',
                    windowsVirtualKeyCode: 32,
                });
            }
        }

        for (const theme of ['light', 'dark']) {
            await browser.resize(375);
            await browser.show(
                'Administration/Inventory',
                inventory,
                'admin',
                theme,
                {},
                staff,
            );
            assert.equal(await browser.evaluate(expanded), 'false');
            assert.equal(
                await browser.evaluate(
                    `${form}.getBoundingClientRect().height`,
                ),
                0,
            );
            assert.equal(
                await browser.evaluate(
                    `document.querySelectorAll('main article form').length`,
                ),
                1,
                'staff cannot change reorder levels',
            );
            await browser.evaluate(`${trigger}.focus()`);
            await space();
            assert.equal(await browser.evaluate(expanded), 'true');
            await browser.evaluate(
                `(() => { const form = ${form}; form.elements.quantity.value = '12'; form.elements.reason_code.value = 'count_error'; form.elements.reason.value = 'Shelf count checked'; for (const field of [form.elements.quantity, form.elements.reason_code, form.elements.reason]) { field.dispatchEvent(new Event('input', { bubbles: true })); field.dispatchEvent(new Event('change', { bubbles: true })); } })()`,
            );
            await space();
            assert.equal(await browser.evaluate(expanded), 'false');
            await space();
            assert.equal(
                await browser.evaluate(`${form}.elements.quantity.value`),
                '12',
                'closing retains the draft',
            );
            assert.deepEqual(
                await browser.evaluate(
                    `Object.fromEntries(new FormData(${form}))`,
                ),
                {
                    observed_quantity: String(product.inventory.quantity),
                    quantity: '12',
                    reorder_level: '10',
                    reason_code: 'count_error',
                    reason: 'Shelf count checked',
                },
            );
            for (const width of [320, 375, 768, 1024, 1440]) {
                await browser.resize(width);
                await assertFits(browser, `inventory editor ${theme} ${width}`);
            }
            await browser.evaluate(`${trigger}.click()`);
            assert.ok(
                await browser.evaluate(
                    `document.querySelector('main article').getBoundingClientRect().height < 220`,
                ),
                'closed rows stay compact on desktop',
            );
            if (process.env.BATTLEFRONT_ADMIN_PREVIEW) {
                for (const width of [375, 1440]) {
                    await browser.resize(width);
                    const { data } = await browser.command(
                        'Page.captureScreenshot',
                        { format: 'png' },
                    );
                    await writeFile(
                        join(
                            process.env.BATTLEFRONT_ADMIN_PREVIEW,
                            `${theme}-inventory-${width}.png`,
                        ),
                        Buffer.from(data, 'base64'),
                    );
                }
            }

            const path = await browser.evaluate(
                `new URL(${form}.action).pathname`,
            );
            browser.registerForm(path, {
                ...inventory,
                errors: {
                    quantity: 'Stock changed. Check the latest quantity.',
                },
            });
            const previous = browser.formRequests.length;
            await browser.evaluate(`${form}.requestSubmit()`);
            for (let attempt = 0; attempt < 100; attempt++) {
                if (
                    await browser.evaluate(
                        `${expanded} === 'true' && document.querySelector('main article').textContent.includes('Stock changed. Check the latest quantity.')`,
                    )
                )
                    break;
                await delay(50);
            }
            assert.equal(browser.formRequests.length, previous + 1);
            assert.equal(
                await browser.evaluate(expanded),
                'true',
                'server errors reveal the submitted editor',
            );
            assert.equal(
                await browser.evaluate(`${form}.elements.quantity.value`),
                '12',
            );
            assert.equal(
                await browser.evaluate(
                    `document.querySelector('main article [aria-invalid="true"]').name`,
                ),
                'quantity',
            );
        }

        await browser.show(
            'Administration/Inventory',
            inventory,
            'admin',
            'light',
            {},
            { viewInventory: true, reorderInventory: true },
        );
        assert.equal(
            await browser.evaluate(
                `document.querySelectorAll('main article form').length`,
            ),
            1,
        );
        assert.equal(
            await browser.evaluate(`${form}.elements.reorder_level.value`),
            '10',
        );
        assert.match(
            await browser.evaluate(`${trigger}.textContent`),
            /Edit reorder level/,
        );
        await browser.show(
            'Administration/Inventory',
            inventory,
            'admin',
            'light',
            {},
            { viewInventory: true },
        );
        assert.equal(
            await browser.evaluate(
                `document.querySelectorAll('main article form').length`,
            ),
            0,
        );
        const uninitialized = {
            ...inventory,
            products: paginated([
                {
                    ...inventory.products.data[0],
                    inventory: null,
                    stock_status: 'not_initialized',
                },
            ]),
        };
        await browser.show(
            'Administration/Inventory',
            uninitialized,
            'admin',
            'light',
            {},
            staff,
        );
        assert.match(
            await browser.evaluate(`${trigger}.textContent`),
            /Set up stock/,
        );
        assert.equal(
            await browser.evaluate(`${form}.elements.quantity.required`),
            true,
        );
        assert.equal(
            await browser.evaluate(`${form}.elements.reorder_level.required`),
            true,
        );

        const direct = {
            viewInventory: true,
            requestInventory: true,
            adjustInventory: true,
        };
        for (const theme of ['light', 'dark']) {
            await browser.show(
                'Administration/Inventory',
                inventory,
                'admin',
                theme,
                {},
                direct,
            );
            assert.match(
                await browser.evaluate(`${trigger}.textContent`),
                /Adjust stock/,
            );
            await browser.evaluate(`${trigger}.click()`);
            assert.equal(
                await browser.evaluate(
                    `${form}.querySelector('button[type=submit]').disabled`,
                ),
                true,
            );
            await browser.evaluate(`(() => {
                const form = ${form};
                form.elements.quantity.value = '12';
                form.elements.quantity.dispatchEvent(new Event('input', { bubbles: true }));
                form.elements.reason_code.value = 'theft_loss';
                form.elements.reason_code.dispatchEvent(new Event('change', { bubbles: true }));
            })()`);
            assert.equal(
                await browser.evaluate(`${form}.elements.reason.required`),
                true,
            );
            assert.match(
                await browser.evaluate(`${form}.textContent`),
                /Proposed/,
            );
            await browser.evaluate(
                `${form}.elements.stock_adjustment_confirmed.click()`,
            );
            assert.equal(
                await browser.evaluate(
                    `${form}.querySelector('button[type=submit]').disabled`,
                ),
                false,
            );
            await browser.evaluate(`(() => {
                const input = ${form}.elements.quantity;
                input.value = '8';
                input.dispatchEvent(new Event('input', { bubbles: true }));
            })()`);
            assert.equal(
                await browser.evaluate(
                    `${form}.elements.stock_adjustment_confirmed.checked`,
                ),
                false,
            );
            assert.equal(
                await browser.evaluate(
                    `${form}.querySelector('button[type=submit]').disabled`,
                ),
                true,
            );
            for (const width of [320, 768, 1440]) {
                await browser.resize(width);
                await assertFits(
                    browser,
                    `direct adjustment ${theme} ${width}`,
                );
            }
            await browser.evaluate(`(() => {
                const input = ${form}.elements.reason;
                input.value = 'Two units missing after shelf inspection';
                input.dispatchEvent(new Event('input', { bubbles: true }));
            })()`);
            await browser.evaluate(
                `${form}.elements.stock_adjustment_confirmed.click()`,
            );
            browser.registerForm(
                await browser.evaluate(`new URL(${form}.action).pathname`),
                {
                    ...inventory,
                    products: paginated([
                        {
                            ...inventory.products.data[0],
                            inventory: {
                                ...inventory.products.data[0].inventory,
                                quantity: 8,
                            },
                        },
                    ]),
                },
            );
            await browser.evaluate(`${form}.requestSubmit()`);
            for (let attempt = 0; attempt < 100; attempt++) {
                if (
                    await browser.evaluate(
                        `${form}.elements.observed_quantity.value === '8'`,
                    )
                )
                    break;
                await delay(50);
            }
            assert.equal(
                await browser.evaluate(
                    `${form}.elements.observed_quantity.value`,
                ),
                '8',
            );
            assert.equal(
                await browser.evaluate(`${form}.elements.quantity.value`),
                '8',
            );
            assert.equal(
                await browser.evaluate(
                    `${form}.elements.stock_adjustment_confirmed.checked`,
                ),
                false,
            );

            await browser.show(
                'Administration/Inventory',
                uninitialized,
                'admin',
                theme,
                {},
                direct,
            );
            await browser.evaluate(`${trigger}.click()`);
            assert.match(
                await browser.evaluate(`${form}.textContent`),
                /Set up stock/,
            );
            assert.equal(
                await browser.evaluate(
                    `${form}.elements.stock_adjustment_confirmed.required`,
                ),
                true,
            );
            assert.equal(
                await browser.evaluate(
                    `${form}.querySelector('button[type=submit]').disabled`,
                ),
                true,
            );
        }

        const reconciliation = {
            inventories: paginated([
                {
                    inventory_id: 7,
                    product_id: 7,
                    name: 'Counted product',
                    product_code: 'RAM-007',
                    sellable: 10,
                    quarantined: 0,
                    ledger_sellable: 10,
                    ledger_quarantined: 0,
                    sellable_difference: 0,
                    quarantined_difference: 0,
                    case_difference: 0,
                    case_expected: 0,
                    case_breakdown: {
                        damage: 0,
                        customer_return: 0,
                        supplier_return: 0,
                    },
                    findings: [],
                },
            ]),
            filters: { q: null },
        };
        await browser.show(
            'Administration/Reports/StockReconciliation',
            reconciliation,
            'admin',
            'light',
            {},
            direct,
        );
        await browser.evaluate(
            "document.querySelector('main details').open = true",
        );
        const countForm = "document.querySelector('main form')";
        assert.match(
            await browser.evaluate(`${countForm}.textContent`),
            /Apply count correction/,
        );
        assert.equal(
            await browser.evaluate(
                `${countForm}.elements.stock_adjustment_confirmed.required`,
            ),
            true,
        );
        await browser.evaluate(`(() => {
            const select = ${countForm}.elements.reason_code;
            select.value = 'theft_loss';
            select.dispatchEvent(new Event('change', { bubbles: true }));
        })()`);
        assert.equal(
            await browser.evaluate(`${countForm}.elements.reason.required`),
            true,
        );
        await browser.evaluate(
            `${countForm}.elements.stock_adjustment_confirmed.click()`,
        );
        await browser.evaluate(`(() => {
            const input = ${countForm}.elements.reason;
            input.value = 'Two units missing from shelf';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        })()`);
        assert.equal(
            await browser.evaluate(
                `${countForm}.elements.stock_adjustment_confirmed.checked`,
            ),
            false,
        );
        await browser.show(
            'Administration/Reports/StockReconciliation',
            reconciliation,
            'admin',
            'light',
            {},
            staff,
        );
        assert.equal(
            await browser.evaluate(
                `${countForm}.querySelector('[name=stock_adjustment_confirmed]')`,
            ),
            null,
        );
        assert.match(
            await browser.evaluate(`${countForm}.textContent`),
            /Manager or Owner approval is required/,
        );

        const suppliers = {
            suppliers: paginated([
                {
                    id: 3,
                    name: 'Verified supplier',
                    contact_name: 'Supplier contact',
                    contact_number: '09123456789',
                    email: 'supplier@example.test',
                    is_active: true,
                    edit_version: 4,
                },
            ]),
            filters: {},
        };
        const team = {
            users: paginated([
                {
                    id: 3,
                    name: 'Staff member',
                    email: 'staff@example.test',
                    role: 'staff',
                    is_active: true,
                    can_edit: true,
                    open_order_count: 1,
                    open_order_ids: [7],
                },
            ]),
            permissions: [],
            grants: [],
            availableRoles: ['staff', 'manager'],
            canManagePermissions: true,
            roleVersions: { staff: 1, manager: 1 },
            filters: {},
            canViewOrders: true,
            canAssignOrders: true,
        };
        for (const [component, props, field, value] of [
            [
                'Administration/Suppliers',
                suppliers,
                'contact_name',
                'Updated contact',
            ],
            ['Administration/Team', team, 'role', 'manager'],
        ]) {
            await browser.show(component, props, 'admin');
            const editor = `document.querySelector('main [data-slot="collapsible"]')`;
            const button = `${editor}.querySelector('button')`;
            assert.equal(
                await browser.evaluate(
                    `${button}.getAttribute('aria-expanded')`,
                ),
                'false',
            );
            await browser.evaluate(`${button}.click()`);
            await browser.evaluate(
                `(() => { const input = ${editor}.querySelector('form').elements.${field}; input.value = ${JSON.stringify(value)}; input.dispatchEvent(new Event('input', { bubbles: true })); input.dispatchEvent(new Event('change', { bubbles: true })); })()`,
            );
            await browser.evaluate(`${button}.click()`);
            await browser.evaluate(`${button}.click()`);
            assert.equal(
                await browser.evaluate(
                    `${editor}.querySelector('form').elements.${field}.value`,
                ),
                value,
            );
            for (const width of [320, 375, 768, 1440]) {
                await browser.resize(width);
                await assertFits(browser, `${component} ${width}`);
            }
            if (component.endsWith('Suppliers')) {
                assert.equal(
                    await browser.evaluate(
                        `${editor}.querySelector('form').elements.edit_version.value`,
                    ),
                    '4',
                );
            } else {
                assert.match(
                    await browser.evaluate(
                        `document.querySelector('main').textContent`,
                    ),
                    /Deactivation takes effect immediately/,
                );
            }
        }
        assert.deepEqual(browser.exceptions, []);
    },
);

test(
    'new administration pages keep unboxed titles and usable case actions in both themes',
    {
        skip: !baseUrl || !executable,
        timeout: 120000,
    },
    async (t) => {
        const browser = await openBrowser(t);
        const paginated = (data) => ({
            data,
            total: data.length,
            current_page: 1,
            last_page: 1,
        });
        const entry = {
            id: 3,
            product_name: longName,
            quantity: 2,
            held_quantity: 2,
            requester_id: 12,
            requester_name: 'Staff member',
            status: 'pending',
            reason: 'Inspection requested',
            age_days: 2,
            customer_name: 'Customer',
            purchase_order_id: 7,
            condition: 'opened',
            supplier_name: 'Verified supplier',
        };
        const products = [
            {
                id: 7,
                name: longName,
                product_code: 'GPU-007',
                available_quantity: 8,
            },
        ];
        const suppliers = [{ id: 3, name: 'Verified supplier' }];
        const capabilities = {
            viewInventory: true,
            requestInventory: true,
            approveInventory: true,
            requestReturns: true,
            approveReturns: true,
            viewOrders: true,
            viewAudit: true,
            exportAudit: true,
        };
        const fixtures = [
            [
                'Administration/WorkQueue',
                'Tasks',
                {
                    entries: paginated([
                        {
                            id: 3,
                            kind: 'stock_adjustment',
                            reference: longName,
                            required_action: 'Review request',
                            responsible_name: 'Staff member',
                            created_at: '2026-10-12T08:00:00Z',
                            age_days: 2,
                            href: '/administration/inventory-adjustments',
                        },
                    ]),
                    filters: {},
                },
            ],
            [
                'Administration/InventoryAdjustments',
                'Stock requests',
                {
                    requests: paginated([
                        { ...entry, observed_quantity: 8, target_quantity: 10 },
                    ]),
                    filters: {},
                },
            ],
            [
                'Administration/DamageReports',
                'Damage reports',
                {
                    reports: paginated([entry]),
                    products,
                    suppliers,
                    evidence: {},
                    filters: {},
                },
            ],
            [
                'Administration/SupplierReturns',
                'Supplier returns',
                {
                    returns: paginated([entry]),
                    products,
                    suppliers,
                    filters: {},
                },
            ],
            [
                'Administration/CustomerReturns',
                'Customer returns',
                {
                    returns: paginated([
                        entry,
                        {
                            ...entry,
                            id: 4,
                            status: 'accepted',
                            disposition: 'supplier_return',
                            held_quantity: 0,
                        },
                    ]),
                    selected_order: null,
                    suppliers,
                    refundPayments: {},
                    evidence: {},
                    paymentEvidence: {},
                    filters: {},
                },
            ],
            [
                'Administration/Reports/AuditLog',
                'Activity log',
                {
                    entries: paginated([
                        {
                            id: 3,
                            action_label: 'Stock change requested',
                            actor_name: 'Staff member',
                            actor_id: 12,
                            actor_type: 'administrator',
                            created_at: '2026-10-12T08:00:00Z',
                            subject_label: longName,
                            subject_id: 7,
                            details: [
                                { label: 'Quantity', before: '8', after: '10' },
                            ],
                            details_visible: true,
                        },
                    ]),
                    filters: {},
                    actors: [],
                    actions: [],
                    subjectTypes: [],
                },
            ],
            [
                'Administration/Reports/StockReconciliation',
                'Stock checks',
                {
                    inventories: paginated([
                        {
                            product_id: 7,
                            inventory_id: 17,
                            name: longName,
                            product_code: 'GPU-007',
                            sellable: 8,
                            ledger_sellable: 8,
                            quarantined: 2,
                            ledger_quarantined: 2,
                            case_expected: 2,
                            case_breakdown: {
                                damage: 2,
                                customer_return: 0,
                                supplier_return: 0,
                            },
                            status: 'matches',
                            findings: [],
                            sellable_difference: 0,
                            held_ledger_difference: 0,
                            case_difference: 0,
                        },
                    ]),
                    filters: {},
                },
            ],
            [
                'Administration/Reports/StaffPerformance',
                'My sales',
                {
                    filters: {},
                    staff: [
                        {
                            id: 12,
                            name: 'Staff member',
                            orders_count: 1,
                            gross_sales: '100.00',
                            refunds_paid: '0.00',
                            net_sales: '100.00',
                        },
                    ],
                    unattributed_count: 0,
                    can_view_all: false,
                    selected_handler_id: 12,
                    selected_handler_name: 'Staff member',
                    transactions: paginated([]),
                },
            ],
        ];
        for (const theme of ['light', 'dark']) {
            for (const [component, title, props] of fixtures) {
                await browser.show(
                    component,
                    props,
                    'admin',
                    theme,
                    {},
                    capabilities,
                );
                assert.equal(
                    await browser.evaluate(
                        `document.querySelectorAll('main h1').length`,
                    ),
                    1,
                    component,
                );
                assert.equal(
                    await browser.evaluate(
                        `document.querySelector('main h1').textContent.trim()`,
                    ),
                    title,
                );
                assert.deepEqual(
                    await browser.evaluate(
                        `(() => { const style = getComputedStyle(document.querySelector('[data-page-header]')); return [style.backgroundColor, style.boxShadow, style.borderTopWidth]; })()`,
                    ),
                    ['rgba(0, 0, 0, 0)', 'none', '0px'],
                );
                if (component === 'Administration/CustomerReturns') {
                    assert.match(
                        await browser.evaluate(
                            `document.querySelector('main').textContent`,
                        ),
                        /Supplier return: no units on hold/,
                    );
                    assert.doesNotMatch(
                        await browser.evaluate(
                            `document.querySelector('main').textContent`,
                        ),
                        /Supplier return complete/,
                    );
                }
                for (const width of [320, 375, 768, 1024, 1440]) {
                    await browser.resize(width);
                    await assertFits(browser, `${component} ${theme} ${width}`);
                }
            }
        }
        assert.deepEqual(browser.exceptions, []);
    },
);

test(
    'website branding stays transparent and fits public and authenticated layouts in both themes',
    {
        skip:
            !baseUrl || !executable
                ? 'Set the local app and browser environment variables'
                : false,
        timeout: 120000,
    },
    async (t) => {
        const browser = await openBrowser(t);
        const manifest = JSON.parse(
            await readFile(
                new URL('../../../public/build/manifest.json', import.meta.url),
                'utf8',
            ),
        );
        const assets = Object.fromEntries(
            ['logo', 'logo-dark', 'symbol'].map((name) => [
                name,
                `/build/${manifest[`docs/assets/battlefront-${name}.webp`].file}`,
            ]),
        );
        const alpha = await browser.evaluate(`(async () => {
        const pixels = {};
        const details = {};
        for (const [name, url] of Object.entries(${JSON.stringify(assets)})) {
            const image = new Image();
            image.src = url;
            await image.decode();
            const canvas = document.createElement('canvas');
            canvas.width = image.naturalWidth;
            canvas.height = image.naturalHeight;
            const context = canvas.getContext('2d');
            context.drawImage(image, 0, 0);
            const data = context.getImageData(0, 0, canvas.width, canvas.height).data;
            let transparent = 0;
            let internalWhite = 0;
            for (let i = 0; i < data.length; i += 4) {
                if (data[i + 3] === 0) transparent++;
                const x = (i / 4) % canvas.width;
                if (x < 300 && data[i] > 230 && data[i + 1] > 230 && data[i + 2] > 230 && data[i + 3] > 250) internalWhite++;
            }
            pixels[name] = data;
            details[name] = { dimensions: [canvas.width, canvas.height], corners: [data[3], data[canvas.width * 4 - 1], data[data.length - 1]], transparent, internalWhite };
        }
        let alphaChanges = 0;
        let monogramChanges = 0;
        for (let i = 0; i < pixels.logo.length; i += 4) {
            if (pixels.logo[i + 3] !== pixels['logo-dark'][i + 3]) alphaChanges++;
            if ((i / 4) % 1200 < 300 && pixels.logo[i + 3] > 0 && [0, 1, 2].some(channel => pixels.logo[i + channel] !== pixels['logo-dark'][i + channel])) monogramChanges++;
        }
        return { details, alphaChanges, monogramChanges };
    })()`);
        for (const [name, details] of Object.entries(alpha.details)) {
            assert.deepEqual(
                details.dimensions,
                name === 'symbol' ? [512, 512] : [1200, 303],
            );
            assert.deepEqual(
                details.corners,
                [0, 0, 0],
                `${name}: no opaque background rectangle`,
            );
            assert.ok(
                details.transparent > 10000,
                `${name}: retain genuine transparency`,
            );
            assert.ok(
                details.internalWhite > 1000,
                `${name}: keep the white artwork inside the monogram`,
            );
        }
        assert.equal(
            alpha.alphaChanges,
            0,
            'Theme variants must preserve identical lettering geometry',
        );
        assert.equal(
            alpha.monogramChanges,
            0,
            'The dark wordmark must leave the BCT artwork unchanged',
        );

        const readBrands = () =>
            browser.evaluate(`(async () => {
        const images = [...document.querySelectorAll('img[alt="Battlefront Computer Trading"]')].filter(image => image.getBoundingClientRect().width && image.getBoundingClientRect().height);
        await Promise.all(images.map(image => image.decode()));
        return images.map(image => ({ src: image.currentSrc, natural: [image.naturalWidth, image.naturalHeight], rect: image.getBoundingClientRect().toJSON(), parent: image.parentElement.getBoundingClientRect().toJSON(), fit: getComputedStyle(image).objectFit, background: getComputedStyle(image).backgroundColor }));
    })()`);
        const assertBrands = async (theme, width, expected, label) => {
            const brands = await readBrands();
            assert.ok(brands.length > 0, `${label}: show the branding`);
            assert.ok(
                brands.some((brand) =>
                    brand.src.includes(`/battlefront-${expected}-`),
                ),
                `${label}: use the appropriate brand asset`,
            );
            for (const brand of brands) {
                const symbol = brand.src.includes('/battlefront-symbol-');
                assert.deepEqual(
                    brand.natural,
                    symbol ? [512, 512] : [1200, 303],
                    label,
                );
                if (!symbol)
                    assert.equal(
                        brand.src.includes('/battlefront-logo-dark-'),
                        theme === 'dark',
                        label,
                    );
                assert.equal(brand.fit, 'contain', label);
                assert.equal(brand.background, 'rgba(0, 0, 0, 0)', label);
                assert.ok(
                    brand.rect.left >= -1 && brand.rect.right <= width + 1,
                    `${label}: logos fit the viewport`,
                );
                assert.ok(
                    brand.rect.height <= brand.parent.height + 1,
                    `${label}: logos fit their existing container (${JSON.stringify(brand)})`,
                );
                assert.ok(
                    Math.abs(
                        brand.rect.height -
                            brand.rect.width * (symbol ? 1 : 303 / 1200),
                    ) < 1,
                    `${label}: preserve logo proportions`,
                );
            }
            return brands;
        };
        const preview = async (name, theme, width) => {
            if (
                !process.env.BATTLEFRONT_UI_PREVIEW ||
                ![375, 1440].includes(width)
            )
                return;
            const screenshot = await browser.command('Page.captureScreenshot', {
                format: 'png',
                captureBeyondViewport: false,
            });
            await writeFile(
                `${process.env.BATTLEFRONT_UI_PREVIEW}-${name}-${theme}-${width}.png`,
                Buffer.from(screenshot.data, 'base64'),
            );
        };

        for (const theme of ['light', 'dark']) {
            for (const width of [320, 375, 768, 1440]) {
                await browser.resize(width);
                await browser.show('Welcome', {}, false, theme);
                const label = `${theme} ${width}px storefront`;
                const brands = await assertBrands(
                    theme,
                    width,
                    width < 640
                        ? 'symbol'
                        : theme === 'dark'
                          ? 'logo-dark'
                          : 'logo',
                    label,
                );
                assert.equal(
                    brands.filter(
                        (brand) => !brand.src.includes('/battlefront-symbol-'),
                    ).length,
                    width < 640 ? 1 : 2,
                    `${label}: retain footer and desktop header wordmarks`,
                );
                await assertFits(browser, label);
                await preview('storefront', theme, width);
                await browser.evaluate(
                    'window.scrollTo(0, document.documentElement.scrollHeight)',
                );
                await preview('footer', theme, width);
                if (width === 1440) {
                    const before = await browser.evaluate(
                        'document.querySelector("header").getBoundingClientRect().height',
                    );
                    await browser.evaluate(
                        'document.querySelector("header [role=switch]").click()',
                    );
                    await browser.evaluate(
                        'new Promise(requestAnimationFrame)',
                    );
                    const next = theme === 'dark' ? 'light' : 'dark';
                    await assertBrands(
                        next,
                        width,
                        next === 'dark' ? 'logo-dark' : 'logo',
                        'switching theme updates both wordmarks',
                    );
                    assert.equal(
                        await browser.evaluate(
                            'document.querySelector("header").getBoundingClientRect().height',
                        ),
                        before,
                    );
                }
            }
            await browser.resize(375);
            await browser.show(
                'auth/Login',
                { canResetPassword: true },
                false,
                theme,
            );
            await assertBrands(
                theme,
                375,
                'symbol',
                `${theme} sign-in branding`,
            );
            await preview('signin', theme, 375);

            for (const role of [true, 'admin']) {
                const component =
                    role === 'admin'
                        ? 'Dashboard/Administration'
                        : 'Dashboard/Customer';
                const dashboard =
                    role === 'admin'
                        ? {
                              kpis: {
                                  recorded_revenue: '0',
                                  recorded_sales: 0,
                                  pending_orders: 0,
                                  processing_orders: 0,
                                  low_stock_products: 0,
                                  out_of_stock_products: 0,
                              },
                              needs_attention: {
                                  pending_payment_reviews: 0,
                                  payment_orders: [],
                                  low_stock_products: [],
                                  out_of_stock_products: [],
                              },
                              recent_orders: [],
                          }
                        : {
                              summary: {
                                  cart_items: 0,
                                  cart_units: 0,
                                  active_orders: 0,
                                  total_orders: 0,
                              },
                              latest_order: null,
                          };
                for (const width of [375, 768, 1440]) {
                    await browser.resize(width);
                    await browser.show(
                        component,
                        { dashboard, sidebarOpen: true, recommendations: [] },
                        role,
                        theme,
                    );
                    if (width < 768) {
                        await browser.evaluate(
                            'document.querySelector("[data-sidebar=trigger]").click()',
                        );
                        await delay(350);
                    }
                    await assertBrands(
                        theme,
                        width,
                        theme === 'dark' ? 'logo-dark' : 'logo',
                        `${theme} ${width}px ${component}`,
                    );
                    await preview(
                        role === 'admin' ? 'admin' : 'customer',
                        theme,
                        width,
                    );
                    if (width >= 768) {
                        await browser.evaluate(
                            'document.querySelector("[data-sidebar=trigger]").click()',
                        );
                        await delay(350);
                        await assertBrands(
                            theme,
                            width,
                            'symbol',
                            `${theme} ${width}px collapsed sidebar`,
                        );
                    }
                }
            }
        }
        assert.deepEqual(browser.exceptions, []);
    },
);

test(
    'landing hero artwork skips mobile downloads and preserves the tablet and desktop layout in both themes',
    {
        skip:
            !baseUrl || !executable
                ? 'Set the local app and browser environment variables'
                : false,
        timeout: 120000,
    },
    async (t) => {
        const browser = await openBrowser(t);
        const imagePath = '/images/hero/battlefront-mouse-finger.webp';
        const heroWidths = [...widths, 639, 640, 1280, 1920, 2560].sort(
            (a, b) => a - b,
        );
        const asset = await fetch(new URL(imagePath, baseUrl));
        assert.equal(asset.status, 200);
        assert.match(asset.headers.get('content-type'), /image\/webp/);
        assert.ok(
            (await asset.arrayBuffer()).byteLength < 300000,
            'Keep the hero artwork lightweight',
        );

        const readLayout = `(() => {
        const heading = document.getElementById('hero-heading');
        const hero = heading.closest('section');
        const image = hero.querySelector('img');
        const css = getComputedStyle(image);
        return {
            image: image.getBoundingClientRect().toJSON(),
            copy: heading.parentElement.getBoundingClientRect().toJSON(),
            hero: hero.getBoundingClientRect().toJSON(),
            next: hero.nextElementSibling.getBoundingClientRect().top,
            natural: [image.naturalWidth, image.naturalHeight],
            source: image.currentSrc,
            artworkDownloads: performance.getEntriesByType('resource').filter(entry => entry.name.endsWith('/images/hero/battlefront-mouse-finger.webp')).length,
            alt: image.alt,
            fit: css.objectFit,
            mask: css.maskImage,
            background: css.backgroundColor,
            pageBackground: getComputedStyle(document.body).backgroundColor,
            dark: document.documentElement.classList.contains('dark'),
            links: [...hero.querySelectorAll('a')].map(link => ({ text: link.textContent.trim(), path: new URL(link.href).pathname })),
            oldCard: /Build station|Start with the essentials/.test(hero.textContent),
            overflow: document.documentElement.scrollWidth > innerWidth,
        };
    })()`;

        await browser.resize(768);
        await browser.command('Network.setCacheDisabled', {
            cacheDisabled: true,
        });
        await browser.command('Network.setBlockedURLs', {
            urls: [`*${imagePath}`],
        });
        await browser.show('Welcome', {}, false);
        const unloaded = await browser.evaluate(readLayout);
        assert.deepEqual(unloaded.natural, [0, 0]);
        assert.ok(
            unloaded.image.height > 0,
            'Reserve artwork space before it loads',
        );
        await browser.command('Network.setBlockedURLs', { urls: [] });
        await browser.evaluate(`(async () => {
        const image = document.querySelector('main section img');
        image.src = image.src;
        await image.decode();
    })()`);
        const loaded = await browser.evaluate(readLayout);
        assert.equal(loaded.image.height, unloaded.image.height);
        assert.equal(
            loaded.next,
            unloaded.next,
            'Image loading must not shift the following content',
        );

        for (const theme of ['light', 'dark']) {
            for (const customer of [false, true]) {
                for (const width of heroWidths) {
                    const label = `${theme} ${customer ? 'customer' : 'guest'} ${width}px hero`;
                    await browser.resize(width);
                    await browser.show('Welcome', {}, customer, theme);
                    await browser.evaluate(
                        `document.querySelector('main section img').decode()`,
                    );
                    const layout = await browser.evaluate(readLayout);

                    assert.ok(
                        layout.alt.includes('mouse') &&
                            layout.alt.includes('finger'),
                        label,
                    );
                    assert.equal(layout.fit, 'contain', label);
                    assert.equal(layout.background, 'rgba(0, 0, 0, 0)', label);
                    assert.equal(layout.dark, theme === 'dark', label);
                    assert.equal(
                        layout.pageBackground,
                        theme === 'dark'
                            ? 'rgb(9, 11, 16)'
                            : 'rgb(250, 247, 244)',
                        label,
                    );
                    assert.equal(layout.oldCard, false, label);
                    assert.equal(layout.overflow, false, label);
                    assert.deepEqual(
                        layout.links,
                        [
                            {
                                text: customer
                                    ? 'Go to dashboard'
                                    : 'Create an account',
                                path: customer ? '/dashboard' : '/register',
                            },
                            { text: 'Find a branch', path: '/branches' },
                        ],
                        label,
                    );
                    if (width < 640) {
                        assert.equal(
                            layout.image.width,
                            0,
                            `${label}: hide the artwork`,
                        );
                        assert.equal(
                            layout.image.height,
                            0,
                            `${label}: reserve no artwork space`,
                        );
                        assert.ok(
                            layout.source.startsWith('data:'),
                            `${label}: use a network-free mobile fallback`,
                        );
                        assert.equal(
                            layout.artworkDownloads,
                            0,
                            `${label}: do not download the hero WebP`,
                        );
                        assert.ok(
                            layout.hero.height <= layout.copy.height + 50,
                            `${label}: keep mobile hero padding compact`,
                        );
                        assert.ok(
                            Math.abs(layout.next - layout.hero.bottom) < 1,
                            `${label}: following content flows directly after the hero`,
                        );
                    } else {
                        assert.deepEqual(layout.natural, [1597, 985], label);
                        assert.ok(
                            layout.image.width > 0 && layout.image.height > 0,
                            `${label}: artwork remains visible`,
                        );
                        assert.ok(
                            Math.abs(
                                layout.image.height -
                                    (layout.image.width * 985) / 1597,
                            ) < 1,
                            `${label}: preserve image proportions`,
                        );
                        assert.ok(layout.image.left >= layout.hero.left, label);
                        assert.ok(
                            layout.image.right <= layout.hero.right,
                            `${label}: keep the compact artwork within the hero`,
                        );
                        assert.notEqual(
                            layout.mask,
                            'none',
                            `${label}: soften the hand's source edge with transparency`,
                        );
                        const mouse = {
                            left:
                                layout.image.left +
                                (layout.image.width * 80) / 1597,
                            right:
                                layout.image.left +
                                (layout.image.width * 1255) / 1597,
                            top:
                                layout.image.top +
                                (layout.image.height * 610) / 985,
                            bottom:
                                layout.image.top +
                                (layout.image.height * 952) / 985,
                        };
                        const fingerTip = {
                            x:
                                layout.image.left +
                                (layout.image.width * 670) / 1597,
                            y:
                                layout.image.top +
                                (layout.image.height * 550) / 985,
                        };
                        assert.ok(
                            mouse.left >= layout.hero.left &&
                                mouse.right <= layout.hero.right &&
                                mouse.top >= layout.hero.top &&
                                mouse.bottom <= layout.hero.bottom,
                            `${label}: retain the complete mouse`,
                        );
                        assert.ok(
                            fingerTip.x >= layout.hero.left &&
                                fingerTip.x <= layout.hero.right &&
                                fingerTip.y >= layout.hero.top &&
                                fingerTip.y <= layout.hero.bottom,
                            `${label}: retain the hovering fingertip`,
                        );
                        assert.ok(
                            layout.image.top >= layout.hero.top &&
                                layout.image.bottom <= layout.hero.bottom,
                            label,
                        );
                        if (width < 1024) {
                            assert.ok(
                                layout.image.top >= layout.copy.bottom,
                                `${label}: artwork follows the CTAs`,
                            );
                            assert.ok(
                                layout.image.top - layout.copy.bottom <= 40,
                                `${label}: keep the artwork close to the CTAs`,
                            );
                            assert.ok(
                                layout.image.width <= 416,
                                `${label}: keep the stacked artwork compact`,
                            );
                        } else {
                            assert.ok(
                                layout.image.left >= layout.copy.right,
                                `${label}: artwork sits beside the copy`,
                            );
                            assert.ok(
                                layout.image.width <= 576,
                                `${label}: cap the artwork on wide screens`,
                            );
                            assert.ok(
                                layout.image.height <= layout.copy.height,
                                `${label}: artwork must not increase the compact hero height`,
                            );
                            assert.ok(
                                layout.hero.height <= layout.copy.height + 130,
                                `${label}: retain the previous section spacing`,
                            );
                            assert.ok(
                                Math.abs(
                                    layout.image.top +
                                        layout.image.height / 2 -
                                        layout.copy.top -
                                        layout.copy.height / 2,
                                ) < 1,
                                `${label}: center the artwork vertically`,
                            );
                        }
                    }
                    await assertFits(browser, label);

                    if (
                        !customer &&
                        [375, 768, 1440, 1920, 2560].includes(width) &&
                        process.env.BATTLEFRONT_UI_PREVIEW
                    ) {
                        const screenshot = await browser.command(
                            'Page.captureScreenshot',
                            { format: 'png', captureBeyondViewport: false },
                        );
                        await writeFile(
                            `${process.env.BATTLEFRONT_UI_PREVIEW}-hero-${theme}-${width}.png`,
                            Buffer.from(screenshot.data, 'base64'),
                        );
                    }
                }
            }
        }
        assert.deepEqual(browser.exceptions, []);
    },
);

test(
    'landing footer keeps verified Sagay contacts and shopping links clear of customer support',
    {
        skip:
            !baseUrl || !executable
                ? 'Set the local app and browser environment variables'
                : false,
        timeout: 90000,
    },
    async (t) => {
        const browser = await openBrowser(t);

        for (const theme of ['light', 'dark']) {
            for (const width of widths) {
                const label = `${theme} ${width}px landing footer`;
                await browser.resize(width);
                await browser.show('Welcome', {}, false, theme);
                await browser.evaluate(`(async () => {
                await Promise.all([...document.images].map(image => image.decode()));
                window.scrollTo(0, document.documentElement.scrollHeight);
                await new Promise(requestAnimationFrame);
            })()`);
                const footer = await browser.evaluate(`(() => {
                const footer = document.querySelector('footer');
                const links = [...footer.querySelectorAll('a')];
                return {
                    links: links.map(link => ({ text: link.textContent.trim(), href: link.getAttribute('href'), target: link.target, rel: link.rel })),
                    obstructed: links.filter(link => {
                        const rect = link.getBoundingClientRect();
                        const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
                        return hit?.closest('a') !== link;
                    }).map(link => link.textContent.trim()),
                    sections: [...footer.firstElementChild.children].map(section => section.getBoundingClientRect().toJSON()),
                    logo: footer.querySelector('img')?.alt,
                    socialHeading: footer.querySelector('#footer-social-heading')?.textContent.trim(),
                    features: [...document.querySelectorAll('[aria-labelledby=shopping-heading] article h3')].map(heading => heading.textContent.trim()),
                    chatCount: document.querySelectorAll('[data-customer-chat]').length,
                    footerControls: footer.querySelectorAll('button, form, input').length,
                    overflow: document.documentElement.scrollWidth > innerWidth,
                    branchLinks: document.querySelectorAll('[aria-labelledby=branch-heading] a').length,
                };
            })()`);

                assert.deepEqual(
                    footer.links,
                    [
                        {
                            text: 'Shop Products',
                            href: '/products',
                            target: '',
                            rel: '',
                        },
                        {
                            text: 'Our Stores',
                            href: '/branches',
                            target: '',
                            rel: '',
                        },
                        {
                            text: 'battlefrontcomputertrading@gmail.com',
                            href: 'mailto:battlefrontcomputertrading@gmail.com',
                            target: '',
                            rel: '',
                        },
                        {
                            text: '0938 647 6046',
                            href: 'tel:09386476046',
                            target: '',
                            rel: '',
                        },
                        {
                            text: 'Facebook',
                            href: 'https://www.facebook.com/BattlefrontComputer',
                            target: '_blank',
                            rel: 'noopener noreferrer',
                        },
                        {
                            text: 'TikTok',
                            href: 'https://www.tiktok.com/@ralphpgonzaga',
                            target: '_blank',
                            rel: 'noopener noreferrer',
                        },
                    ],
                    label,
                );
                assert.equal(
                    footer.logo,
                    'Battlefront Computer Trading',
                    label,
                );
                assert.equal(
                    footer.socialHeading,
                    'Follow our Sagay branch',
                    label,
                );
                assert.deepEqual(
                    footer.features,
                    [
                        'Find the right hardware',
                        'Choose with confidence',
                        'Keep track of your order',
                    ],
                    label,
                );
                assert.equal(footer.chatCount, 1, label);
                assert.equal(footer.footerControls, 0, label);
                assert.equal(footer.branchLinks, 1, label);
                assert.equal(footer.overflow, false, label);
                assert.deepEqual(
                    footer.obstructed,
                    [],
                    `${label}: every footer link remains visible and clickable`,
                );
                if (width >= 1024) {
                    assert.ok(
                        footer.sections.every(
                            (section) =>
                                Math.abs(section.top - footer.sections[0].top) <
                                1,
                        ),
                        `${label}: use compact desktop columns`,
                    );
                } else if (width < 640) {
                    assert.ok(
                        footer.sections[1].top >= footer.sections[0].bottom &&
                            footer.sections[2].top >= footer.sections[1].bottom,
                        `${label}: stack mobile sections`,
                    );
                }
                assert.deepEqual(
                    await browser.evaluate(`(() => {
                const links = [...document.querySelectorAll('footer a')];
                return links.map(link => {
                    link.focus({ preventScroll: true });
                    return document.activeElement === link;
                });
            })()`),
                    Array(6).fill(true),
                    `${label}: footer links accept keyboard focus`,
                );
                await assertFits(browser, label);

                if (
                    [375, 1440].includes(width) &&
                    process.env.BATTLEFRONT_UI_PREVIEW
                ) {
                    const screenshot = await browser.command(
                        'Page.captureScreenshot',
                        {
                            format: 'png',
                            captureBeyondViewport: false,
                        },
                    );
                    await writeFile(
                        `${process.env.BATTLEFRONT_UI_PREVIEW}-footer-${theme}-${width}.png`,
                        Buffer.from(screenshot.data, 'base64'),
                    );
                }
            }
        }
        assert.deepEqual(browser.exceptions, []);
    },
);

test(
    'compact catalog filters support keyboard disclosure and clear individual selections on mobile',
    {
        skip:
            !baseUrl || !executable
                ? 'Set the local app and browser environment variables'
                : false,
        timeout: 90000,
    },
    async (t) => {
        const browser = await openBrowser(t);
        const catalog = {
            products: {
                data: [product],
                total: 1,
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
            filters: {
                q: 'graphics',
                category_id: 3,
                brand: 'Atlas',
                tag_id: 7,
                sort: 'price_asc',
            },
            filter_options: {
                categories: [{ id: 3, name: 'Graphics cards' }],
                brands: ['Atlas'],
                tags: [{ id: 7, name: 'Gaming' }],
            },
            recommendations: [],
        };
        const visible = (id) =>
            `!!document.querySelector('#${id}')?.getBoundingClientRect().height`;
        async function pressSpace() {
            for (const type of ['keyDown', 'keyUp']) {
                await browser.command('Input.dispatchKeyEvent', {
                    type,
                    key: ' ',
                    code: 'Space',
                    windowsVirtualKeyCode: 32,
                });
            }
        }
        for (const theme of ['light', 'dark']) {
            await browser.resize(375);
            await browser.show('Products/Index', catalog, true, theme);
            assert.equal(
                await browser.evaluate(visible('catalog-search')),
                true,
            );
            assert.equal(await browser.evaluate(visible('catalog-sort')), true);
            assert.equal(
                await browser.evaluate(visible('catalog-category')),
                false,
            );
            assert.equal(
                await browser.evaluate(
                    `document.querySelector('#catalog-filters-toggle').getAttribute('aria-expanded')`,
                ),
                'false',
            );
            assert.equal(
                await browser.evaluate(
                    `document.querySelectorAll('[aria-label^="Remove filter:"]').length`,
                ),
                3,
            );

            await browser.evaluate(
                `document.querySelector('#catalog-filters-toggle').focus()`,
            );
            assert.equal(
                await browser.evaluate(`document.activeElement.id`),
                'catalog-filters-toggle',
            );
            await pressSpace();
            assert.equal(
                await browser.evaluate(
                    `document.querySelector('#catalog-filters-toggle').getAttribute('aria-expanded')`,
                ),
                'true',
                await browser.evaluate(
                    `document.querySelector('#catalog-filters-toggle').outerHTML`,
                ),
            );
            for (const id of [
                'catalog-category',
                'catalog-brand',
                'catalog-tag',
            ]) {
                assert.equal(await browser.evaluate(visible(id)), true);
            }
            await pressSpace();
            assert.equal(
                await browser.evaluate(visible('catalog-category')),
                false,
            );
            assert.equal(
                await browser.evaluate(`document.activeElement.id`),
                'catalog-filters-toggle',
            );

            await browser.resize(768);
            assert.equal(
                await browser.evaluate(visible('catalog-filters-toggle')),
                false,
            );
            assert.equal(
                await browser.evaluate(visible('catalog-category')),
                true,
            );
            await assertFits(browser, 'compact tablet catalog');
            await browser.resize(375);
            assert.equal(
                await browser.evaluate(visible('catalog-category')),
                false,
            );
            assert.equal(
                await browser.evaluate(
                    `document.querySelectorAll('[aria-label^="Remove filter:"]').length`,
                ),
                3,
            );

            const cleared = {
                ...catalog,
                filters: { ...catalog.filters, category_id: null },
            };
            browser.registerPage('/products', 'Products/Index', cleared, theme);
            const previous = browser.filterRequests.length;
            await browser.evaluate(
                `document.querySelector('[aria-label="Remove filter: Graphics cards"]').click()`,
            );
            for (let attempt = 0; attempt < 100; attempt++) {
                if (
                    browser.filterRequests.length > previous &&
                    (await browser.evaluate(
                        `document.querySelectorAll('[aria-label^="Remove filter:"]').length === 2`,
                    ))
                )
                    break;
                await delay(50);
            }
            assert.ok(browser.filterRequests.length > previous);
            const request = new URL(browser.filterRequests.at(-1));
            assert.deepEqual(Object.fromEntries(request.searchParams), {
                q: 'graphics',
                brand: 'Atlas',
                tag_id: '7',
                sort: 'price_asc',
            });
            assert.equal(
                await browser.evaluate(
                    `document.querySelectorAll('[aria-label^="Remove filter:"]').length`,
                ),
                2,
            );
            await assertFits(browser, 'compact mobile catalog');
        }
        assert.deepEqual(browser.exceptions, []);
    },
);

test(
    'responsive shopping layouts remain usable in a real browser',
    {
        skip:
            !baseUrl || !executable
                ? 'Set BATTLEFRONT_UI_URL and BATTLEFRONT_UI_BROWSER to run browser geometry checks'
                : false,
        timeout: 180000,
    },
    async (t) => {
        const browser = await openBrowser(t);

        await t.test(
            'catalog has two mobile columns with readable prices and responsive image fitting',
            async () => {
                for (const customer of [false, true]) {
                    await browser.show(
                        'Products/Index',
                        {
                            products: {
                                data: Array.from({ length: 8 }, (_, index) => ({
                                    ...product,
                                    id: index + 1,
                                })),
                                total: 8,
                                current_page: 1,
                                last_page: 1,
                                next_page_url: null,
                                prev_page_url: null,
                            },
                            filters: { sort: 'featured' },
                            filter_options: {
                                categories: [],
                                brands: [],
                                tags: [],
                            },
                            recommendations: [],
                        },
                        customer,
                    );
                    for (const width of widths) {
                        await browser.resize(width);
                        await assertFits(browser, `catalog ${width}`);
                        const result = await browser.evaluate(`(() => {
                    const cards = [...document.querySelector('#product-list-heading').closest('section').querySelectorAll('a')];
                    const price = cards[0].querySelector('article > div:last-child');
                    return { columns: cards.filter(card => Math.abs(card.getBoundingClientRect().top-cards[0].getBoundingClientRect().top)<2).length,
                        showcaseAbsent: !document.querySelector('#featured-products-heading'),
                        featuredInteractions: cards.every(card => card.getAttribute('href')?.includes('/products/')
                            && card.textContent.includes('Featured') && card.textContent.includes('Low stock')),
                        sortFits: (() => {
                            const control = document.querySelector('#catalog-sort');
                            const rect = control.getBoundingClientRect();
                            return rect.left >= 0 && rect.right <= innerWidth && control.getAttribute('aria-labelledby')?.includes('catalog-sort-label');
                        })(),
                        readable: cards.every(card => { const name = card.querySelector('h3'); const price = card.querySelector('article > div:last-child > div > span:last-child'); return parseFloat(getComputedStyle(name).fontSize) >= 14 && name.scrollHeight <= name.clientHeight + 1 && parseFloat(getComputedStyle(price).fontSize) >= 16; }),
                        priceFits: price.scrollWidth <= price.clientWidth + 1, imageFits: getComputedStyle(cards[0].querySelector('img')).objectFit === 'contain',
                        descriptionHidden: getComputedStyle(cards[0].querySelector('h3 + p')).display === 'none' };
                })()`);
                        assert.equal(
                            result.columns,
                            width < 1024 ? 2 : width < 1280 ? 3 : 4,
                            `catalog columns at ${width}`,
                        );
                        assert.equal(
                            result.showcaseAbsent,
                            true,
                            'Catalog has no standalone Featured Products showcase',
                        );
                        assert.equal(
                            result.featuredInteractions,
                            true,
                            'Main grid products retain Featured badges, navigation and qualitative stock badges',
                        );
                        assert.equal(
                            result.sortFits,
                            true,
                            `labeled sort control fits at ${width}`,
                        );
                        assert.equal(
                            result.priceFits,
                            true,
                            `catalog price fits at ${width}`,
                        );
                        assert.equal(
                            result.readable,
                            true,
                            'Product names are at least 14px, fully readable, with prices at least 16px',
                        );
                        assert.equal(
                            result.imageFits,
                            width < 1024,
                            'Catalog photography is contained below desktop and covers its frame on desktop',
                        );
                        assert.equal(
                            result.descriptionHidden,
                            width < 640,
                            'Mobile cards reserve space for names, prices and stock',
                        );
                    }
                }
            },
        );

        await t.test(
            'product details keep guest and customer quantity actions within the panel',
            async () => {
                for (const customer of [false, true]) {
                    await browser.show(
                        'Products/Show',
                        {
                            product,
                            can_record_product_dwell: false,
                            recommendations: [],
                        },
                        customer,
                    );
                    for (const width of widths) {
                        await browser.resize(width);
                        await assertFits(browser, `product details ${width}`);
                        assert.equal(
                            await browser.evaluate(
                                `(() => { const button = document.querySelector('form button[type=submit]'); return button.scrollWidth <= button.clientWidth + 1; })()`,
                            ),
                            true,
                            `Quantity action text fits at ${width}`,
                        );
                    }
                }
            },
        );

        await t.test(
            'populated cart and order details fit, and cart removal dialogs scroll',
            async () => {
                for (const [component, props] of [
                    ['Cart/Index', { cart }],
                    [
                        'Orders/Index',
                        {
                            orders: {
                                data: [order],
                                total: 1,
                                current_page: 1,
                                last_page: 1,
                            },
                        },
                    ],
                    ['Orders/Show', { order, isConfirmation: false }],
                ]) {
                    await browser.show(component, props);
                    for (const width of widths) {
                        await browser.resize(width);
                        await assertFits(browser, `${component} ${width}`);
                    }
                }
                await browser.show('Cart/Index', {
                    cart: {
                        ...cart,
                        items: [
                            {
                                ...cart.items[0],
                                product: {
                                    ...cart.items[0].product,
                                    name: longName.repeat(12),
                                },
                            },
                        ],
                    },
                });
                await browser.resize(320, 480);
                await browser.evaluate(
                    `[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Remove').click()`,
                );
                await delay(300);
                const dialog = await browser.evaluate(
                    `(() => { const el = document.querySelector('[role=dialog]'); const rect = el.getBoundingClientRect(); el.scrollTop = el.scrollHeight; return { top: rect.top, bottom: rect.bottom, right: rect.right, scrolled: el.scrollTop > 0 }; })()`,
                );
                assert.ok(
                    dialog.top >= 0 &&
                        dialog.bottom <= 480 &&
                        dialog.right <= 320,
                    'Long dialog fits the available viewport',
                );
                assert.equal(
                    dialog.scrolled,
                    true,
                    'Dialog actions are reachable by scrolling',
                );
                await browser.evaluate(
                    `document.querySelector('[data-slot=dialog-close]').click()`,
                );
            },
        );

        await t.test(
            'checkout payment choices, wallet proof and totals stay usable on mobile',
            async () => {
                await browser.show('Checkout/Index', checkout);
                for (const width of widths) {
                    await browser.resize(width);
                    await assertFits(browser, `checkout pickup ${width}`);
                }
                await browser.resize(320);
                const checkoutState = await browser.evaluate(`(() => ({
            hasDelivery: !!document.querySelector('input[name=fulfillment_method][value=delivery]'),
            heading: document.querySelector('h1')?.textContent,
            text: document.querySelector('main')?.innerText.slice(0, 300),
        }))()`);
                assert.equal(
                    checkoutState.hasDelivery,
                    true,
                    `Checkout fixture must finish mounting: ${JSON.stringify(checkoutState)}; ${browser.exceptions.join('; ')}; ${browser.diagnostics.slice(-5).join('; ')}`,
                );
                await browser.evaluate(
                    `document.querySelector('input[name=fulfillment_method][value=delivery]').click()`,
                );
                await delay(300);
                assert.equal(
                    await browser.evaluate(
                        `document.querySelector('input[name=delivery_region_code]').value`,
                    ),
                    '1800000000',
                );
                assert.equal(
                    await browser.evaluate(
                        `document.querySelector('input[name=delivery_city_code]').value`,
                    ),
                    '1804523000',
                );
                for (const value of ['gcash', 'maya', 'cod']) {
                    await browser.evaluate(
                        `document.querySelector('input[name=payment_method][value=${value}]').click()`,
                    );
                    await delay(100);
                    await assertFits(browser, `checkout ${value} 320`);
                    assert.equal(
                        await browser.evaluate(
                            `!!document.querySelector('input[name=payment_proof]')`,
                        ),
                        value !== 'cod',
                    );
                }
                const sizes = await browser.evaluate(
                    `[...document.querySelectorAll('[data-slot=input], [data-slot=select-trigger], [data-slot=button]')].filter(el => el.getBoundingClientRect().height > 0).map(el => el.getBoundingClientRect().height)`,
                );
                assert.ok(
                    sizes.every((height) => height >= 44),
                    'Mobile form controls have at least 44px height',
                );
            },
        );

        await t.test(
            'navigation opens on phones and uses the desktop sidebar at the tablet breakpoint',
            async () => {
                await browser.show('Cart/Index', { cart });
                await browser.resize(320);
                await browser.evaluate(
                    `document.querySelector('[data-sidebar=trigger]').click()`,
                );
                await delay(300);
                assert.ok(
                    await browser.evaluate(
                        `!!document.querySelector('[role=dialog]')`,
                    ),
                    'Mobile navigation opens',
                );
                await browser.command('Input.dispatchKeyEvent', {
                    type: 'keyDown',
                    key: 'Escape',
                    code: 'Escape',
                    windowsVirtualKeyCode: 27,
                });
                await delay(400);
                await browser.resize(768);
                await browser.evaluate(
                    `document.querySelector('[data-sidebar=trigger]').click()`,
                );
                await delay(300);
                assert.equal(
                    await browser.evaluate(
                        `!!document.querySelector('[role=dialog]')`,
                    ),
                    false,
                    '768px toggles the desktop sidebar',
                );
            },
        );

        await t.test(
            'admin panels, charts and horizontally scrollable tables fit without losing desktop density',
            async () => {
                const fixtures = [
                    [
                        'Dashboard/Administration',
                        {
                            dashboard: {
                                kpis: {
                                    recorded_revenue: '829300.00',
                                    recorded_sales: 259,
                                    pending_orders: 0,
                                    processing_orders: 0,
                                    low_stock_products: 1,
                                    out_of_stock_products: 1,
                                },
                                needs_attention: {
                                    pending_payment_reviews: 0,
                                    payment_orders: [],
                                    low_stock_products: [
                                        {
                                            ...product,
                                            quantity: 3,
                                            reorder_level: 5,
                                        },
                                    ],
                                    out_of_stock_products: [product],
                                },
                                recent_orders: [],
                            },
                        },
                    ],
                    [
                        'Administration/Reports/Sales',
                        {
                            filters: {
                                from: '2026-09-01',
                                to: '2026-09-30',
                                period: 'month',
                            },
                            active_filters: { product: null, category: null },
                            kpis: {
                                total_sales: 2,
                                total_revenue: '199999.98',
                                average_order_value: '99999.99',
                                total_items_sold: 2,
                            },
                            timeline: {
                                labels: ['Sep 2026'],
                                sales: [2],
                                revenue: [199999.98],
                            },
                            top_products: {
                                ids: [7],
                                labels: [longName],
                                quantities: [2],
                            },
                            categories: {
                                ids: [3],
                                labels: ['Graphics cards'],
                                revenue: [199999.98],
                                rows: [],
                            },
                            products: {
                                data: [
                                    {
                                        id: 7,
                                        name: longName,
                                        category: 'Graphics cards',
                                        sales_count: 2,
                                        quantity_sold: 2,
                                        item_revenue: '199999.98',
                                    },
                                ],
                                total: 1,
                                current_page: 1,
                                last_page: 1,
                            },
                            recommendation_engagement: {
                                summary: {},
                                placements: [],
                            },
                        },
                    ],
                    [
                        'Administration/Customers/Show',
                        {
                            customer: {
                                id: 9,
                                name: 'Customer With A Long Name',
                                email: 'customer.with.a.long.email@example.test',
                                created_at: '2026-10-12T08:00:00Z',
                            },
                        },
                    ],
                    [
                        'Administration/Orders/Show',
                        {
                            order: {
                                ...order,
                                status: {
                                    value: 'processing',
                                    label: 'Processing',
                                },
                                allowed_status_transitions: [],
                                payment: {
                                    ...order.payment,
                                    pay_at_pickup: true,
                                    can_confirm_pickup: true,
                                },
                            },
                        },
                    ],
                    [
                        'Administration/Forecasting',
                        {
                            products: {
                                data: [],
                                current_page: 1,
                                last_page: 1,
                            },
                            filters: {},
                            timezone: 'Asia/Manila',
                            forecasts: {
                                data: [
                                    {
                                        id: 1,
                                        product: {
                                            ...product,
                                            product_code: 'PRODUCT123',
                                        },
                                        method_label: 'Additive Holt–Winters',
                                        forecast_quantity: '123.45',
                                        target_label: 'Q1 2027',
                                        source_label: '36 completed months',
                                        generated_at: '2026-10-12T08:00:00Z',
                                    },
                                ],
                                current_page: 1,
                                last_page: 1,
                            },
                        },
                    ],
                ];
                for (const [component, props] of fixtures) {
                    await browser.show(component, props, 'admin');
                    for (const width of widths) {
                        await browser.resize(width);
                        await assertFits(browser, `${component} ${width}`);
                        assert.equal(
                            await browser.evaluate(
                                `[...document.querySelectorAll('canvas')].every(el => el.getBoundingClientRect().right <= innerWidth + 1)`,
                            ),
                            true,
                            `Charts fit at ${width}`,
                        );
                        if (width < 768) {
                            assert.deepEqual(
                                await browser.evaluate(
                                    `[...document.querySelectorAll('[data-slot=button]')].filter(el => el.getBoundingClientRect().height && el.scrollHeight > el.clientHeight + 1).map(el => el.textContent.trim())`,
                                ),
                                [],
                                'Wrapped action labels fit inside their buttons',
                            );
                        }
                    }
                    if (
                        component === 'Administration/Reports/Sales' ||
                        component === 'Administration/Forecasting'
                    ) {
                        await browser.resize(320);
                        const table = await browser.evaluate(
                            `(() => { const table = document.querySelector('table'); const region = table.parentElement; region.focus(); region.scrollLeft = region.scrollWidth; return { scrolled: region.scrollLeft > 0, focused: document.activeElement === region }; })()`,
                        );
                        assert.equal(
                            table.scrolled,
                            true,
                            'Dense tables remain horizontally scrollable',
                        );
                        assert.equal(
                            table.focused,
                            true,
                            'Table scrolling is keyboard accessible',
                        );
                    }
                }
            },
        );

        assert.deepEqual(
            browser.exceptions,
            [],
            'Fixture pages mount without JavaScript exceptions',
        );
    },
);

test(
    'shopping photos fill desktop frames and preserve readable mobile cards in both themes',
    {
        skip:
            !baseUrl || !executable
                ? 'Set BATTLEFRONT_UI_URL and BATTLEFRONT_UI_BROWSER to run browser geometry checks'
                : false,
        timeout: 180000,
    },
    async (t) => {
        const browser = await openBrowser(t);
        const photos = [
            [240, 240],
            [320, 120],
            [120, 320],
            [240, 240],
        ].map(([width, height], index) => ({
            ...product,
            id: index + 1,
            image_url: `data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><rect width="100%" height="100%" fill="gray"/><rect x="1" y="1" width="${width - 2}" height="${height - 2}" fill="none" stroke="red" stroke-width="2"/></svg>`)}`,
        }));
        const recommendations = photos.map((product, index) => ({
            product: {
                ...product,
                inventory: {
                    ...product.inventory,
                    status: [
                        'in_stock',
                        'low_stock',
                        'out_of_stock',
                        'in_stock',
                    ][index],
                },
            },
            reasons: [
                { code: 'popular', value: 'Similar to a product you viewed' },
            ],
        }));
        const imageItems = photos.map((product, index) => ({
            ...cart.items[0],
            id: index + 1,
            product: { ...product, category: product.category.name },
        }));
        const pages = [
            [
                'Products/Index',
                {
                    products: {
                        data: photos,
                        total: 4,
                        current_page: 1,
                        last_page: 1,
                        next_page_url: null,
                        prev_page_url: null,
                    },
                    filters: {
                        q: null,
                        category_id: null,
                        brand: null,
                        tag_id: null,
                    },
                    filter_options: { categories: [], brands: [], tags: [] },
                    recommendations: [],
                },
                false,
            ],
            [
                'Products/Index',
                {
                    products: {
                        data: photos,
                        total: 4,
                        current_page: 1,
                        last_page: 1,
                        next_page_url: null,
                        prev_page_url: null,
                    },
                    filters: {
                        q: null,
                        category_id: null,
                        brand: null,
                        tag_id: null,
                    },
                    filter_options: { categories: [], brands: [], tags: [] },
                    recommendations,
                },
                true,
            ],
            [
                'Products/Show',
                {
                    product: photos[1],
                    can_record_product_dwell: false,
                    recommendations,
                },
                false,
            ],
            [
                'Products/Show',
                {
                    product: photos[1],
                    can_record_product_dwell: false,
                    recommendations,
                },
                true,
            ],
            [
                'Dashboard/Customer',
                {
                    dashboard: {
                        summary: {
                            cart_items: 0,
                            cart_units: 0,
                            active_orders: 0,
                            total_orders: 0,
                        },
                        latest_order: null,
                    },
                    recommendations,
                },
                true,
            ],
            [
                'Cart/Index',
                {
                    cart: {
                        ...cart,
                        items: imageItems,
                        item_count: 4,
                        total_quantity: 8,
                        total: '799999.92',
                    },
                    recommendations,
                },
                true,
            ],
            [
                'Orders/Show',
                {
                    order: {
                        ...order,
                        item_count: 4,
                        total_quantity: 8,
                        product_subtotal: '799999.92',
                        total: '799999.92',
                        items: photos.map((product, index) => ({
                            ...order.items[0],
                            id: index + 1,
                            product,
                        })),
                    },
                    isConfirmation: false,
                },
                true,
            ],
        ];
        const measurePhotos = `(() => [...document.querySelectorAll('main img[src^="data:image/svg+xml"]')].map(image => {
        const rect = image.getBoundingClientRect();
        const frameElement = image.parentElement.parentElement;
        const bounds = frameElement.getBoundingClientRect();
        const style = getComputedStyle(frameElement);
        const border = { left: parseFloat(style.borderLeftWidth), right: parseFloat(style.borderRightWidth), top: parseFloat(style.borderTopWidth), bottom: parseFloat(style.borderBottomWidth) };
        const frame = { left: bounds.left + border.left, right: bounds.right - border.right, top: bounds.top + border.top, bottom: bounds.bottom - border.bottom,
            width: bounds.width - border.left - border.right, height: bounds.height - border.top - border.bottom };
        const scale = Math.min(rect.width / image.naturalWidth, rect.height / image.naturalHeight);
        const width = image.naturalWidth * scale;
        const height = image.naturalHeight * scale;
        const idealScale = Math.min(frame.width / image.naturalWidth, frame.height / image.naturalHeight);
        return { recommendation: !!image.closest('[data-recommendation-product-id]'),
            catalog: !!image.closest('section[aria-labelledby="product-list-heading"]'), fit: getComputedStyle(image).objectFit, loaded: image.naturalWidth > 0,
            matchesDetailsHeight: Math.abs(bounds.bottom - (frameElement.nextElementSibling?.getBoundingClientRect().bottom ?? bounds.bottom)) < 0.75,
            edgeToEdge: Math.abs(rect.left - frame.left) < 0.75 && Math.abs(rect.right - frame.right) < 0.75 && Math.abs(rect.top - frame.top) < 0.75 && Math.abs(rect.bottom - frame.bottom) < 0.75,
            visible: rect.left + (rect.width - width) / 2 >= frame.left - 0.75 && rect.right - (rect.width - width) / 2 <= frame.right + 0.75 && rect.top + (rect.height - height) / 2 >= frame.top - 0.75 && rect.bottom - (rect.height - height) / 2 <= frame.bottom + 0.75,
            fillsFrame: scale >= idealScale * 0.97, frameWidth: frame.width, frameHeight: frame.height };
    }))()`;
        const themedPages = ['light', 'dark'].flatMap((theme) =>
            pages.map(([component, props, customer]) => ({
                theme,
                component,
                props,
                customer,
            })),
        );
        for (const { theme, component, props, customer } of themedPages) {
            await browser.show(component, props, customer, theme);
            await browser.evaluate(
                `Promise.all([...document.querySelectorAll('main img[src^="data:image/svg+xml"]')].map(image => { image.loading = 'eager'; return image.decode(); }))`,
            );
            for (const width of widths) {
                await browser.resize(width);
                await assertFits(browser, `${component} photography ${width}`);
                const images = await browser.evaluate(measurePhotos);
                assert.ok(
                    images.length >= 3,
                    `${component} renders the image fixtures`,
                );
                for (const image of images) {
                    const desktopCover =
                        width >= 1024 &&
                        (image.recommendation ||
                            ['Products/Index', 'Products/Show'].includes(
                                component,
                            ));
                    assert.equal(image.loaded, true);
                    assert.equal(
                        image.fit,
                        desktopCover ? 'cover' : 'contain',
                        `${component} ${theme} photo fit at ${width}`,
                    );
                    if (desktopCover) {
                        assert.equal(
                            image.edgeToEdge,
                            true,
                            `${component} ${theme} desktop photos fill the frame without letterboxing`,
                        );
                    } else {
                        assert.equal(
                            image.visible,
                            true,
                            `${component} ${theme} contained photos stay fully visible at ${width}`,
                        );
                    }
                    assert.equal(
                        image.fillsFrame,
                        true,
                        `${component} avoids unnecessary image padding at ${width}`,
                    );
                    if (
                        component === 'Products/Show' &&
                        width >= 1024 &&
                        !image.recommendation
                    ) {
                        assert.equal(
                            image.matchesDetailsHeight,
                            true,
                            'Desktop detail photography fills the row beside product information',
                        );
                    }
                }
                if (props.recommendations?.length) {
                    const cards = await browser.evaluate(`(() => {
                    const cards = [...document.querySelectorAll('[data-recommendation-product-id]')];
                    if (!cards.length) return { columns: 0, usable: false, clickable: false, hideHeight: 0, titlesCompact: false };
                    return { columns: getComputedStyle(cards[0].parentElement).gridTemplateColumns.split(' ').length,
                        usable: cards.every(card => {
                            const bounds = card.getBoundingClientRect();
                            return [...card.querySelectorAll('p, a, button, span')].every(element => {
                                if (element.classList.contains('sr-only') && getComputedStyle(element).position === 'absolute') return true;
                                const rect = element.getBoundingClientRect();
                                return rect.left >= bounds.left && rect.right <= bounds.right && element.scrollWidth <= element.clientWidth + 1;
                            });
                        }),
                        clickable: cards.every(card => [...card.querySelectorAll('a')].every(link => link.getAttribute('href')?.includes('/products/'))),
                        titlesReadable: cards.every(card => { const title = card.querySelectorAll('a')[1]; return parseFloat(getComputedStyle(title).fontSize) >= 14 && title.scrollHeight <= title.clientHeight + 1; }),
                        hideHeight: cards[0].querySelector('button').getBoundingClientRect().height };
                })()`);
                    assert.equal(
                        cards.columns,
                        width < 1024 ? 2 : width < 1280 ? 3 : 4,
                        `${component} recommendation columns at ${width}`,
                    );
                    assert.equal(
                        cards.usable,
                        true,
                        `${component} recommendation titles, prices and stock labels fit at ${width}`,
                    );
                    assert.equal(
                        cards.clickable,
                        true,
                        'Recommendation images and titles link to their products',
                    );
                    assert.ok(
                        cards.hideHeight >= (width < 768 ? 44 : 40),
                        'Recommendation hide actions retain usable targets',
                    );
                    assert.equal(
                        cards.titlesReadable,
                        true,
                        'Recommendation model names stay readable without clipping',
                    );
                    const photo = images.find((image) => image.recommendation);
                    assert.ok(
                        Math.abs(
                            photo.frameWidth / photo.frameHeight -
                                (width < 640 ? 1 : 1.5),
                        ) < 0.01,
                        'Recommendation images match catalog aspect ratios',
                    );
                }
                if (component === 'Products/Index') {
                    const cards = await browser.evaluate(`(() => {
                    const cards = [...document.querySelector('#product-list-heading').closest('section').querySelectorAll('a')];
                    return { columns: cards.filter(card => Math.abs(card.getBoundingClientRect().top - cards[0].getBoundingClientRect().top) < 2).length,
                        clickable: cards.every(card => card.getAttribute('href')?.includes('/products/')) };
                })()`);
                    assert.equal(
                        cards.columns,
                        width < 1024 ? 2 : width < 1280 ? 3 : 4,
                    );
                    assert.equal(cards.clickable, true);
                    const photo = images.find((image) => image.catalog);
                    assert.ok(
                        Math.abs(
                            photo.frameWidth / photo.frameHeight -
                                (width < 640 ? 1 : 1.5),
                        ) < 0.01,
                        'Catalog image aspect ratios are preserved',
                    );
                    const point = await browser.evaluate(
                        `(() => { const image = document.querySelector('#product-list-heading').closest('section').querySelector('img'); image.scrollIntoView({block:'center'}); const rect = image.getBoundingClientRect(); return {x:rect.left+rect.width/2,y:rect.top+rect.height/2}; })()`,
                    );
                    await browser.command('Input.dispatchMouseEvent', {
                        type: 'mouseMoved',
                        ...point,
                    });
                    await delay(350);
                    const hovered = (
                        await browser.evaluate(measurePhotos)
                    ).find((image) => image.catalog);
                    assert.ok(
                        await browser.evaluate(
                            `parseFloat(getComputedStyle(document.querySelector('#product-list-heading').closest('section').querySelector('img').parentElement).scale) > 1`,
                        ),
                        'Catalog hover zoom remains active',
                    );
                    if (width < 1024) {
                        assert.equal(
                            hovered.visible,
                            true,
                            'Mobile hover keeps the full photograph inside the frame',
                        );
                    }
                    assert.equal(
                        hovered.frameWidth,
                        photo.frameWidth,
                        'Hover preserves card width',
                    );
                    assert.equal(
                        hovered.frameHeight,
                        photo.frameHeight,
                        'Hover preserves image height',
                    );
                    await browser.command('Input.dispatchMouseEvent', {
                        type: 'mouseMoved',
                        x: 0,
                        y: 0,
                    });
                    await delay(350);
                }
            }
        }
        await browser.show(
            'Products/Show',
            {
                product: photos[1],
                can_record_product_dwell: false,
                recommendations,
            },
            true,
        );
        await browser.resize(320);
        await browser.evaluate(
            `document.querySelector('[data-recommendation-product-id] button').click()`,
        );
        assert.equal(
            await browser.evaluate(
                `document.querySelectorAll('[data-recommendation-product-id]').length`,
            ),
            3,
            'Mobile Hide removes only the selected suggestion',
        );
        await assertFits(browser, 'recommendations after dismissal at 320');
        if (process.env.BATTLEFRONT_UI_PREVIEW) {
            await browser.command('Page.navigate', {
                url: `${baseUrl}/products`,
            });
            for (let attempt = 0; attempt < 100; attempt++) {
                if (
                    await browser.evaluate(
                        `location.pathname === '/products' && !!document.querySelector('#app')?.__vue_app__ && !!document.querySelector('#product-list-heading')`,
                    )
                )
                    break;
                await delay(100);
            }
            for (const width of [375, 1440]) {
                await browser.resize(width);
                await browser.evaluate(
                    `(() => { const section = document.querySelector('#product-list-heading').closest('section'); scrollTo(0, section.getBoundingClientRect().top + scrollY - 88); return Promise.allSettled([...section.querySelectorAll('img')].slice(0,8).map(image => { image.loading = 'eager'; return image.decode(); })); })()`,
                );
                await delay(350);
                const screenshot = await browser.command(
                    'Page.captureScreenshot',
                    { format: 'png', captureBeyondViewport: false },
                );
                await writeFile(
                    `${process.env.BATTLEFRONT_UI_PREVIEW}-${width}.png`,
                    Buffer.from(screenshot.data, 'base64'),
                );
            }
            const liveProducts = await browser.evaluate(
                `JSON.parse(document.querySelector('script[data-page="app"]').textContent).props.products.data`,
            );
            await browser.show(
                'Products/Show',
                {
                    product: { ...product, ...liveProducts[0] },
                    can_record_product_dwell: false,
                    recommendations: liveProducts
                        .slice(0, 4)
                        .map((product) => ({
                            product,
                            reasons: [
                                {
                                    code: 'similar',
                                    value: 'Similar to a product you viewed',
                                },
                            ],
                        })),
                },
                true,
            );
            for (const width of [375, 1440]) {
                await browser.resize(width);
                await browser.evaluate(
                    `Promise.allSettled([...document.querySelectorAll('main img')].map(image => { image.loading = 'eager'; return image.decode(); }))`,
                );
                for (const [name, selector] of [
                    ['detail', 'main > article'],
                    ['recommendations', '#recommendation-section-heading'],
                ]) {
                    await browser.evaluate(
                        `(() => { const element = document.querySelector('${selector}'); scrollTo(0, element.getBoundingClientRect().top + scrollY - 88); })()`,
                    );
                    await delay(350);
                    const screenshot = await browser.command(
                        'Page.captureScreenshot',
                        { format: 'png', captureBeyondViewport: false },
                    );
                    await writeFile(
                        `${process.env.BATTLEFRONT_UI_PREVIEW}-${name}-${width}.png`,
                        Buffer.from(screenshot.data, 'base64'),
                    );
                }
            }
        }
        assert.deepEqual(browser.exceptions, []);
    },
);

test(
    'Warm Battlefront surfaces, preferences and charts stay consistent in both themes',
    {
        skip:
            !baseUrl || !executable
                ? 'Set the local app and browser environment variables'
                : false,
        timeout: 240000,
    },
    async (t) => {
        const browser = await openBrowser(t, { built: true });
        const paginated = (data) => ({
            data,
            total: data.length,
            current_page: 1,
            last_page: 1,
            next_page_url: null,
            prev_page_url: null,
        });
        const filters = {
            q: null,
            category_id: null,
            brand: null,
            tag_id: null,
            status: null,
            stock: 'all',
        };
        const filter_options = { categories: [], brands: [], tags: [] };
        const recommendations = [
            {
                product,
                reasons: [{ code: 'popular', value: 'Popular hardware' }],
            },
        ];
        const sales = {
            filters: { from: '2026-09-01', to: '2026-09-30', period: 'month' },
            active_filters: { product: null, category: null },
            kpis: {
                total_sales: 2,
                total_revenue: '199999.98',
                average_order_value: '99999.99',
                total_items_sold: 2,
            },
            timeline: {
                labels: ['Sep 2026'],
                sales: [2],
                revenue: [199999.98],
            },
            top_products: { ids: [7], labels: [longName], quantities: [2] },
            categories: {
                ids: [3],
                labels: ['Graphics cards'],
                revenue: [199999.98],
                rows: [],
            },
            products: paginated([
                {
                    id: 7,
                    name: longName,
                    category: 'Graphics cards',
                    sales_count: 2,
                    quantity_sold: 2,
                    item_revenue: '199999.98',
                },
            ]),
            recommendation_engagement: { summary: {}, placements: [] },
        };
        const forecast = {
            status: 'ready',
            product: { ...product, product_code: 'GPU-007' },
            forecast_quantity: 12,
            target_label: 'Q4 2026',
            source_label: '36 completed months',
            generated_at: '2026-10-12T08:00:00Z',
            timezone: 'Asia/Manila',
            observations: [
                { label: 'Sep 2026', start: '2026-09-01', quantity_sold: 8 },
            ],
            monthly_forecasts: ['Oct', 'Nov', 'Dec'].map((label) => ({
                label,
                forecast_quantity: 4,
            })),
        };
        const forecastProps = {
            products: paginated([]),
            filters: {},
            timezone: 'Asia/Manila',
            forecasts: paginated([]),
        };
        const fixtures = [
            ['Welcome', {}, false],
            [
                'Products/Index',
                {
                    products: paginated([product]),
                    filters,
                    filter_options,
                    recommendations,
                },
                true,
            ],
            [
                'Products/Show',
                { product, can_record_product_dwell: false, recommendations },
                true,
            ],
            ['Cart/Index', { cart, recommendations }, true],
            ['Checkout/Index', checkout, true],
            ['Orders/Index', { orders: paginated([order]) }, true],
            ['Orders/Show', { order, isConfirmation: false }, true],
            ['Branches/Index', { branches: [] }, false],
            [
                'Dashboard/Customer',
                {
                    dashboard: {
                        summary: {
                            cart_items: 1,
                            cart_units: 2,
                            active_orders: 0,
                            total_orders: 0,
                        },
                        latest_order: null,
                    },
                    recommendations,
                },
                true,
            ],
            [
                'Dashboard/Administration',
                {
                    dashboard: {
                        kpis: {
                            recorded_revenue: '400',
                            recorded_sales: 2,
                            pending_orders: 0,
                            processing_orders: 0,
                            low_stock_products: 0,
                            out_of_stock_products: 0,
                        },
                        needs_attention: {
                            pending_payment_reviews: 0,
                            payment_orders: [],
                            low_stock_products: [],
                            out_of_stock_products: [],
                        },
                        recent_orders: [],
                    },
                },
                'admin',
            ],
            [
                'Administration/Products/Index',
                {
                    products: paginated([
                        {
                            ...product,
                            product_code: 'GPU-007',
                            is_active: true,
                        },
                    ]),
                    filters,
                    filter_options,
                },
                'admin',
            ],
            [
                'Administration/Inventory',
                {
                    products: paginated([
                        {
                            ...product,
                            category: 'Graphics cards',
                            is_active: true,
                            stock_status: 'low_stock',
                            product_code: 'GPU-007',
                        },
                    ]),
                    filters,
                    filter_options,
                    low_stock_count: 1,
                    out_of_stock_count: 0,
                },
                'admin',
            ],
            [
                'Administration/Orders/Show',
                { order: { ...order, allowed_status_transitions: [] } },
                'admin',
            ],
            ['Administration/Reports/Sales', sales, 'admin'],
            [
                'Administration/Forecasting',
                forecastProps,
                'admin',
                { forecast_result: forecast },
            ],
            ['settings/Appearance', {}, true],
            [
                'settings/Profile',
                { canManageDefaultDeliveryAddress: false },
                'admin',
            ],
            ['settings/Security', { passwordRules: 'min:8' }, true],
            [
                'Administration/Categories/Index',
                { categories: paginated([]) },
                'admin',
            ],
            [
                'Administration/ChatbotKnowledge/Index',
                { knowledge: paginated([]) },
                'admin',
            ],
            [
                'Administration/Customers/Index',
                { customers: paginated([]) },
                'admin',
            ],
            [
                'Administration/Customers/Show',
                {
                    customer: {
                        id: 9,
                        name: 'Customer With A Long Name',
                        email: 'customer@example.test',
                        created_at: '2026-10-12T08:00:00Z',
                    },
                },
                'admin',
            ],
            [
                'Administration/Orders/Index',
                {
                    orders: paginated([]),
                    filters: {
                        status: 'active',
                        q: null,
                        payment_status: null,
                        payment_method: null,
                        fulfillment_method: null,
                    },
                    filter_options: {
                        payment_statuses: [],
                        payment_methods: [],
                        fulfillment_methods: [],
                    },
                    status_counts: { active: 0, completed: 0, cancelled: 0 },
                },
                'admin',
            ],
            [
                'auth/Login',
                { canResetPassword: true, canRegister: true },
                false,
            ],
        ];
        const readTheme = `(() => { const css = getComputedStyle(document.documentElement); const token = name => css.getPropertyValue(name).trim(); return { dark: document.documentElement.classList.contains('dark'), background: getComputedStyle(document.body).backgroundColor, surface: token('--card'), foreground: token('--foreground'), muted: token('--muted-foreground'), ink: token('--primary-ink'), input: token('--input'), primary: token('--primary'), primaryForeground: token('--primary-foreground'), colorScheme: css.colorScheme }; })()`;
        const luminance = (hex) => {
            if (hex.length === 4) {
                hex =
                    '#' +
                    Array.from(
                        hex.slice(1),
                        (character) => character + character,
                    ).join('');
            }
            const rgb = hex
                .slice(1)
                .match(/../g)
                .map((value) => parseInt(value, 16) / 255)
                .map((value) =>
                    value <= 0.04045
                        ? value / 12.92
                        : ((value + 0.055) / 1.055) ** 2.4,
                );
            return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722;
        };
        const contrast = (a, b) => {
            const values = [luminance(a), luminance(b)].sort((a, b) => b - a);
            return (values[0] + 0.05) / (values[1] + 0.05);
        };
        for (const theme of ['light', 'dark']) {
            await browser.command('Emulation.setEmulatedMedia', {
                features: [
                    {
                        name: 'prefers-color-scheme',
                        value: theme === 'light' ? 'dark' : 'light',
                    },
                ],
            });
            for (const [component, props, account, flash] of fixtures) {
                await browser.show(component, props, account, theme, flash);
                for (const width of widths) {
                    await browser.resize(width);
                    await assertFits(
                        browser,
                        component + ' ' + theme + ' ' + width,
                    );
                    const surfaces = await browser.evaluate(
                        `(() => [...document.querySelectorAll('.surface-card, .surface-panel')].map(element => { const style = getComputedStyle(element); return { panel: element.classList.contains('surface-panel'), radii: [style.borderTopLeftRadius, style.borderTopRightRadius, style.borderBottomRightRadius, style.borderBottomLeftRadius], shadow: style.boxShadow, nested: !!element.parentElement.closest('.surface-card, .surface-panel') }; }))()`,
                    );
                    if (
                        ![
                            'auth/Login',
                            'Welcome',
                            'Branches/Index',
                            'settings/Appearance',
                            'settings/Profile',
                            'settings/Security',
                        ].includes(component)
                    ) {
                        assert.ok(
                            surfaces.length > 0,
                            component + ' uses the shared surface treatment',
                        );
                    }
                    if (!['auth/Login', 'Products/Show'].includes(component)) {
                        const header = await browser.evaluate(`(() => {
                            const header = document.querySelector('[data-page-header]');
                            const style = getComputedStyle(header);
                            const heading = header.querySelector('h1');
                            return { count: document.querySelectorAll('h1').length, text: heading.textContent.trim(),
                                background: style.backgroundColor, shadow: style.boxShadow,
                                border: [style.borderTopWidth, style.borderRightWidth, style.borderBottomWidth, style.borderLeftWidth],
                                fontSize: parseFloat(getComputedStyle(heading).fontSize) };
                        })()`);
                        assert.equal(
                            header.count,
                            1,
                            component + ' has one page title',
                        );
                        assert.ok(header.text.length > 0);
                        assert.equal(
                            header.background,
                            'rgba(0, 0, 0, 0)',
                            component + ' has an unboxed heading',
                        );
                        assert.equal(header.shadow, 'none');
                        assert.deepEqual(header.border, [
                            '0px',
                            '0px',
                            '0px',
                            '0px',
                        ]);
                        assert.ok(
                            header.fontSize >= 24 && header.fontSize <= 30,
                        );
                    }
                    for (const surface of surfaces) {
                        assert.ok(
                            surface.radii.every(
                                (radius) =>
                                    radius ===
                                    (surface.panel ? '16px' : '12px'),
                            ),
                            component + ' has consistent corners',
                        );
                        assert.notEqual(
                            surface.shadow,
                            'none',
                            component + ' has subtle depth',
                        );
                        assert.equal(
                            surface.shadow.includes('inset'),
                            theme === 'dark',
                            component + ' uses mode-appropriate shadows',
                        );
                        assert.equal(
                            surface.nested,
                            false,
                            component + ' avoids stacked surface decoration',
                        );
                    }
                    const actual = await browser.evaluate(readTheme);
                    assert.equal(
                        actual.dark,
                        theme === 'dark',
                        component + ' honors its saved theme',
                    );
                    assert.equal(
                        actual.background,
                        theme === 'dark'
                            ? 'rgb(9, 11, 16)'
                            : 'rgb(250, 247, 244)',
                    );
                    assert.equal(actual.colorScheme, theme);
                    assert.ok(
                        contrast(actual.foreground, actual.surface) >= 4.5,
                        'Readable surface text: ' + JSON.stringify(actual),
                    );
                    assert.ok(
                        contrast(actual.muted, actual.surface) >= 4.5,
                        'Readable muted text',
                    );
                    assert.ok(
                        contrast(actual.ink, actual.surface) >= 4.5,
                        'Readable red text',
                    );
                    assert.ok(
                        contrast(actual.primaryForeground, actual.primary) >=
                            4.5,
                        'Readable primary buttons',
                    );
                    assert.ok(
                        contrast(actual.input, actual.surface) >= 3,
                        'Visible input boundaries',
                    );
                }
                if (
                    process.env.BATTLEFRONT_THEME_PREVIEW &&
                    [
                        'Welcome',
                        'Products/Index',
                        'Administration/Reports/Sales',
                        'Administration/Forecasting',
                    ].includes(component)
                ) {
                    for (const width of [390, 1440]) {
                        await browser.resize(width);
                        const shot = await browser.command(
                            'Page.captureScreenshot',
                            { format: 'png', captureBeyondViewport: false },
                        );
                        await writeFile(
                            process.env.BATTLEFRONT_THEME_PREVIEW +
                                '-' +
                                component.replaceAll('/', '-') +
                                '-' +
                                theme +
                                '-' +
                                width +
                                '.png',
                            Buffer.from(shot.data, 'base64'),
                        );
                    }
                }
            }
        }
        await browser.show('Welcome', {}, false, 'system');
        assert.equal(
            (await browser.evaluate(readTheme)).dark,
            false,
            'Legacy System renders Light',
        );
        await browser.command('Emulation.setEmulatedMedia', {
            features: [{ name: 'prefers-color-scheme', value: 'dark' }],
        });
        assert.equal(
            (await browser.evaluate(readTheme)).dark,
            false,
            'OS changes cannot override Light',
        );
        await browser.show('Administration/Reports/Sales', sales, 'admin');
        const manifest = JSON.parse(
            await readFile(
                new URL('../../../public/build/manifest.json', import.meta.url),
                'utf8',
            ),
        );
        const chartChunk = Object.values(manifest).find(
            (entry) => entry.name === 'useChartTheme',
        ).file;
        const readCharts = `(async () => { const module = await import('/build/${chartChunk}'); const Chart = Object.values(module).find(value => typeof value?.getChart === 'function'); return [...document.querySelectorAll('canvas')].map(canvas => { const chart = Chart.getChart(canvas); return { data: chart.data.datasets.map(dataset => dataset.data), legend: chart.options.plugins.legend.labels.color, tooltip: chart.options.plugins.tooltip.backgroundColor }; }); })()`;
        const before = await browser.evaluate(readCharts);
        assert.equal(before.length, 3);
        await browser.evaluate(
            `document.querySelector('[role=switch][aria-label="Dark mode"]').click()`,
        );
        for (let attempt = 0; attempt < 30; attempt++) {
            if (
                await browser.evaluate(
                    `document.querySelector('[role=switch]').getAttribute('aria-checked') === 'true' && document.querySelector('[data-sonner-toaster]')?.dataset.theme === 'dark'`,
                )
            )
                break;
            await delay(100);
        }
        assert.equal((await browser.evaluate(readTheme)).dark, true);
        const after = await browser.evaluate(readCharts);
        assert.deepEqual(
            after.map((chart) => chart.data),
            before.map((chart) => chart.data),
            'Theme switching preserves chart data',
        );
        assert.notEqual(
            after[0].legend,
            before[0].legend,
            'Mounted chart colors refresh immediately',
        );
        assert.notEqual(
            after[0].tooltip,
            before[0].tooltip,
            'Tooltip surfaces refresh immediately',
        );
        assert.equal(
            await browser.evaluate(
                `document.querySelector('[data-sonner-toaster]').dataset.theme`,
            ),
            'dark',
        );
        for (const theme of ['light', 'dark']) {
            await browser.show('Cart/Index', { cart }, true, theme);
            await browser.resize(390);
            await browser.evaluate(
                `[...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Remove').click()`,
            );
            await delay(300);
            assert.equal(
                await browser.evaluate(
                    `getComputedStyle(document.querySelector('[role=dialog]')).backgroundColor`,
                ),
                theme === 'dark' ? 'rgb(17, 19, 24)' : 'rgb(255, 255, 255)',
            );
        }
        assert.deepEqual(
            browser.exceptions,
            [],
            'Theme fixtures have no JavaScript exceptions',
        );
    },
);

test(
    'latest appearance survives history and prefetched catalog navigation',
    {
        skip:
            !baseUrl || !executable
                ? 'Set BATTLEFRONT_UI_URL and BATTLEFRONT_UI_BROWSER to run appearance navigation checks'
                : false,
        timeout: 90000,
    },
    async (t) => {
        const browser = await openBrowser(t);
        const catalogProps = {
            products: {
                data: [product],
                total: 1,
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
            filters: { sort: 'featured' },
            filter_options: { categories: [], brands: [], tags: [] },
            recommendations: [],
        };
        async function waitFor(expression, message) {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (await browser.evaluate(expression)) return;
                await delay(50);
            }
            assert.fail(message + '; ' + browser.exceptions.join('; '));
        }
        async function assertAppearance(selected) {
            const state = await browser.evaluate(
                `({ dark: document.documentElement.classList.contains('dark'), checked: document.querySelector('[role=switch][aria-label="Dark mode"]').getAttribute('aria-checked'), theme: document.querySelector('[data-sonner-toaster]')?.dataset.theme, documentPreserved: window.appearanceNavigationDocument === true })`,
            );
            assert.deepEqual(state, {
                dark: selected === 'dark',
                checked: String(selected === 'dark'),
                theme: selected,
                documentPreserved: true,
            });
        }
        for (const [width, initial, selected] of [
            [390, 'light', 'dark'],
            [1440, 'dark', 'light'],
        ]) {
            await browser.resize(width);
            await browser.show('Products/Index', catalogProps, true, initial);
            browser.registerPage(
                '/products/7',
                'Products/Show',
                {
                    product,
                    can_record_product_dwell: false,
                    recommendations: [],
                },
                initial,
            );
            browser.registerPage(
                '/orders',
                'Orders/Index',
                {
                    orders: {
                        data: [order],
                        total: 1,
                        current_page: 1,
                        last_page: 1,
                    },
                },
                initial,
            );
            browser.registerPage(
                '/settings/appearance',
                'settings/Appearance',
                {},
                initial,
            );
            await browser.evaluate(`(async () => {
            window.appearanceNavigationRouter = document.querySelector('#app').__vue_app__.config.globalProperties.$inertia;
            window.appearanceNavigationDocument = true;
            window.appearanceNavigationRouter.prefetch('/products/7');
        })()`);
            await waitFor(
                `!!window.appearanceNavigationRouter.getCached('/products/7')`,
                'Product details must finish prefetching',
            );
            const productRequests = browser.navigationRequests.filter(
                (path) => path === '/products/7',
            ).length;
            await browser.evaluate(
                `window.appearanceNavigationRouter.visit('/orders')`,
            );
            await waitFor(
                `location.pathname === '/orders' && !!document.querySelector('#order-history-heading')`,
                'Orders must mount',
            );
            await browser.evaluate(
                `document.querySelector('[role=switch][aria-label="Dark mode"]').click()`,
            );
            await waitFor(
                `!!document.querySelector('[data-sonner-toast]')`,
                'Appearance save must finish',
            );
            await assertAppearance(selected);
            await browser.evaluate('history.back()');
            await waitFor(
                `location.pathname === '/__responsive_fixture' && !!document.querySelector('#catalog-heading')`,
                'Back must restore the cached catalog',
            );
            await assertAppearance(selected);
            await browser.evaluate(
                `document.querySelector('a.surface-card[href$="/products/7"]').click()`,
            );
            await waitFor(
                `location.pathname === '/products/7' && !!document.querySelector('form button[type=submit]')`,
                'Prefetched product details must mount',
            );
            await assertAppearance(selected);
            assert.equal(
                browser.navigationRequests.filter(
                    (path) => path === '/products/7',
                ).length,
                productRequests,
                'Details use the response prefetched before the appearance change',
            );
            await browser.evaluate('history.back()');
            await waitFor(
                `location.pathname === '/__responsive_fixture' && !!document.querySelector('#catalog-heading')`,
                'Back must restore the catalog again',
            );
            await assertAppearance(selected);
            await browser.evaluate('history.forward()');
            await waitFor(
                `location.pathname === '/products/7' && !!document.querySelector('form button[type=submit]')`,
                'Forward must restore details',
            );
            await assertAppearance(selected);
            await browser.evaluate(
                `window.appearanceNavigationRouter.visit('/settings/appearance')`,
            );
            await waitFor(
                `location.pathname === '/settings/appearance' && !!document.querySelector('button[aria-pressed="true"]')`,
                'Appearance settings must mount',
            );
            await assertAppearance(selected);
            assert.equal(
                await browser.evaluate(
                    `document.querySelector('button[aria-pressed="true"]').textContent.trim()`,
                ),
                selected === 'dark' ? 'Dark' : 'Light',
            );
            await browser.evaluate('history.go(-2)');
            await waitFor(
                `location.pathname === '/__responsive_fixture' && !!document.querySelector('#catalog-heading')`,
                'Catalog must be current before reloading',
            );
            await browser.command('Page.reload');
            await waitFor(
                `window.appearanceNavigationDocument !== true && !!document.querySelector('#app')?.__vue_app__ && !!document.querySelector('#catalog-heading')`,
                'Reload must mount the catalog using the saved server preference',
            );
            await browser.evaluate(
                'window.appearanceNavigationDocument = true',
            );
            await assertAppearance(selected);
            await browser.evaluate('history.forward()');
            await waitFor(
                `location.pathname === '/products/7' && !!document.querySelector('form button[type=submit]')`,
                'Forward after reload must restore older product details',
            );
            await assertAppearance(selected);
        }
        assert.deepEqual(
            browser.exceptions,
            [],
            'Appearance navigation has no JavaScript exceptions',
        );
    },
);

test(
    'mobile support stays clear of shopping controls and opens an accessible chat panel',
    {
        skip:
            !baseUrl || !executable
                ? 'Set the local app and browser environment variables'
                : false,
        timeout: 120000,
    },
    async (t) => {
        const browser = await openBrowser(t);
        const catalog = {
            products: {
                data: Array.from({ length: 4 }, (_, index) => ({
                    ...product,
                    id: index + 1,
                })),
                total: 4,
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
            filters: { sort: 'featured' },
            filter_options: { categories: [], brands: [], tags: [] },
            recommendations: [],
        };
        for (const theme of ['light', 'dark']) {
            for (const [component, props, customer] of [
                ['Products/Index', catalog, false],
                ['Products/Index', catalog, true],
                [
                    'Products/Show',
                    {
                        product,
                        can_record_product_dwell: false,
                        recommendations: [],
                    },
                    true,
                ],
                ['Cart/Index', { cart }, true],
                ['Checkout/Index', checkout, true],
            ]) {
                await browser.show(component, props, customer, theme);
                for (const width of [320, 390, 768, 1440]) {
                    await browser.resize(width);
                    const dock = await browser.evaluate(`(() => {
                    const element = document.querySelector('[data-customer-chat]');
                    const rect = element.getBoundingClientRect();
                    const button = element.querySelector('button');
                    const target = button.getBoundingClientRect();
                    const label = button.querySelector('span').getBoundingClientRect();
                    return { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom, height: rect.height, clientWidth: document.documentElement.clientWidth,
                        clearance: parseFloat(getComputedStyle(document.body).paddingBottom),
                        target: target.height, targetWidth: target.width, labelWidth: label.width,
                        name: button.textContent.trim(),
                        reachable: document.elementFromPoint(target.left + target.width / 2, target.top + target.height / 2)?.closest('button') === button,
                        shadow: getComputedStyle(button).boxShadow };
                })()`);
                    assert.ok(
                        dock.left >= 0 &&
                            dock.right <= width &&
                            dock.bottom <= 900,
                        `${component} support fits at ${width}`,
                    );
                    assert.ok(dock.target >= 44);
                    assert.equal(dock.name, 'Ask Battlefront');
                    assert.equal(
                        dock.reachable,
                        true,
                        'The support launcher remains clickable',
                    );
                    if (width < 640) {
                        assert.equal(
                            dock.targetWidth,
                            dock.target,
                            'Use a compact circular mobile launcher',
                        );
                        assert.ok(
                            dock.targetWidth <= 48,
                            'Keep the icon button compact',
                        );
                        assert.ok(
                            dock.labelWidth <= 1,
                            'Keep the mobile label available to screen readers without visible text',
                        );
                        assert.equal(dock.left, 0);
                        assert.equal(dock.right, dock.clientWidth);
                        assert.ok(
                            dock.clearance >= dock.height,
                            'The mobile support dock has reserved page clearance',
                        );
                    } else {
                        assert.ok(
                            dock.labelWidth > 1,
                            'Keep the desktop support label visible',
                        );
                        assert.equal(
                            dock.clearance,
                            0,
                            'Desktop pages retain their original bottom spacing',
                        );
                        assert.ok(
                            dock.right - dock.left < 220,
                            'Desktop support retains its compact floating launcher',
                        );
                    }
                    const controls = await browser.evaluate(`(async () => {
                    const dock = document.querySelector('[data-customer-chat]').getBoundingClientRect();
                    const results = [];
                    for (const element of document.querySelectorAll('main input:not([type=hidden]), main [role=combobox], main button:not([disabled]), main h3, main nav a')) {
                        if (!element.getBoundingClientRect().height || element.closest('header, [data-slot=sidebar]')) continue;
                        element.scrollIntoView({ block: 'center' });
                        await new Promise(resolve => requestAnimationFrame(resolve));
                        const rect = element.getBoundingClientRect();
                        const top = Math.max(rect.top, 74);
                        const bottom = Math.min(rect.bottom, innerHeight);
                        const x = rect.left + rect.width / 2;
                        const y = (top + bottom) / 2;
                        const hit = document.elementFromPoint(x, y);
                        const label = element.closest('label') ?? document.querySelector('label[for="' + element.id + '"]');
                        results.push({ text: element.textContent.trim().slice(0, 50) || element.id,
                            clear: !(rect.left < dock.right && rect.right > dock.left && rect.top < dock.bottom && rect.bottom > dock.top),
                            reachable: hit === element || element.contains(hit),
                            target: element.matches('input[type=radio], input[type=checkbox], [role=checkbox]') ? Math.max(rect.height, label?.getBoundingClientRect().height ?? 0) : element.matches('input, [role=combobox], button') ? rect.height : null });
                    }
                    return results;
                })()`);
                    assert.ok(controls.length > 0);
                    assert.deepEqual(
                        controls.filter(
                            (control) => !control.clear || !control.reachable,
                        ),
                        [],
                        `${component} shopping content remains unobstructed at ${width}`,
                    );
                    if (width < 768)
                        assert.deepEqual(
                            controls.filter(
                                (control) =>
                                    control.target !== null &&
                                    control.target < 44,
                            ),
                            [],
                            'Shopping controls retain 44px touch targets',
                        );
                    await assertFits(
                        browser,
                        `${component} support dock ${theme} ${width}`,
                    );
                    if (
                        process.env.BATTLEFRONT_UI_PREVIEW &&
                        component === 'Products/Index' &&
                        customer &&
                        width === 390
                    ) {
                        await browser.evaluate(
                            `(() => { const section = document.querySelector('#product-list-heading').closest('section'); scrollTo(0, section.getBoundingClientRect().top + scrollY - 82); })()`,
                        );
                        const screenshot = await browser.command(
                            'Page.captureScreenshot',
                            { format: 'png', captureBeyondViewport: false },
                        );
                        await writeFile(
                            process.env.BATTLEFRONT_UI_PREVIEW +
                                '-' +
                                theme +
                                '-390.png',
                            Buffer.from(screenshot.data, 'base64'),
                        );
                    }
                }
            }
            await browser.resize(320, 480);
            await browser.evaluate(
                `document.querySelector('[aria-controls="battlefront-chat-panel"]').click()`,
            );
            const panel = await browser.evaluate(`(() => {
            const element = document.querySelector('#battlefront-chat-panel');
            const rect = element.getBoundingClientRect();
            return { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom,
                focused: document.activeElement.id, background: getComputedStyle(element).backgroundColor,
                radius: getComputedStyle(element).borderTopLeftRadius };
        })()`);
            assert.ok(
                panel.left >= 0 &&
                    panel.right <= 320 &&
                    panel.top >= 0 &&
                    panel.bottom <= 480,
                'The chat panel fits a short mobile viewport',
            );
            assert.equal(panel.focused, 'floating-chatbot-message');
            assert.equal(panel.radius, '16px');
            assert.equal(
                panel.background,
                theme === 'dark' ? 'rgb(17, 19, 24)' : 'rgb(255, 255, 255)',
            );
            await browser.evaluate(
                `document.querySelector('#battlefront-chat-panel').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))`,
            );
            assert.equal(
                await browser.evaluate(
                    `document.activeElement.getAttribute('aria-controls')`,
                ),
                'battlefront-chat-panel',
                'Closing support returns keyboard focus to the launcher',
            );
        }
        assert.deepEqual(browser.exceptions, []);
    },
);

test(
    'guest appearance persists across public pages and stays separate from account settings',
    {
        skip:
            !baseUrl || !executable
                ? 'Set the local app and browser environment variables'
                : false,
        timeout: 120000,
    },
    async (t) => {
        const browser = await openBrowser(t);
        const key = 'battlefront_guest_appearance';
        const catalog = {
            products: {
                data: [product],
                total: 1,
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
            filters: { sort: 'featured' },
            filter_options: { categories: [], brands: [], tags: [] },
            recommendations: [],
        };
        const readAppearance = `({ dark: document.documentElement.classList.contains('dark'), checked: document.querySelector('[role=switch][aria-label="Dark mode"]')?.getAttribute('aria-checked'), stored: localStorage.getItem('${key}'), beforeApp: window.appearanceBeforeApp })`;
        async function waitFor(expression, message) {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (await browser.evaluate(expression)) return;
                await delay(50);
            }
            assert.fail(message + '; ' + browser.exceptions.join('; '));
        }
        await browser.command('Emulation.setEmulatedMedia', {
            features: [{ name: 'prefers-color-scheme', value: 'dark' }],
        });
        await browser.evaluate(`localStorage.removeItem('${key}')`);
        await browser.show('Welcome', {}, false, null);
        await waitFor(
            `!!document.querySelector('[role=switch]')`,
            'The guest switch must mount',
        );
        assert.deepEqual(await browser.evaluate(readAppearance), {
            dark: false,
            checked: 'false',
            stored: null,
            beforeApp: false,
        });
        await browser.evaluate(
            `document.querySelector('[role=switch]').focus()`,
        );
        await browser.command('Input.dispatchKeyEvent', {
            type: 'keyDown',
            key: ' ',
            code: 'Space',
            windowsVirtualKeyCode: 32,
        });
        await browser.command('Input.dispatchKeyEvent', {
            type: 'keyUp',
            key: ' ',
            code: 'Space',
            windowsVirtualKeyCode: 32,
        });
        await waitFor(
            `document.querySelector('[role=switch]').getAttribute('aria-checked') === 'true'`,
            'Space must operate the guest switch',
        );
        assert.equal((await browser.evaluate(readAppearance)).stored, 'dark');
        for (const [component, props] of [
            ['Welcome', {}],
            ['Products/Index', catalog],
            [
                'Products/Show',
                {
                    product,
                    can_record_product_dwell: false,
                    recommendations: [],
                },
            ],
            ['Branches/Index', { branches: [] }],
            ['auth/Login', { canResetPassword: true }],
            ['auth/Register', { passwordRules: 'min:8' }],
        ]) {
            await browser.show(component, props, false, null);
            await waitFor(
                `!!document.querySelector('[role=switch]')`,
                component + ' must expose the guest switch',
            );
            assert.deepEqual(
                await browser.evaluate(readAppearance),
                {
                    dark: true,
                    checked: 'true',
                    stored: 'dark',
                    beforeApp: true,
                },
                component + ' restores Dark before application code',
            );
            for (const width of [320, 375, 390, 768, 1440]) {
                await browser.resize(width);
                await assertFits(
                    browser,
                    component + ' guest appearance ' + width,
                );
                const bounds = await browser.evaluate(`(() => {
                const control = document.querySelector('[role=switch]');
                const rect = control.getBoundingClientRect();
                return { left: rect.left, right: rect.right, height: rect.height, width: rect.width, viewport: document.documentElement.clientWidth };
            })()`);
                assert.ok(
                    bounds.left >= 0 && bounds.right <= bounds.viewport,
                    component + ' switch stays visible at ' + width,
                );
                assert.ok(
                    bounds.width >= 44 &&
                        bounds.height >= (width < 768 ? 44 : 36),
                    'The switch retains accessible target dimensions',
                );
                if (
                    process.env.BATTLEFRONT_GUEST_PREVIEW &&
                    ['Welcome', 'auth/Login', 'auth/Register'].includes(
                        component,
                    ) &&
                    [320, 1440].includes(width)
                ) {
                    const screenshot = await browser.command(
                        'Page.captureScreenshot',
                        { format: 'png', captureBeyondViewport: false },
                    );
                    await writeFile(
                        process.env.BATTLEFRONT_GUEST_PREVIEW +
                            '-' +
                            component.replaceAll('/', '-') +
                            '-' +
                            width +
                            '.png',
                        Buffer.from(screenshot.data, 'base64'),
                    );
                }
            }
        }
        assert.deepEqual(
            browser.appearanceRequests,
            [],
            'Guests never call the account preference endpoint',
        );
        await browser.show('settings/Appearance', {}, true, 'light');
        assert.deepEqual(
            await browser.evaluate(readAppearance),
            { dark: false, checked: 'false', stored: 'dark', beforeApp: false },
            'Account Light wins over stored guest Dark before mounting',
        );
        await browser.evaluate(
            `document.querySelector('[role=switch]').click()`,
        );
        await waitFor(
            `!!document.querySelector('[data-sonner-toast]')`,
            'Account Dark must save',
        );
        assert.equal((await browser.evaluate(readAppearance)).dark, true);
        await browser.evaluate(
            `document.querySelector('[role=switch]').click()`,
        );
        await waitFor(
            `document.querySelector('[role=switch]').getAttribute('aria-checked') === 'false' && document.querySelectorAll('[data-sonner-toast]').length >= 2`,
            'Account Light must save',
        );
        assert.equal(
            (await browser.evaluate(readAppearance)).stored,
            'dark',
            'Account saves never overwrite guest storage',
        );
        assert.deepEqual(browser.appearanceRequests, [
            { appearance: 'dark' },
            { appearance: 'light' },
        ]);
        browser.registerPage(
            '/__guest_appearance_logout',
            'Products/Index',
            catalog,
            'light',
            null,
        );
        await browser.evaluate(
            `document.querySelector('#app').__vue_app__.config.globalProperties.$inertia.visit('/__guest_appearance_logout')`,
        );
        await waitFor(
            `location.pathname === '/__guest_appearance_logout' && !!document.querySelector('header nav [role=switch]')`,
            'Logout must expose the guest header',
        );
        assert.equal(
            (await browser.evaluate(readAppearance)).dark,
            true,
            'Logout restores the prior guest preference',
        );
        const auth = {
            user: {
                id: 999998,
                name: 'Appearance Account',
                email: 'appearance@example.test',
                role: 'customer',
            },
            can: { useCustomerCart: true, accessAdministration: false },
        };
        browser.registerPage(
            '/__guest_appearance_login',
            'settings/Appearance',
            {},
            'light',
            auth,
        );
        await browser.evaluate(
            `document.querySelector('#app').__vue_app__.config.globalProperties.$inertia.visit('/__guest_appearance_login')`,
        );
        await waitFor(
            `location.pathname === '/__guest_appearance_login' && !!document.querySelector('button[aria-pressed="true"]')`,
            'Login must mount account settings',
        );
        assert.equal(
            (await browser.evaluate(readAppearance)).dark,
            false,
            'Login reapplies the account preference',
        );
        assert.equal((await browser.evaluate(readAppearance)).stored, 'dark');
        assert.deepEqual(browser.exceptions, []);
    },
);
