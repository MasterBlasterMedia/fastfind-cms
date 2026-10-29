import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

async function signIn(page, password = 'FastFind-e2e-only-2026') {
  await page.goto('/studio');
  await page.getByLabel('Email address', { exact: true }).fill('recovery@fastfind.test');
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: /Sign in to studio/ }).click();
  await expect(page.getByRole('navigation', { name: 'Studio navigation' })).toBeVisible();
}

test('password recovery sends a real local notification, resets once, and revokes old sessions', async ({ browser, page }, testInfo) => {
  const first = await browser.newContext();
  const second = await browser.newContext();
  try {
    const oldPage = await first.newPage();
    await signIn(oldPage);
    await signIn(await second.newPage());
    await page.goto('/studio');
    await page.getByRole('link', { name: 'Forgot your password?' }).click();
    const forgotA11y = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(forgotA11y.violations.map(({ id }) => id)).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('forgot-password.png'), fullPage: true });
    await page.getByLabel('Email address', { exact: true }).fill('recovery@fastfind.test');
    await page.getByRole('button', { name: 'Send reset link', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('If an active account matches');

    // Read the real notification delivered by the log mailer, only within this run.
    const id = process.env.FASTFIND_E2E_RUN_ID;
    expect(id).toMatch(/^[a-f0-9-]{36}$/);
    const logPath = path.resolve('storage/framework/testing', `run-${id}`, 'logs/laravel.log');
    let link;
    await expect.poll(async () => {
      const raw = await readFile(logPath, 'utf8').catch(() => '');
      // Laravel's log transport may render an unencoded body despite its MIME header.
      const decoded = raw.replace(/=\r?\n/g, '').replace(/=([a-f0-9]{2})/gi, (_, hex) => String.fromCharCode(parseInt(hex, 16)));
      link = [raw, decoded].map(mail => mail.replace(/&amp;/g, '&').match(/http:\/\/127\.0\.0\.1:8123\/studio\/reset-password\?token=[a-f0-9]{64}&email=recovery%40fastfind\.test/)?.[0]).find(Boolean);
      return Boolean(link);
    }).toBe(true);
    const response = await page.goto(link);
    expect(response.headers()['referrer-policy']).toBe('no-referrer');
    expect(response.headers()['cache-control']).toContain('no-store');
    await page.getByLabel('New password', { exact: true }).fill('New-browser-password-2026');
    await page.getByLabel('Confirm new password', { exact: true }).fill('Different-browser-password');
    await page.getByRole('button', { name: 'Reset password', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('do not match');
    await page.getByLabel('Confirm new password', { exact: true }).fill('New-browser-password-2026');
    await page.getByRole('button', { name: 'Reset password', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Your password has been reset');
    await expect(page).toHaveURL('http://127.0.0.1:8123/studio/reset-password');
    const json = { headers: { Accept: 'application/json' } };
    expect((await first.request.get('/api/v1/studio/pages', json)).status()).toBe(401);
    expect((await second.request.get('/api/v1/studio/pages', json)).status()).toBe(401);
    await page.getByRole('link', { name: 'Back to sign in' }).click();
    await page.getByLabel('Email address', { exact: true }).fill('recovery@fastfind.test');
    await page.getByLabel('Password', { exact: true }).fill('FastFind-e2e-only-2026');
    await page.getByRole('button', { name: /Sign in to studio/ }).click();
    await expect(page.getByRole('alert')).toContainText('incorrect');
    await page.getByLabel('Password', { exact: true }).fill('New-browser-password-2026');
    await page.getByRole('button', { name: /Sign in to studio/ }).click();
    await expect(page.getByRole('navigation', { name: 'Studio navigation' })).toBeVisible();
    await page.goto(link);
    await page.getByLabel('New password', { exact: true }).fill('Another-password-2026');
    await page.getByLabel('Confirm new password', { exact: true }).fill('Another-password-2026');
    await page.getByRole('button', { name: 'Reset password', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('invalid or has expired');
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    const a11y = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(a11y.violations.map(({ id, nodes }) => ({ id, nodes: nodes.map(node => node.target) }))).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('reset-password-mobile.png'), fullPage: true });
  } finally {
    await first.close();
    await second.close();
  }
});
