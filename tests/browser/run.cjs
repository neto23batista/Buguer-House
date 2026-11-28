'use strict';
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { randomBytes } = require('node:crypto');
const { spawn, spawnSync } = require('node:child_process');

const dsn = process.env.DB_TEST_DSN ?? '';
const name = dsn.match(/(?:^mysql:|;)dbname=([a-zA-Z0-9_]+)(?:;|$)/)?.[1];
const host = dsn.match(/(?:^mysql:|;)host=([^;]+)(?:;|$)/)?.[1];
const port = dsn.match(/(?:^mysql:|;)port=(\d+)(?:;|$)/)?.[1] ?? '3306';
if (!dsn.startsWith('mysql:') || !name || !(name.endsWith('_test') || name.startsWith('bh_test_'))
    || !['localhost', '127.0.0.1', '::1'].includes(host)) {
    throw new Error('E2E requer DB_TEST_DSN MySQL local com banco dedicado terminado em _test ou iniciado por bh_test_.');
}

const root = path.resolve(__dirname, '../..');
const temporaryRoot = path.resolve(os.tmpdir());
const directory = path.resolve(fs.mkdtempSync(path.join(temporaryRoot, 'burger-e2e-')));
if (path.dirname(directory) !== temporaryRoot || !path.basename(directory).startsWith('burger-e2e-')) {
    throw new Error('Diretório temporário E2E inesperado.');
}
const statePath = path.join(directory, 'state.json');
const env = { ...process.env, APP_ENV: 'test', APP_DEMO: '1', VERCEL: '0',
    DB_HOST: host, DB_PORT: port, DB_NAME: name,
    DB_USER: process.env.DB_TEST_USER ?? 'root', DB_PASSWORD: process.env.DB_TEST_PASSWORD ?? '',
    DB_SSL_REQUIRED: '0', DB_SSL_CA: '', DB_SSL_CA_BASE64: '',
    SESSION_DRIVER: 'mysql', SESSION_COOKIE_SECURE: '0', BH_BROWSER_STATE: statePath };
const php = process.env.PHP_BINARY ?? 'php';
let server;
let exitCode = 1;
let serverErrors = '';
function prepare(args = []) {
    const result = spawnSync(php, ['tests/browser/prepare.php', ...args], { cwd: root, env, stdio: 'inherit', windowsHide: true });
    if (result.status !== 0) throw new Error('Falha ao preparar/restaurar o banco E2E.');
}
async function availablePort() {
    const listener = net.createServer();
    await new Promise((resolve, reject) => { listener.once('error', reject); listener.listen(0, '127.0.0.1', resolve); });
    const assigned = listener.address().port;
    await new Promise(resolve => listener.close(resolve));
    return assigned;
}

(async () => {
    try {
        env.BH_BROWSER_PASSWORD = randomBytes(24).toString('hex');
        env.ADMIN_USER = 'e2e-' + randomBytes(8).toString('hex');
        const hash = spawnSync(php, ['-r', 'echo password_hash(getenv("BH_BROWSER_PASSWORD"), PASSWORD_DEFAULT);'],
            { env, encoding: 'utf8', windowsHide: true });
        if (hash.status !== 0) throw new Error('Falha ao preparar a conta de teste.');
        env.ADMIN_PASSWORD_HASH = hash.stdout;
        env.STORE_CONTACT_PHONE = '11900000000';
        prepare();
        const state = JSON.parse(fs.readFileSync(statePath, 'utf8'));
        const localPort = await availablePort();
        env.BH_BROWSER_BASE_URL = `http://127.0.0.1:${localPort}`;
        env.BH_BROWSER_PRODUCT_ID = String(state.productId);
        server = spawn(php, ['-S', `127.0.0.1:${localPort}`, '-t', 'public'], { cwd: root, env, windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
        server.stderr.on('data', data => { serverErrors = (serverErrors + data).slice(-20000); });
        server.on('error', error => { serverErrors += error.message; });
        let ready = false;
        for (let attempt = 0; attempt < 60; attempt++) {
            try { if ((await fetch(env.BH_BROWSER_BASE_URL + '/health.php')).ok) { ready = true; break; } } catch {}
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        if (!ready) throw new Error('O servidor PHP local não iniciou.');
        const child = spawn(process.execPath, [require.resolve('@playwright/test/cli'), 'test', '--config', 'playwright.config.cjs'], { cwd: root, env, windowsHide: true, stdio: 'inherit' });
        exitCode = await new Promise((resolve, reject) => { child.once('error', reject); child.once('exit', code => resolve(code ?? 1)); });
        if (/PHP Fatal|PHP Warning|Uncaught/.test(serverErrors)) throw new Error('O servidor PHP registrou erro durante o E2E.');
    } finally {
        if (server) {
            const stopped = new Promise(resolve => server.once('exit', resolve));
            if (server.exitCode === null) { server.kill(); await stopped; }
        }
        if (fs.existsSync(statePath)) prepare(['--cleanup']);
        fs.rmSync(directory, { recursive: true, force: true });
    }
})().then(() => { process.exitCode = exitCode; }).catch(error => { console.error(error.message); process.exitCode = 1; });
