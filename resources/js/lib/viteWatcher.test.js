import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { basename, dirname, join, resolve } from 'node:path';
import { setTimeout } from 'node:timers/promises';
import test from 'node:test';
import { createServer, loadConfigFromFile } from '@voidzero-dev/vite-plus-core';

test('Vite watches application sources without watching generated storage and cache files', async () => {
    const { config } = await loadConfigFromFile({ command: 'serve', mode: 'development' });
    const root = await mkdtemp(join(tmpdir(), 'battlefront-vite-watch-'));
    let server;
    try {
        for (const directory of ['resources/js', 'storage/framework/testing', 'public/storage', 'bootstrap/cache']) {
            await mkdir(join(root, directory), { recursive: true });
            await writeFile(join(root, directory, 'example.js'), 'export default 1;');
        }
        server = await createServer({
            configFile: false,
            root,
            logLevel: 'silent',
            server: { middlewareMode: true, hmr: false, watch: config.server.watch },
            optimizeDeps: { noDiscovery: true, include: [] },
        });
        const sourceDirectory = join(root, 'resources/js').replaceAll('\\', '/');
        const deadline = Date.now() + 5000;
        let watched;
        do {
            watched = Object.entries(server.watcher.getWatched());
            if (watched.some(([directory, files]) => directory.replaceAll('\\', '/') === sourceDirectory && files.includes('example.js'))) break;
            await setTimeout(10);
        } while (Date.now() < deadline);
        assert.ok(watched.some(([directory, files]) => directory.replaceAll('\\', '/') === sourceDirectory && files.includes('example.js')));
        const paths = watched.flatMap(([directory, files]) => [directory, ...files.map(file => join(directory, file))]).map(path => path.replaceAll('\\', '/'));
        assert.equal(paths.some(path => /\/(?:storage|bootstrap\/cache)(?:\/|$)/.test(path)), false);
    } finally {
        if (server) await server.close();
        const absoluteRoot = resolve(root);
        assert.equal(dirname(absoluteRoot), resolve(tmpdir()));
        assert.ok(basename(absoluteRoot).startsWith('battlefront-vite-watch-'));
        await rm(absoluteRoot, { recursive: true, force: true });
    }
});
