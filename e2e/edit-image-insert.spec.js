// @ts-check
const { test, expect } = require('@playwright/test')
const fs = require('fs')
const os = require('os')
const path = require('path')

const USER = process.env.PW_USER || 'editor'
const PASS = process.env.PW_PASS || 'e2e-test-pass'
const PAGE = 'SandBox'
const NEW_PAGE = '議事録/PwImgInsertNew'

/** Minimal 1x1 PNG */
function tinyPngBuffer() {
	return Buffer.from(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
		'base64'
	)
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function login(page) {
	await page.goto('/?plugin=loginform')
	await page.locator('#_plugin_loginform_username, input[name="username"]').fill(USER)
	await page.locator('#_plugin_loginform_password, input[name="password"]').fill(PASS)
	await Promise.all([
		page.waitForURL((url) => !url.href.includes('plugin=loginform') || url.href.includes('changepassword'), {
			timeout: 20_000,
		}),
		page.locator('input[type="submit"].loginbutton, input[type="submit"]').first().click(),
	])
	if (page.url().includes('changepassword')) {
		throw new Error('Password change required; set a non-default PW_PASS for e2e')
	}
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} pageName
 */
async function openEdit(page, pageName) {
	await page.goto('/?cmd=edit&page=' + encodeURIComponent(pageName))
	const textarea = page.locator('.edit_form form._plugin_edit_edit_form textarea[name="msg"]')
	await expect(textarea).toBeVisible({ timeout: 15_000 })
	return textarea
}

test.describe('Edit mode image paste / drop inserts #ref', () => {
	test('Ctrl+V paste uploads and inserts #ref(...,middle) at cursor', async ({ page }) => {
		await login(page)
		const textarea = await openEdit(page, PAGE)

		await textarea.click()
		await textarea.evaluate((el) => {
			el.value = 'BEFORE\n\nAFTER\n'
			el.selectionStart = 'BEFORE\n'.length
			el.selectionEnd = 'BEFORE\n'.length
			el.focus()
		})

		const png = tinyPngBuffer()
		await page.evaluate(async (b64) => {
			const binary = atob(b64)
			const bytes = new Uint8Array(binary.length)
			for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
			const blob = new Blob([bytes], { type: 'image/png' })
			const ta = document.querySelector(
				'.edit_form form._plugin_edit_edit_form textarea[name="msg"]'
			)
			if (!ta) throw new Error('textarea missing')

			const dt = new DataTransfer()
			dt.items.add(new File([blob], 'clip.png', { type: 'image/png' }))

			const ev = new ClipboardEvent('paste', {
				bubbles: true,
				cancelable: true,
				clipboardData: dt,
			})
			// Some browsers need items via DataTransfer on clipboardData
			Object.defineProperty(ev, 'clipboardData', { value: dt })
			ta.dispatchEvent(ev)
		}, png.toString('base64'))

		await expect
			.poll(async () => textarea.inputValue(), { timeout: 20_000 })
			.toMatch(/#ref\(paste-\d{8}-\d{6}\.png,middle\)/)

		const val = await textarea.inputValue()
		expect(val).toContain('BEFORE\n')
		expect(val).toContain('AFTER\n')
		const refLine = val.match(/#ref\(paste-\d{8}-\d{6}\.png,middle\)/)?.[0]
		expect(val.indexOf(refLine)).toBeGreaterThan(val.indexOf('BEFORE'))
		expect(val.indexOf(refLine)).toBeLessThan(val.indexOf('AFTER'))
	})

	test('file drop uploads and inserts #ref at cursor', async ({ page }) => {
		await login(page)
		const textarea = await openEdit(page, PAGE)

		const tmp = path.join(os.tmpdir(), 'e2e-drop-' + Date.now() + '.png')
		fs.writeFileSync(tmp, tinyPngBuffer())
		const dropName = path.basename(tmp)

		await textarea.evaluate((el) => {
			el.value = 'L1\n\nL3\n'
			el.selectionStart = 'L1\n'.length
			el.selectionEnd = 'L1\n'.length
			el.focus()
		})

		const buffer = fs.readFileSync(tmp).toString('base64')
		await page.evaluate(
			async ({ bufferB64, fileName }) => {
				const binary = atob(bufferB64)
				const bytes = new Uint8Array(binary.length)
				for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
				const file = new File([bytes], fileName, { type: 'image/png' })
				const dt = new DataTransfer()
				dt.items.add(file)
				const ta = document.querySelector(
					'.edit_form form._plugin_edit_edit_form textarea[name="msg"]'
				)
				if (!ta) throw new Error('textarea missing')
				for (const type of ['dragenter', 'dragover', 'drop']) {
					ta.dispatchEvent(
						new DragEvent(type, { bubbles: true, cancelable: true, dataTransfer: dt })
					)
				}
			},
			{ bufferB64: buffer, fileName: dropName }
		)

		await expect
			.poll(async () => textarea.inputValue(), { timeout: 20_000 })
			.toContain('#ref(' + dropName + ',middle)')

		const val = await textarea.inputValue()
		expect(val.indexOf('#ref(' + dropName + ',middle)')).toBeGreaterThan(val.indexOf('L1'))
		expect(val.indexOf('#ref(' + dropName + ',middle)')).toBeLessThan(val.indexOf('L3'))

		fs.unlinkSync(tmp)
	})

	test('new unsaved page can attach via paste', async ({ page }) => {
		await login(page)
		const textarea = await openEdit(page, NEW_PAGE)

		await textarea.evaluate((el) => {
			el.value = 'draft\n'
			el.selectionStart = el.value.length
			el.selectionEnd = el.value.length
			el.focus()
		})

		const png = tinyPngBuffer()
		await page.evaluate(async (b64) => {
			const binary = atob(b64)
			const bytes = new Uint8Array(binary.length)
			for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
			const blob = new Blob([bytes], { type: 'image/png' })
			const dt = new DataTransfer()
			dt.items.add(new File([blob], 'clip.png', { type: 'image/png' }))
			const ta = document.querySelector(
				'.edit_form form._plugin_edit_edit_form textarea[name="msg"]'
			)
			const ev = new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: dt })
			Object.defineProperty(ev, 'clipboardData', { value: dt })
			ta.dispatchEvent(ev)
		}, png.toString('base64'))

		await expect
			.poll(async () => textarea.inputValue(), { timeout: 20_000 })
			.toMatch(/#ref\(paste-\d{8}-\d{6}\.png,middle\)/)
	})
})
