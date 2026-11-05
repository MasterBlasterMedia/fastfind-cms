import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import path from 'node:path';

let ownerCookies;
async function login(page, role = 'owner') {
  await page.goto('/studio');
  await page.getByLabel('Email address', { exact: true }).fill(`${role}@fastfind.test`);
  await page.getByLabel('Password', { exact: true }).fill('FastFind-e2e-only-2026');
  await page.getByRole('button', { name: /Sign in to studio/ }).click();
  await expect(page.getByRole('navigation', { name: 'Studio navigation' })).toBeVisible();
}
async function api(page, method, url, data) {
  return page.evaluate(async ({ method, url, data }) => {
    const response = await fetch(`/api/v1${url}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: data === undefined ? undefined : JSON.stringify(data) });
    return { status: response.status, data: await response.json() };
  }, { method, url, data });
}
async function create(page, title) {
  await page.goto('/studio/pages');
  await page.getByLabel('New page title').fill(title);
  await page.getByRole('button', { name: 'Create page ↗' }).click();
  await expect(page.getByLabel('Page title', { exact: true })).toHaveValue(title);
  await page.getByRole('button', { name: 'Delete block', exact: true }).click();
  await expect(page.locator('.canvas-block')).toHaveCount(0);
  return Number(page.url().split('/').at(-1));
}
async function saved(page) {
  await page.getByRole('button', { name: 'Save draft', exact: true }).click();
  await expect(page.locator('.editor-status')).toContainText('All changes saved');
}
async function dragAcrossCanvas(page, source, target) {
  // Start the native drag before scrolling a tall canvas to its destination.
  await source.scrollIntoViewIfNeeded();
  const start = await source.boundingBox();
  expect(start).not.toBeNull();
  const x = start.x + start.width / 2, y = start.y + start.height / 2;
  await page.mouse.move(x, y); await page.mouse.down();
  await page.mouse.move(x + 8, y + 8, { steps: 3 });
  await target.scrollIntoViewIfNeeded();
  const end = await target.boundingBox();
  expect(end).not.toBeNull();
  await page.mouse.move(end.x + end.width / 2, end.y + end.height / 2, { steps: 6 });
  await page.mouse.move(end.x + end.width / 2 + 1, end.y + end.height / 2 + 1);
  await page.mouse.up();
}
test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage(); await login(page); ownerCookies = await page.context().cookies(); await page.close();
});
test.beforeEach(async ({ context }) => { await context.addCookies(ownerCookies); });

test('editor saves, reloads, publishes and keeps subsequent drafts private', async ({ page }) => {
  const id = await create(page, 'Browser publishing proof');
  await page.getByRole('button', { name: 'Add Paragraph block', exact: true }).click();
  await page.getByLabel('Paragraph text', { exact: true }).fill('Published browser content');
  await saved(page);
  await page.reload();
  await expect(page.locator('.canvas-block-preview')).toContainText('Published browser content');
  await page.getByRole('button', { name: 'Publish', exact: true }).click();
  await expect(page.locator('.editor-status .state-pill')).toHaveText('published');
  const publicPage = await api(page, 'GET', '/pages/browser-publishing-proof');
  expect(publicPage.status).toBe(200);
  expect(JSON.stringify(publicPage.data)).toContain('Published browser content');
  await page.getByRole('button', { name: 'Edit Paragraph block', exact: true }).click();
  await page.getByLabel('Paragraph text', { exact: true }).fill('Private newer draft');
  await saved(page);
  expect(JSON.stringify((await api(page, 'GET', '/pages/browser-publishing-proof')).data)).not.toContain('Private newer draft');
  await page.getByRole('button', { name: 'History', exact: true }).click();
  await expect(page.getByRole('dialog')).toContainText('Version');
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog')).not.toBeVisible();
  await expect(page.getByRole('button', { name: 'History', exact: true })).toBeFocused();
  expect((await api(page, 'GET', `/studio/pages/${id}`)).status).toBe(200);
  await page.getByRole('button', { name: 'History', exact: true }).click();
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Restore version 2', exact: true }).click();
  await expect(page.locator('.canvas-block-preview')).toContainText('Published browser content');
  await expect(page.getByRole('dialog')).not.toBeVisible();
  await page.getByRole('button', { name: 'Unpublish', exact: true }).click();
  await expect(page.locator('.editor-status .state-pill')).toHaveText('draft');
  expect((await api(page, 'GET', '/pages/browser-publishing-proof')).status).toBe(404);
});

test('edits during save survive, failed saves retain input, stale versions cannot overwrite', async ({ page }) => {
  const id = await create(page, 'Concurrent browser edits');
  let release; let arrived;
  const gate = new Promise(resolve => { release = resolve; });
  const received = new Promise(resolve => { arrived = resolve; });
  await page.route(`**/api/v1/studio/pages/${id}`, async route => {
    if (route.request().method() !== 'PUT') return route.continue();
    const response = await route.fetch(); arrived(); await gate; await route.fulfill({ response });
  });
  await page.getByLabel('Page title', { exact: true }).fill('Saved first title');
  await page.getByRole('button', { name: 'Save draft', exact: true }).click();
  await received;
  await page.getByLabel('Page title', { exact: true }).fill('Newer unsaved title');
  release();
  await expect(page.locator('.toast')).toContainText('more recent edits');
  await expect(page.getByLabel('Page title', { exact: true })).toHaveValue('Newer unsaved title');
  await page.unroute(`**/api/v1/studio/pages/${id}`);
  await page.route(`**/api/v1/studio/pages/${id}`, route => route.request().method() === 'PUT'
    ? route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Deliberate service failure' }) }) : route.continue());
  await page.getByRole('button', { name: 'Save draft', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('Deliberate service failure');
  await expect(page.getByLabel('Page title', { exact: true })).toHaveValue('Newer unsaved title');
  await page.unroute(`**/api/v1/studio/pages/${id}`);
  await saved(page);
  const current = (await api(page, 'GET', `/studio/pages/${id}`)).data.page;
  expect((await api(page, 'PUT', `/studio/pages/${id}`, { ...current, title: 'Changed in another session' })).status).toBe(200);
  await page.getByLabel('Page title', { exact: true }).fill('Stale local title');
  await page.getByRole('button', { name: 'Save draft', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText(/changed|version|another/i);
  expect((await api(page, 'GET', `/studio/pages/${id}`)).data.page.title).toBe('Changed in another session');
});

test('reorder, cross-section movement, compatibility, duplication and undo work', async ({ page }) => {
  await create(page, 'Movement proof');
  await page.getByRole('button', { name: 'Add Paragraph block', exact: true }).click();
  await page.getByLabel('Paragraph text', { exact: true }).fill('Moving block');
  await page.getByRole('button', { name: 'Duplicate block', exact: true }).click();
  await expect(page.locator('.canvas-block')).toHaveCount(2);
  const initialIds = await page.locator('.canvas-block').evaluateAll(nodes => nodes.map(node => node.dataset.blockId));
  expect(new Set(initialIds).size).toBe(2);
  await page.getByRole('button', { name: 'Move block up', exact: true }).nth(1).click();
  await expect(page.locator('.canvas-block').first()).toHaveAttribute('data-block-id', initialIds[1]);
  await page.getByRole('button', { name: 'Undo', exact: true }).click();
  await expect(page.locator('.canvas-block').first()).toHaveAttribute('data-block-id', initialIds[0]);
  await page.getByRole('button', { name: 'Redo', exact: true }).click();
  await page.locator('.layout-choice').filter({ hasText: 'Two columns' }).click();
  await page.getByRole('button', { name: 'Edit Paragraph block', exact: true }).first().click();
  await page.getByLabel('Move to section').selectOption('1');
  await expect(page.locator('.canvas-section').nth(1).locator('.canvas-block')).toHaveCount(1);
  await dragAcrossCanvas(page, page.locator('.canvas-section').nth(1).locator('.block-drag'), page.locator('.canvas-section').first().locator('.section-end'));
  await expect(page.locator('.canvas-section').first().locator('.canvas-block')).toHaveCount(2);
  await dragAcrossCanvas(page, page.locator('.canvas-section').first().locator('.block-drag').last(), page.locator('.canvas-section').nth(1).locator('.section-end'));
  await expect(page.locator('.canvas-section').nth(1).locator('.canvas-block')).toHaveCount(1);
  await page.locator('.layout-choice').filter({ hasText: 'Accordion' }).click();
  await expect(page.getByRole('button', { name: 'Add Paragraph block', exact: true })).toBeDisabled();
  await dragAcrossCanvas(page, page.locator('.canvas-section').first().locator('.drag-handle'), page.locator('.canvas-section').nth(1).locator('.section-toolbar'));
  await expect(page.locator('.canvas-section').first()).toHaveAttribute('data-layout', 'two-columns');
  await saved(page);
  await page.reload();
  await expect(page.locator('.canvas-section').first()).toHaveAttribute('data-layout', 'two-columns');
});

test('SEO uses plugin lifecycle, generic editor fields, head, settings, redirects and sitemap', async ({ page }) => {
  await page.goto('/studio/plugins');
  const card = page.locator('.plugin-card').filter({ has: page.getByRole('heading', { name: 'FastFind SEO' }) });
  await card.getByRole('button', { name: 'Install', exact: true }).click();
  await card.getByRole('button', { name: 'Activate', exact: true }).click();
  await expect(card.getByRole('button', { name: 'Deactivate', exact: true })).toBeVisible();
  await create(page, 'Plugin browser page');
  await page.getByRole('button', { name: 'Page', exact: true }).click();
  await page.getByLabel('Search title', { exact: true }).fill('Plugin search title');
  await page.getByLabel('Meta description', { exact: true }).fill('Plugin browser description');
  await page.getByRole('button', { name: 'Publish', exact: true }).click();
  await expect(page.locator('.editor-status .state-pill')).toHaveText('published');
  await page.goto('/p/plugin-browser-page');
  await expect(page).toHaveTitle('Plugin search title | FastFind');
  await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', 'Plugin browser description');
  const raw = await page.request.get('/p/plugin-browser-page');
  expect(await raw.text()).toContain('Plugin search title | FastFind');
  await page.goto('/studio/extensions/fastfind/seo/seo');
  await page.getByLabel('Title suffix', { exact: true }).fill('| Browser');
  await page.getByRole('button', { name: 'Save SEO settings', exact: true }).click();
  await expect(page.locator('.toast')).toContainText('SEO settings saved');
  await page.getByRole('button', { name: 'Add redirect', exact: true }).click();
  await page.getByLabel('Source path', { exact: true }).fill('/old-browser-page');
  await page.getByLabel('Destination', { exact: true }).fill('/p/plugin-browser-page');
  await page.getByRole('button', { name: 'Save redirects', exact: true }).click();
  await expect(page.locator('.toast')).toContainText('Redirects saved');
  const redirect = await page.request.get('/old-browser-page', { maxRedirects: 0 });
  expect(redirect.status()).toBe(301); expect(redirect.headers().location).toContain('/p/plugin-browser-page');
  expect(await (await page.request.get('/sitemap-pages.xml')).text()).toContain('/p/plugin-browser-page');
  await page.goto('/studio/plugins');
  await card.getByRole('button', { name: 'Deactivate', exact: true }).click();
  await expect(card.getByRole('button', { name: 'Activate', exact: true })).toBeVisible();
  expect((await page.request.get('/sitemap.xml')).status()).toBe(404);
  await page.goto('/p/plugin-browser-page');
  await expect(page).toHaveTitle('Plugin browser page | FastFind');
});

test('theme switching, navigation persistence and owner health have real effects', async ({ page }) => {
  await page.goto('/studio/themes');
  await page.getByRole('button', { name: /Minimalist/ }).click();
  await page.getByRole('button', { name: 'Apply theme', exact: true }).click();
  await expect(page.locator('.toast')).toContainText('Public theme updated');
  await page.goto('/');
  await expect(page.locator('.public-shell')).toHaveAttribute('data-theme', 'minimalist');
  await page.goto('/studio/navigation');
  await page.getByLabel('Navigation label', { exact: true }).first().fill('Start here');
  await page.getByRole('button', { name: 'Save navigation ↗' }).click();
  await expect(page.locator('.toast')).toContainText('Navigation updated');
  await page.reload();
  await expect(page.getByLabel('Navigation label', { exact: true }).first()).toHaveValue('Start here');
  await page.goto('/studio/health');
  await expect(page.getByRole('heading', { name: 'System health', exact: true })).toBeVisible();
  await expect(page.locator('main')).toContainText(/Database/i);
});

test('owner uploads a signed custom theme, previews it and applies it explicitly', async ({ page }, testInfo) => {
  const fixture = path.resolve('storage/framework/testing', `run-${process.env.FASTFIND_E2E_RUN_ID}`, 'framework/testing/aurora.zip');
  await page.goto('/studio/themes');
  const previous = (await api(page, 'GET', '/studio/themes')).data;
  await page.getByLabel('Theme package ZIP', { exact: true }).setInputFiles(fixture);
  await page.getByLabel('Signature metadata (optional)', { exact: true }).setInputFiles(`${fixture}.json`);
  await expect(page.getByLabel('Trusted publisher ID', { exact: true })).toHaveValue('local-reviewed');
  await page.getByRole('button', { name: 'Verify and install', exact: true }).click();
  await expect(page.locator('.package-upload [role=status]')).toContainText('Theme installed');
  await expect(page.getByRole('button', { name: /Aurora/ })).toHaveAttribute('aria-pressed', 'true');
  expect((await api(page, 'GET', '/studio/themes')).data.active).toBe(previous.active);
  await page.getByRole('combobox', { name: 'Corner style', exact: true }).selectOption('rounded');
  await page.getByRole('button', { name: 'Apply theme', exact: true }).click();
  await expect(page.locator('.toast')).toContainText('Public theme updated');
  await page.screenshot({ path: testInfo.outputPath('custom-theme-library.png'), fullPage: true });
  const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
  expect(scan.violations.map(({ id, nodes }) => ({ id, nodes: nodes.map(node => node.target) }))).toEqual([]);
  await page.goto('/');
  await expect(page.locator('.public-shell')).toHaveAttribute('data-theme', 'aurora');
  await expect(page.locator('.public-shell')).toHaveAttribute('data-theme-layout', 'editorial');
  await expect(page.locator('.public-shell')).toHaveCSS('background-color', 'rgb(250, 247, 255)');
  await expect(page.locator('.article-card').first()).toHaveCSS('border-radius', '24px');
  await page.screenshot({ path: testInfo.outputPath('custom-theme-public.png'), fullPage: true });
  expect((await api(page, 'PUT', '/studio/themes', { id: previous.active, settings: previous.settings })).status).toBe(200);
});

test('marketplace explains connection setup and links to real libraries', async ({ page }, testInfo) => {
  await page.goto('/studio/marketplace');
  await expect(page.getByRole('heading', { name: 'Connect your marketplace' })).toBeVisible();
  await expect(page.locator('.marketplace-card')).toHaveCount(0);
  await page.screenshot({ path: testInfo.outputPath('marketplace-unconfigured.png'), fullPage: true });
  const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
  expect(scan.violations.map(({ id, nodes }) => ({ id, nodes: nodes.map(node => node.target) }))).toEqual([]);
  await page.getByRole('link', { name: 'Explore themes', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Your theme library' })).toBeVisible();
});

test('viewer can read but cannot change content or manage plugins', async ({ browser }) => {
  const page = await browser.newPage(); await login(page, 'viewer');
  await page.goto('/studio/pages');
  await expect(page.getByLabel('New page title')).toHaveCount(0);
  await page.getByRole('link', { name: 'Getting started', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Save draft', exact: true })).toBeDisabled();
  expect((await api(page, 'POST', '/studio/pages', { title: 'Forbidden' })).status).toBe(403);
  expect((await api(page, 'GET', '/studio/plugins')).status).toBe(403);
  await page.close();
});

test('media upload displays decoded image and rejects executable content', async ({ page }) => {
  await page.goto('/studio/media');
  const png = Buffer.from(await page.evaluate(() => { const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1; canvas.getContext('2d').fillRect(0, 0, 1, 1); return canvas.toDataURL('image/png').split(',')[1]; }), 'base64');
  await page.locator('input[type=file]').setInputFiles({ name: 'browser-pixel.png', mimeType: 'image/png', buffer: png });
  await page.getByLabel('Image description').fill('Browser test pixel');
  const imageResponse = page.waitForResponse(response => new URL(response.url()).pathname.startsWith('/storage/images/') && response.request().resourceType() === 'image');
  await page.getByRole('button', { name: 'Upload ↗' }).click();
  await expect(page.locator('.toast')).toContainText('File uploaded');
  const response = await imageResponse;
  expect(response.status(), 'Uploaded image must be served from the isolated media directory').toBe(200);
  expect(response.headers()['content-type']).toMatch(/^image\/(png|webp)(;|$)/);
  const img = page.locator('.media-grid img').first();
  await expect(img).toHaveAttribute('alt', 'Browser test pixel');
  await expect.poll(() => img.evaluate(image => ({ complete: image.complete, width: image.naturalWidth, height: image.naturalHeight }))).toEqual({ complete: true, width: 1, height: 1 });
  await page.locator('input[type=file]').setInputFiles({ name: 'bad.php', mimeType: 'application/x-httpd-php', buffer: Buffer.from('<?php echo "bad";') });
  const rejectedUpload = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/studio/media' && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Upload ↗' }).click();
  const rejection = await rejectedUpload;
  expect(rejection.status()).toBe(422);
  const validation = await rejection.json();
  await expect(page.locator('.toast')).toContainText(validation.errors.file[0]);
  await expect(page.locator('.media-grid img')).toHaveCount(1);
});

test('visual builder edits inline, inserts modules, switches layers and saves the same document', async ({ page }, testInfo) => {
  await create(page, 'Visual canvas workflow');
  await page.getByRole('button', { name: 'Add Paragraph block', exact: true }).click();
  await page.getByRole('button', { name: 'Edit text on canvas', exact: true }).first().click();
  await page.getByLabel('Edit paragraph on canvas', { exact: true }).fill('Written directly on the visual canvas');
  await page.getByRole('button', { name: 'Insert module after Paragraph 1 in section 1', exact: true }).click();
  await page.getByRole('dialog', { name: 'Insert a module' }).getByLabel('Search modules', { exact: true }).fill('Heading');
  await page.getByRole('button', { name: 'Insert Heading', exact: true }).click();
  await expect(page.locator('.canvas-block')).toHaveCount(2);
  await page.getByRole('button', { name: 'Wireframe view', exact: true }).click();
  await page.getByRole('button', { name: 'Layers', exact: true }).click();
  await page.getByRole('button', { name: 'Select Paragraph module 1 in section 1', exact: true }).click();
  await expect(page.getByLabel('Paragraph text', { exact: true })).toHaveValue('Written directly on the visual canvas');
  await page.getByRole('button', { name: 'Mobile', exact: true }).click();
  await page.getByRole('button', { name: 'Visual view', exact: true }).click();
  await saved(page);
  await page.screenshot({ path: testInfo.outputPath('visual-builder-mobile-canvas.png'), fullPage: true });
  await page.reload();
  await expect(page.locator('.canvas-block')).toHaveCount(2);
  await expect(page.locator('.canvas-block-preview').first()).toContainText('Written directly on the visual canvas');
});

test('editor controls are accessible in light and dark mode', async ({ page }, testInfo) => {
  await create(page, 'Accessible editor');
  await page.getByRole('button', { name: 'Add Paragraph block', exact: true }).click();
  for (const mode of ['light', 'dark']) {
    if (mode === 'dark') await page.getByRole('button', { name: 'Dark mode', exact: true }).click();
    await page.screenshot({ path: testInfo.outputPath(`editor-${mode}.png`), fullPage: true });
    const report = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(report.violations.map(({ id, nodes }) => ({ id, nodes: nodes.map(node => node.target) }))).toEqual([]);
  }
});

for (const width of [375, 768, 1280, 1920]) {
  test(`every layout and block renders without viewport overflow at ${width}px`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/studio/pages');
    await page.getByRole('link', { name: 'Builder catalog', exact: true }).click();
    await expect(page.locator('.canvas-section')).toHaveCount(17);
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    await expect(page.locator('.rendered-section')).toHaveCount(17);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath(`catalog-${width}.png`), fullPage: true });
    const a11y = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(a11y.violations.map(({ id, nodes }) => ({ id, nodes: nodes.map(node => node.target) }))).toEqual([]);
    const id = page.url().split('/').at(-1);
    const current = (await api(page, 'GET', `/studio/pages/${id}`)).data.page;
    expect((await api(page, 'POST', `/studio/pages/${id}/publish`, { version: current.version })).status).toBe(200);
    await page.goto('/p/builder-catalog');
    await expect(page.locator('.rendered-section')).toHaveCount(17);
    const columnCount = await page.locator('.rendered-section[data-layout="four-columns"] .registered-grid').evaluate(node => getComputedStyle(node).gridTemplateColumns.split(' ').length);
    expect(columnCount).toBe(width <= 500 ? 1 : width <= 1020 ? 2 : 4);
    await page.getByRole('tab', { name: 'Overview', exact: true }).click();
    await page.keyboard.press('ArrowRight');
    await expect(page.getByRole('tab', { name: 'Details', exact: true })).toHaveAttribute('aria-selected', 'true');
    await page.getByRole('button', { name: 'Next slide', exact: true }).click();
    await expect(page.locator('.carousel-controls')).toContainText('Slide 2 of 2');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath(`public-catalog-${width}.png`), fullPage: true });
    const publicA11y = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(publicA11y.violations.map(({ id, nodes }) => ({ id, nodes: nodes.map(node => node.target) }))).toEqual([]);
  });
}
