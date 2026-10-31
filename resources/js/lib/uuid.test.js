import assert from 'node:assert/strict';
import test from 'node:test';
import { createUuid } from './uuid.js';

test('creates a valid version four UUID when randomUUID is unavailable', () => {
    const randomSource = {
        getRandomValues(bytes) {
            bytes.fill(0);

            return bytes;
        },
    };

    const uuid = createUuid(randomSource);

    assert.match(
        uuid,
        /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    );
});
