import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import PasswordRecovery from '../../resources/js/components/PasswordRecovery.vue';
import App from '../../resources/js/App.vue';
import { get, post } from '../../resources/js/lib';

vi.mock('../../resources/js/lib', () => ({ get: vi.fn(), post: vi.fn(), remove: vi.fn(), failure: e => e.response?.data?.message || e.message }));
vi.mock('../../resources/js/extensions', () => ({ activateClientExtensions: vi.fn(() => []) }));
vi.mock('../../resources/js/head', () => ({ applyPageHead: vi.fn() }));
const wrappers = [];
beforeEach(() => vi.resetAllMocks());
afterEach(() => wrappers.splice(0).forEach(wrapper => wrapper.unmount()));

async function form(url = '/studio/forgot-password') {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }] });
  await router.push(url);
  await router.isReady();
  const resetPassword = vi.fn(input => post('/password/reset', input));
  const wrapper = mount(PasswordRecovery, { props: { resetPassword }, attachTo: document.body, global: { plugins: [router] } });
  wrappers.push(wrapper);
  return { wrapper, router, resetPassword };
}
async function passwords(wrapper, password = 'A-new-password-123', confirmation = password) {
  await wrapper.findAll('input[type=password]')[0].setValue(password);
  await wrapper.findAll('input[type=password]')[1].setValue(confirmation);
}
const resetUrl = '/studio/reset-password?token=test-token&email=owner%40example.test';

