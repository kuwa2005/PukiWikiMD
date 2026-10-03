// @ts-check
const { defineConfig } = require('@playwright/test')

module.exports = defineConfig({
	testDir: 'e2e',
	timeout: 60_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	retries: 0,
	workers: 1,
	reporter: [['list']],
	use: {
		baseURL: process.env.PW_BASE_URL || 'http://127.0.0.1',
		headless: true,
		trace: 'on-first-retry',
	},
	projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
})
