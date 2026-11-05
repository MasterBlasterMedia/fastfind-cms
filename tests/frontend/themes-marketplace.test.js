import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import Marketplace from '../../resources/js/views/Marketplace.vue';
import Themes from '../../resources/js/views/Themes.vue';
import SignedPackageUpload from '../../resources/js/components/SignedPackageUpload.vue';
import { themeStyle } from '../../resources/js/themes';
import { get, post, put } from '../../resources/js/lib';

vi.mock('../../resources/js/lib', () => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), failure: e => e.message }));
const wrappers = [];
beforeEach(() => vi.resetAllMocks());
afterEach(() => wrappers.splice(0).forEach(wrapper => wrapper.unmount()));
const defaults = { accent: '#3159d9', background: '#ffffff', surface: '#ffffff', text: '#172431', muted: '#52665c', font: 'sans', width: 'wide', radius: 'soft', spacing: 'normal' };
const themes = [{ id: 'professional', name: 'Professional', layout: 'editorial', defaults }, { id: 'aurora', name: 'Aurora', layout: 'minimal', defaults: { ...defaults, accent: '#ffffff', font: 'serif', radius: 'rounded' } }];
const item = { type: 'theme', id: 'aurora', name: 'Aurora', description: 'A quiet design.', publisher: 'local-reviewed', version: '1.0.0', sha256: 'a'.repeat(64), can_install: true, issues: [] };
const catalog = { configured: true, name: 'Our marketplace', site_url: 'https://store.example.test', items: [item, { ...item, type: 'plugin', id: 'vendor/search', name: 'Search Plus' }] };
async function render(component, role = 'owner', path = '/studio/marketplace') {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }] });
  await router.push(path);
  const session = { toast: vi.fn(), reloadSite: vi.fn() };
  const wrapper = mount(component, { props: { user: { role, name: 'Owner' } }, attachTo: document.body, global: { plugins: [router], provide: { session }, stubs: { teleport: true } } });
  wrappers.push(wrapper); await flushPromises();
  return { wrapper, session };
}
const button = (wrapper, text) => wrapper.findAll('button').find(node => node.text() === text);

describe('theme design settings', () => {
  it('limits CSS values to supported tokens and chooses readable accent text', () => {
    expect(themeStyle({ accent: '#ffffff' })['--theme-accent-text']).toBe('#000000');
    expect(themeStyle({ accent: '#000000' })['--theme-accent-text']).toBe('#ffffff');
    const style = themeStyle({ accent: 'url(https://bad.test)', background: '</style>', font: 'external', radius: '50vh', spacing: '99' });
    expect(style['--theme-accent']).toBe('#3159d9');
    expect(style['--theme-background']).toBe('#fbfaf7');
    expect(style['--theme-radius']).toBe('12px');
    expect(style['--theme-spacing']).toBe('1');
    expect(Object.values(style).join(' ')).not.toContain('bad.test');
  });
  it('previews selected package defaults and applies them only on save', async () => {
    get.mockResolvedValue({ themes, active: 'professional', settings: defaults });
    const { wrapper, session } = await render(Themes);
    await wrapper.findAll('.theme-card')[1].trigger('click');
    expect(put).not.toHaveBeenCalled();
    expect(wrapper.get('.theme-design-preview').attributes('data-layout')).toBe('minimal');
    expect(wrapper.get('.theme-design-preview').attributes('style')).toContain('--theme-radius: 24px');
    await wrapper.get('form.settings-form').trigger('submit'); await flushPromises();
    expect(put).toHaveBeenCalledWith('/studio/themes', { id: 'aurora', settings: themes[1].defaults });
    expect(session.reloadSite).toHaveBeenCalledOnce();
  });
  it('keeps settings after a failed save and prevents duplicate submissions', async () => {
    get.mockResolvedValue({ themes, active: 'professional', settings: defaults });
    let reject; put.mockImplementation(() => new Promise((_, fail) => { reject = fail; }));
    const { wrapper } = await render(Themes);
    await wrapper.findAll('.theme-card')[1].trigger('click');
    await wrapper.get('form.settings-form').trigger('submit'); await wrapper.get('form.settings-form').trigger('submit');
    expect(put).toHaveBeenCalledOnce();
    reject(new Error('Save failed')); await flushPromises();
    expect(wrapper.get('[role=alert]').text()).toContain('Save failed');
    expect(wrapper.findAll('.theme-card')[1].attributes('aria-pressed')).toBe('true');
  });
  it('allows admins to apply designs but reserves package upload for owners', async () => {
    get.mockResolvedValue({ themes, active: 'professional', settings: defaults });
    const { wrapper } = await render(Themes, 'admin');
    expect(wrapper.findComponent(SignedPackageUpload).exists()).toBe(false);
    expect(button(wrapper, 'Apply theme').element.disabled).toBe(false);
  });
});