describe('password recovery', () => {
  it('requests a link once while pending and shows the generic response', async () => {
    let resolve;
    post.mockImplementation(() => new Promise(done => { resolve = done; }));
    const { wrapper } = await form();
    await wrapper.get('input[type=email]').setValue('owner@example.test');
    await wrapper.get('form').trigger('submit');
    await wrapper.get('form').trigger('submit');
    expect(post).toHaveBeenCalledTimes(1);
    expect(post).toHaveBeenCalledWith('/password/forgot', { email: 'owner@example.test' });
    expect(wrapper.get('button').element.disabled).toBe(true);
    resolve({ message: 'If an active account matches that email, a password reset link has been sent.' });
    await flushPromises();
    expect(wrapper.get('[role=status]').text()).toContain('If an active account');
    expect(document.activeElement).toBe(wrapper.get('[role=status]').element);
    expect(wrapper.find('form').exists()).toBe(false);
  });

  it('retains input after a network failure and allows retry', async () => {
    post.mockRejectedValueOnce(new Error('Network unavailable')).mockResolvedValueOnce({ message: 'Check your inbox.' });
    const { wrapper } = await form();
    await wrapper.get('input').setValue('owner@example.test');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role=alert]').text()).toBe('Network unavailable');
    expect(wrapper.get('input').element.value).toBe('owner@example.test');
    expect(wrapper.get('button').element.disabled).toBe(false);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role=status]').text()).toBe('Check your inbox.');
  });

  it.each(['/studio/reset-password', '/studio/reset-password?token=a&token=b&email=user%40example.test'])('rejects incomplete or ambiguous links: %s', async url => {
    const { wrapper } = await form(url);
    expect(wrapper.get('[role=alert]').text()).toContain('incomplete');
    expect(wrapper.find('form').exists()).toBe(false);
    expect(post).not.toHaveBeenCalled();
    expect(wrapper.get('a[href="/studio/forgot-password"]').text()).toContain('new reset link');
  });

  it('requires matching passwords before sending the token', async () => {
    const { wrapper } = await form(resetUrl);
    await passwords(wrapper, 'A-new-password-123', 'A-different-password');
    await wrapper.get('form').trigger('submit');
    expect(wrapper.get('[role=alert]').text()).toContain('do not match');
    expect(post).not.toHaveBeenCalled();
  });

  it('clears secret fields and the URL on success without automatically signing in', async () => {
    post.mockResolvedValue({ message: 'Your password has been reset. Sign in with your new password.' });
    const { wrapper, router, resetPassword } = await form(resetUrl);
    await passwords(wrapper);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(post).toHaveBeenCalledWith('/password/reset', { email: 'owner@example.test', token: 'test-token', password: 'A-new-password-123', password_confirmation: 'A-new-password-123' });
    expect(router.currentRoute.value.query).toEqual({});
    expect(wrapper.get('[role=status]').text()).toContain('Sign in');
    expect(wrapper.find('input[type=password]').exists()).toBe(false);
    expect(resetPassword).toHaveBeenCalledTimes(1);
    expect(wrapper.get('a[href="/studio"]').text()).toContain('sign in');
  });

  it('keeps recoverable form input when reset is rejected or throttled', async () => {
    post.mockRejectedValueOnce({ response: { status: 422, data: { message: 'This reset link has expired.' } } })
      .mockRejectedValueOnce({ response: { status: 429 } });
    const { wrapper, router } = await form(resetUrl);
    await passwords(wrapper);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role=alert]').text()).toContain('expired');
    expect(wrapper.findAll('input[type=password]')[0].element.value).toBe('A-new-password-123');
    expect(router.currentRoute.value.query.token).toBe('test-token');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role=alert]').text()).toContain('Too many attempts');
    expect(wrapper.find('[role=status]').exists()).toBe(false);
  });

  it('uses a newly opened link rather than stale token or password state', async () => {
    const { wrapper, router } = await form(resetUrl);
    await passwords(wrapper);
    await router.push('/studio/reset-password?token=second-token&email=other%40example.test');
    await flushPromises();
    expect(wrapper.get('input[type=email]').element.value).toBe('other@example.test');
    expect(wrapper.findAll('input[type=password]')[0].element.value).toBe('');
    post.mockResolvedValue({ message: 'Reset complete.' });
    await passwords(wrapper);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(post.mock.calls[0][1].token).toBe('second-token');
  });

  it('keeps a new reset link and its pending request intact when an earlier reset completes', async () => {
    let finishFirst, finishSecond;
    post.mockImplementationOnce(() => new Promise(resolve => { finishFirst = resolve; }))
      .mockImplementationOnce(() => new Promise(resolve => { finishSecond = resolve; }));
    const { wrapper, router } = await form(resetUrl);
    await passwords(wrapper);
    await wrapper.get('form').trigger('submit');
    const newUrl = '/studio/reset-password?token=second-token&email=other%40example.test';
    await router.push(newUrl);
    await passwords(wrapper, 'Second-new-password-456');
    await wrapper.get('form').trigger('submit');
    finishFirst({ message: 'First password reset.' });
    await flushPromises();
    expect(router.currentRoute.value.fullPath).toBe(newUrl);
    expect(wrapper.findAll('input[type=password]')[0].element.value).toBe('Second-new-password-456');
    expect(wrapper.get('button').element.disabled).toBe(true);
    expect(wrapper.find('[role=status]').exists()).toBe(false);
    finishSecond({ message: 'Second password reset.' });
    await flushPromises();
    expect(wrapper.get('[role=status]').text()).toBe('Second password reset.');
    expect(router.currentRoute.value.query).toEqual({});
    expect(post.mock.calls[1][1].token).toBe('second-token');
  });

  it('does not replace the forgot-password form with a previous reset result', async () => {
    let finish;
    post.mockImplementation(() => new Promise(resolve => { finish = resolve; }));
    const { wrapper, router } = await form(resetUrl);
    await passwords(wrapper);
    await wrapper.get('form').trigger('submit');
    await router.push('/studio/forgot-password');
    await wrapper.get('input[type=email]').setValue('another@example.test');
    finish({ message: 'Your password has been reset.' });
    await flushPromises();
    expect(router.currentRoute.value.path).toBe('/studio/forgot-password');
    expect(wrapper.get('input[type=email]').element.value).toBe('another@example.test');
    expect(wrapper.get('button').element.disabled).toBe(false);
    expect(wrapper.find('[role=status]').exists()).toBe(false);
  });

  it('ignores a late error after navigating from forgot-password to a reset link', async () => {
    let fail;
    post.mockImplementation(() => new Promise((resolve, reject) => { fail = reject; }));
    const { wrapper, router } = await form();
    await wrapper.get('input[type=email]').setValue('owner@example.test');
    await wrapper.get('form').trigger('submit');
    await router.push(resetUrl);
    await passwords(wrapper);
    fail(new Error('Previous request failed'));
    await flushPromises();
    expect(router.currentRoute.value.fullPath).toBe(resetUrl);
    expect(wrapper.find('[role=alert]').exists()).toBe(false);
    expect(wrapper.findAll('input[type=password]')[0].element.value).toBe('A-new-password-123');
    expect(wrapper.get('button').element.disabled).toBe(false);
  });

  it('clears the app session after a successful reset even after leaving and unmounting the form', async () => {
    const user = { id: 1, name: 'Owner', email: 'owner@example.test', role: 'owner', is_active: true };
    get.mockImplementation(async path => path === '/session' ? { user } : path === '/site' ? { navigation: [] } : { page_fields: [], admin_views: [], active_plugins: [] });
    let finish;
    post.mockImplementation(() => new Promise(resolve => { finish = resolve; }));
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { props: ['user'], template: '<p data-testid="signed-in">{{ user?.email }}</p>' } }] });
    await router.push(resetUrl);
    await router.isReady();
    const wrapper = mount(App, { attachTo: document.body, global: { plugins: [router] } });
    wrappers.push(wrapper);
    await flushPromises();
    await passwords(wrapper);
    await wrapper.get('form').trigger('submit');
    await router.push('/studio');
    await flushPromises();
    expect(wrapper.findComponent(PasswordRecovery).exists()).toBe(false);
    expect(wrapper.get('[data-testid=signed-in]').text()).toBe(user.email);
    finish({ message: 'Your password has been reset.' });
    await flushPromises();
    expect(router.currentRoute.value.path).toBe('/studio');
    expect(wrapper.find('[data-testid=signed-in]').exists()).toBe(false);
    expect(wrapper.get('button').text()).toContain('Sign in to studio');
    expect(wrapper.find('[role=status]').exists()).toBe(false);
  });
});
