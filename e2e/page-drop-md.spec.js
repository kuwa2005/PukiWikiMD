// @ts-check
const { test, expect } = require('@playwright/test')
const fs = require('fs')
const os = require('os')
const path = require('path')

const USER = process.env.PW_USER || 'editor'
const PASS = process.env.PW_PASS || 'e2e-test-pass'
const PAGE_NAME = 'PwDropMdTest'
const MD_BASENAME = `${PAGE_NAME}.md`

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} filePath
 * @param {string} fileName
 */
async function dropMarkdownFile(page, filePath, fileName) {
	const buffer = fs.readFileSync(filePath).toString('base64')
	await page.evaluate(
		async ({ bufferB64, fileName: name }) => {
			const binary = atob(bufferB64)
			const bytes = new Uint8Array(binary.length)
			for (let i = 0; i < binary.length; i++) {
				bytes[i] = binary.charCodeAt(i)
			}
			const file = new File([bytes], name, { type: 'text/markdown' })
			const dt = new DataTransfer()
			dt.items.add(file)

			const target = document.documentElement
			for (const type of ['dragenter', 'dragover', 'drop']) {
				const ev = new DragEvent(type, {
					bubbles: true,
					cancelable: true,
					dataTransfer: dt,
				})
				target.dispatchEvent(ev)
			}
		},
		{ bufferB64: buffer, fileName }
	)
}

async function login(page) {
	await page.goto('/?plugin=loginform')
	await page.locator('#_plugin_loginform_username, input[name="username"]').fill(USER)
	await page.locator('#_plugin_loginform_password, input[name="password"]').fill(PASS)
	await Promise.all([
		page.waitForURL((url) => !url.href.includes('plugin=loginform'), { timeout: 20_000 }),
		page.locator('input[type="submit"].loginbutton, input[type="submit"]').first().click(),
	])
	// 初期パスワード変更が挟まる場合はスキップ不可なので、変更済み前提
	if (page.url().includes('changepassword')) {
		throw new Error('Password change required; set a non-default PW_PASS for e2e')
	}
	await page.goto('/')
	await expect(page.locator('h1.title, .title').first()).toBeVisible()
}

test.describe('MD drop creates wiki page', () => {
	/** @type {string} */
	let tmpMd

	test.beforeAll(() => {
		tmpMd = path.join(os.tmpdir(), MD_BASENAME)
		fs.writeFileSync(tmpMd, `# ${PAGE_NAME}\n\ncreated by playwright drop\n`, 'utf8')
		const wikiFile = path.join(__dirname, '..', 'pukiwiki', 'wiki', MD_BASENAME)
		if (fs.existsSync(wikiFile)) {
			fs.unlinkSync(wikiFile)
		}
	})

	test.afterAll(() => {
		try {
			fs.unlinkSync(tmpMd)
		} catch (_) {}
		const wikiFile = path.join(__dirname, '..', 'pukiwiki', 'wiki', MD_BASENAME)
		try {
			fs.unlinkSync(wikiFile)
		} catch (_) {}
	})

	test('login → drop .md → navigate to new page', async ({ page }) => {
		await login(page)

		/** @type {string[]} */
		const postBodies = []
		page.on('response', async (res) => {
			if (res.request().method() !== 'POST') return
			if (!(res.url().endsWith('/') || res.url().includes('index.php'))) return
			try {
				postBodies.push(await res.text())
			} catch (_) {
				// 成功時は location 遷移で body が取れないことがある
			}
		})

		await dropMarkdownFile(page, tmpMd, MD_BASENAME)

		await page.waitForURL(
			(url) => url.href.includes(PAGE_NAME) || url.href.includes(encodeURIComponent(PAGE_NAME)),
			{ timeout: 20_000 }
		)

		const statusText = await page.locator('.pkwk-page-dd-status').textContent().catch(() => '')
		expect(statusText || '', 'status should not show parse/cache errors').not.toMatch(
			/解析に失敗|キャッシュ|ログインが必要/
		)

		await expect(page.locator('body')).toContainText('created by playwright drop')
		expect(fs.existsSync(path.join(__dirname, '..', 'pukiwiki', 'wiki', MD_BASENAME))).toBeTruthy()

		if (postBodies.length > 0) {
			const json = JSON.parse(postBodies[postBodies.length - 1])
			expect(json.ok).toBeTruthy()
			expect(json.page).toBe(PAGE_NAME)
		}
	})
})