describe('external marketplace', () => {
  it('honestly explains an unconfigured catalog without fictional listings', async () => {
    get.mockResolvedValue({ configured: false, items: [] });
    const { wrapper } = await render(Marketplace);
    expect(wrapper.text()).toContain('No marketplace address has been configured');
    expect(wrapper.find('.marketplace-card').exists()).toBe(false);
    expect(post).not.toHaveBeenCalled();
  });
  it('filters packages by requested type and search', async () => {
    get.mockResolvedValue(catalog);
    const { wrapper } = await render(Marketplace, 'owner', '/studio/marketplace?type=theme');
    expect(wrapper.findAll('.marketplace-card')).toHaveLength(1);
    await wrapper.get('select').setValue('all');
    await wrapper.get('input[type=search]').setValue('Search Plus');
    expect(wrapper.findAll('.marketplace-card')).toHaveLength(1);
    expect(wrapper.get('.marketplace-card').text()).toContain('Search Plus');
  });
  it('reviews before installing, pins the chosen digest and prevents duplicate requests', async () => {
    get.mockResolvedValue(catalog);
    let resolve; post.mockImplementation(() => new Promise(done => { resolve = done; }));
    const { wrapper } = await render(Marketplace);
    await wrapper.get('.marketplace-card button').trigger('click');
    expect(post).not.toHaveBeenCalled();
    const dialog = wrapper.getComponent({ name: 'AppDialog' });
    await button(dialog, 'Download and install').trigger('click');
    await button(dialog, 'Verifying and installing…').trigger('click');
    expect(post).toHaveBeenCalledOnce();
    expect(post).toHaveBeenCalledWith('/studio/marketplace/install', { type: 'theme', id: 'aurora', version: '1.0.0', sha256: item.sha256 });
    resolve({ status: 'installed' }); await flushPromises();
    expect(wrapper.text()).toContain('Open Themes to customize and apply it');
    expect(post.mock.calls.every(([url]) => !url.includes('activate'))).toBe(true);
  });
  it('retains the reviewed package and displays a rejected stale catalog response', async () => {
    get.mockResolvedValue(catalog); post.mockRejectedValue(new Error('The catalog package changed. Refresh and review it again.'));
    const { wrapper } = await render(Marketplace);
    await wrapper.get('.marketplace-card button').trigger('click');
    const dialog = wrapper.getComponent({ name: 'AppDialog' });
    await button(dialog, 'Download and install').trigger('click'); await flushPromises();
    expect(dialog.get('[role=alert]').text()).toContain('catalog package changed');
    expect(button(dialog, 'Download and install').element.disabled).toBe(false);
  });
  it('does not offer installation to nonowners or incompatible packages', async () => {
    get.mockResolvedValue(catalog);
    const { wrapper } = await render(Marketplace, 'admin');
    expect(wrapper.findAll('.marketplace-card button').every(node => node.element.disabled)).toBe(true);
    expect(post).not.toHaveBeenCalled();
  });
  it('never renders an unsafe storefront address', async () => {
    get.mockResolvedValue({ ...catalog, site_url: 'javascript:alert(1)' });
    const { wrapper } = await render(Marketplace);
    expect(wrapper.find('.topbar-actions a[target=_blank]').exists()).toBe(false);
  });
});
