// @ts-check
const { test, expect } = require('@playwright/test')
const fs = require('fs')
const os = require('os')
const path = require('path')

const USER = process.env.PW_USER || 'editor'
const PASS = process.env.PW_PASS || 'e2e-test-pass'
// FrontPage は frozen のため、リンク追記の検証は非凍結ページで行う
const PARENT_PAGE = '議事録'
const LEAF_NAME = 'PwDropMdTest'
const FULL_PAGE = `${PARENT_PAGE}/${LEAF_NAME}`
const MD_BASENAME = `${LEAF_NAME}.md`

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
	if (page.url().includes('changepassword')) {
		throw new Error('Password change required; set a non-default PW_PASS for e2e')
	}
	await page.goto('/?' + encodeURIComponent(PARENT_PAGE))
	await expect(page.locator('h1.title, .title').first()).toContainText(PARENT_PAGE)
}

function wikiPathForPage(pageName) {
	return path.join(__dirname, '..', 'pukiwiki', 'wiki', ...pageName.split('/')) + '.md'
}

function stripParentLink() {
	const parentFile = wikiPathForPage(PARENT_PAGE)
	if (!fs.existsSync(parentFile)) return
	let src = fs.readFileSync(parentFile, 'utf8')
	const next = src
		.replace(new RegExp(`\\n?\\[\\[${FULL_PAGE.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\]\\]\\n?`), '\n')
		.replace(/\n{3,}/g, '\n\n')
	if (next !== src) {
		fs.writeFileSync(parentFile, next, 'utf8')
	}
}

test.describe('MD drop creates child wiki page', () => {
	/** @type {string} */
	let tmpMd

	test.beforeAll(() => {
		tmpMd = path.join(os.tmpdir(), MD_BASENAME)
		fs.writeFileSync(tmpMd, `# ${LEAF_NAME}\n\ncreated by playwright drop\n`, 'utf8')
		const wikiFile = wikiPathForPage(FULL_PAGE)
		if (fs.existsSync(wikiFile)) {
			fs.unlinkSync(wikiFile)
		}
		stripParentLink()
	})

	test.afterAll(() => {
		try {
			fs.unlinkSync(tmpMd)
		} catch (_) {}
		const wikiFile = wikiPathForPage(FULL_PAGE)
		try {
			fs.unlinkSync(wikiFile)
		} catch (_) {}
		stripParentLink()
	})

	test('login → drop .md on parent → create child and append [[child]] link', async ({ page }) => {
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
			(url) =>
				url.href.includes(LEAF_NAME) ||
				url.href.includes(encodeURIComponent(FULL_PAGE)) ||
				url.href.includes(encodeURIComponent(LEAF_NAME)),
			{ timeout: 20_000 }
		)

		const statusText = await page.locator('.pkwk-page-dd-status').textContent().catch(() => '')
		expect(statusText || '', 'status should not show parse/cache errors').not.toMatch(
			/解析に失敗|キャッシュ|ログインが必要/
		)

		await expect(page.locator('body')).toContainText('created by playwright drop')
		await expect(page.locator('h1.title, .title').first()).toContainText(FULL_PAGE)
		expect(fs.existsSync(wikiPathForPage(FULL_PAGE))).toBeTruthy()

		const parentSrc = fs.readFileSync(wikiPathForPage(PARENT_PAGE), 'utf8')
		expect(parentSrc).toContain(`[[${FULL_PAGE}]]`)

		if (postBodies.length > 0) {
			const json = JSON.parse(postBodies[postBodies.length - 1])
			expect(json.ok).toBeTruthy()
			expect(json.page).toBe(FULL_PAGE)
			expect(json.parent_link && json.parent_link.ok).toBeTruthy()
		}
	})
})
