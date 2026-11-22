'use strict';
const { defineConfig, devices } = require('@playwright/test');

if (!process.env.BH_BROWSER_BASE_URL?.startsWith('http://127.0.0.1:')) {
    throw new Error('Execute npm run test:e2e; o runner inicia um servidor local isolado.');
}

module.exports = defineConfig({
    testDir: './tests/browser',
    testMatch: '*.spec.cjs',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 20000,
    reporter: 'list',
    use: { baseURL: process.env.BH_BROWSER_BASE_URL, headless: true, trace: 'retain-on-failure' },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'mobile', use: { ...devices['Pixel 5'] } },
    ],
});
