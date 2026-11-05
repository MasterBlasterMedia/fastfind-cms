import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { h, ref } from 'vue';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import Editor from '../../resources/js/views/Editor.vue';
import { forgetPluginDefinitions, newSection, registerBlock, registerLayout, starterBlock } from '../../resources/js/builder/registry';
import { forgetPluginRenderers, registerBlockRenderer } from '../../resources/js/builder/renderers';
import { get, put } from '../../resources/js/lib';

vi.mock('../../resources/js/lib', () => ({ get: vi.fn(), put: vi.fn(), post: vi.fn(), failure: error => error.message }));
const wrappers = [];
const clone = value => JSON.parse(JSON.stringify(value));
const button = (wrapper, name) => wrapper.findAll('button').find(item => item.text() === name);
const field = (wrapper, label) => wrapper.findAll('label').find(item => item.text().startsWith(label)).get('input,textarea,select');
const dialog = () => document.querySelector('[role="dialog"]');
const dialogButton = name => dialog().querySelector(`[aria-label="${name}"]`);
async function searchPicker(value) {
  const input = dialog().querySelector('input[type="search"]');
  input.value = value; input.dispatchEvent(new Event('input', { bubbles: true }));
  await flushPromises();
}
function pageFixture() {
  const first = newSection('stack'), second = newSection('accordion');
  const paragraph = starterBlock('text'), heading = starterBlock('heading');
  paragraph.props.text = 'Original paragraph'; heading.props.text = 'Original heading';
  first.blocks = [paragraph, heading]; second.blocks = [starterBlock('faq')];
  return { id: 31, title: 'Visual editor test', slug: 'visual-editor-test', summary: '', status: 'draft', version: 1, show_in_search: true, extensions: {}, document: { version: 1, sections: [first, second] } };
}
async function editor(role = 'owner') {
  const initial = pageFixture();
  get.mockResolvedValue({ page: clone(initial) });
  put.mockImplementation(async (path, payload) => ({ page: { ...clone(initial), ...clone(payload), version: 2 } }));
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/studio/pages/:id/edit', component: Editor, props: { user: { name: 'Test owner', role } } }, { path: '/studio/pages', component: { template: '<div />' } }] });
  await router.push('/studio/pages/31/edit'); await router.isReady();
  const wrapper = mount({ render: () => h(RouterView) }, { attachTo: document.body, global: { plugins: [router], provide: { session: { toast: vi.fn(), extensions: ref({ page_fields: [] }) } }, stubs: { StudioFrame: { template: '<div><slot /></div>' } } } });
  wrappers.push(wrapper); await flushPromises();
  return { wrapper, initial };
}
beforeEach(() => vi.resetAllMocks());
afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); forgetPluginDefinitions('test/visual-builder'); forgetPluginRenderers('test/visual-builder'); document.body.innerHTML = ''; });

describe('native visual builder', () => {
  it('edits text directly on the canvas and keeps inspector, wireframe, undo and save in sync', async () => {
    const { wrapper } = await editor();
    await wrapper.findAll('.canvas-inline-edit')[0].trigger('click');
    const text = wrapper.get('.inline-text-editor textarea');
    expect(document.activeElement).toBe(text.element);
    await text.setValue('Edited directly on canvas');
    expect(field(wrapper, 'Paragraph text').element.value).toBe('Edited directly on canvas');
    await button(wrapper, 'Wireframe view').trigger('click');
    expect(wrapper.find('.canvas-block-preview').exists()).toBe(false);
    expect(wrapper.findAll('.wireframe-summary')[0].text()).toBe('Edited directly on canvas');
    await button(wrapper, 'Undo').trigger('click');
    expect(wrapper.findAll('.wireframe-summary')[0].text()).toBe('Original paragraph');
    await button(wrapper, 'Redo').trigger('click');
    await button(wrapper, 'Visual view').trigger('click');
    expect(wrapper.findAll('.canvas-block-preview')[0].text()).toContain('Edited directly on canvas');
    await button(wrapper, 'Save draft').trigger('click'); await flushPromises();
    expect(put.mock.calls[0][1].document.sections[0].blocks[0].props.text).toBe('Edited directly on canvas');
    expect(wrapper.get('.editor-status').text()).toContain('All changes saved');
  });

  it('inserts a searched module exactly between existing modules and rejects incompatible choices', async () => {
    const { wrapper, initial } = await editor();
    await wrapper.get('[aria-label="Insert module after Paragraph 1 in section 1"]').trigger('click');
    expect(dialog().textContent).toContain('at position 2');
    await searchPicker('image');
    expect([...dialog().querySelectorAll('.insert-option')].every(item => item.textContent.includes('Image'))).toBe(true);
    expect(dialogButton('Insert Paragraph')).toBeNull();
    dialogButton('Insert Image').click(); await flushPromises();
    const first = wrapper.findAll('.canvas-section')[0];
    const ids = first.findAll('.canvas-block').map(item => item.attributes('data-block-id'));
    expect(ids[0]).toBe(initial.document.sections[0].blocks[0].id);
    expect(ids[2]).toBe(initial.document.sections[0].blocks[1].id);
    expect(first.findAll('.block-select').map(item => item.text())).toEqual(['Paragraph', 'Image', 'Heading']);
    expect(dialog()).toBeNull();
    await wrapper.get('[aria-label="Add module to section 2"]').trigger('click');
    expect(dialogButton('Insert Paragraph').disabled).toBe(true);
    expect(dialogButton('Insert FAQ').disabled).toBe(false);
    await searchPicker('no-matching-module');
    expect(dialog().querySelector('[role="status"]').textContent).toContain('No matches');
    dialog().dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await flushPromises(); expect(dialog()).toBeNull();
  });

  it('inserts a chosen section at its contextual position while preserving surrounding content', async () => {
    const { wrapper, initial } = await editor();
    await wrapper.get('[aria-label="Insert section after section 1"]').trigger('click');
    await searchPicker('two columns');
    dialogButton('Insert Two columns').click(); await flushPromises();
    expect(wrapper.findAll('.canvas-section').map(item => item.attributes('data-layout'))).toEqual(['stack', 'two-columns', 'accordion']);
    expect(wrapper.findAll('.canvas-section')[2].attributes('data-section-id')).toBe(initial.document.sections[1].id);
    expect(wrapper.findAll('.canvas-block')).toHaveLength(3);
    await button(wrapper, 'Undo').trigger('click');
    expect(wrapper.findAll('.canvas-section')).toHaveLength(2);
  });

  it('selects modules through collapsible layers and applies section design to the live canvas', async () => {
    const { wrapper } = await editor();
    await button(wrapper, 'Layers').trigger('click');
    await wrapper.get('[aria-label="Select Heading module 2 in section 1"]').trigger('click');
    expect(field(wrapper, 'Heading').element.value).toBe('Original heading');
    expect(wrapper.get('[aria-label="Select Heading module 2 in section 1"]').attributes('aria-current')).toBe('true');
    await button(wrapper, 'Section design').trigger('click');
    await field(wrapper, 'Background').setValue('dark');
    await field(wrapper, 'Spacing').setValue('relaxed');
    await field(wrapper, 'Alignment').setValue('center');
    const sectionBody = wrapper.findAll('.canvas-section-body')[0];
    expect(sectionBody.classes()).toEqual(expect.arrayContaining(['section-bg-dark', 'canvas-spacing-relaxed', 'section-align-center']));
    await wrapper.get('[aria-label="Collapse section 1 layers"]').trigger('click');
    expect(wrapper.find('[aria-label="Select Heading module 2 in section 1"]').exists()).toBe(false);
    expect(wrapper.findAll('.canvas-block')).toHaveLength(3);
    await wrapper.get('[aria-label="Expand section 1 layers"]').trigger('click');
    expect(wrapper.find('[aria-label="Select Heading module 2 in section 1"]').exists()).toBe(true);
    await button(wrapper, 'Save draft').trigger('click'); await flushPromises();
    expect(put.mock.calls[0][1].document.sections[0].settings).toMatchObject({ background: 'dark', spacing: 'relaxed', align: 'center' });
  });

  it('shares responsive width between the editing canvas and draft preview without dirtying content', async () => {
    const { wrapper } = await editor();
    await button(wrapper, 'Mobile').trigger('click');
    expect(wrapper.get('.builder-canvas').element.style.width).toBe('375px');
    expect(button(wrapper, 'Mobile').attributes('aria-pressed')).toBe('true');
    await button(wrapper, 'Preview').trigger('click');
    expect(wrapper.get('.preview-content').element.style.width).toBe('375px');
    await field(wrapper, 'Viewport').setValue('1280px');
    await button(wrapper, 'Edit page').trigger('click');
    expect(wrapper.get('.builder-canvas').element.style.width).toBe('1280px');
    await button(wrapper, 'Tablet').trigger('click');
    expect(wrapper.get('.builder-canvas').element.style.width).toBe('768px');
    expect(wrapper.get('.editor-status').text()).toContain('All changes saved');
    expect(button(wrapper, 'Undo').element.disabled).toBe(true);
  });

  it('searches the persistent palette while retaining layout choices and compatibility checks', async () => {
    const { wrapper } = await editor();
    await field(wrapper, 'Search available modules').setValue('Paragraph');
    expect(wrapper.findAll('.palette-item')).toHaveLength(1);
    expect(wrapper.findAll('.layout-choice').length).toBeGreaterThan(2);
    await wrapper.findAll('.section-select')[1].trigger('click');
    expect(wrapper.get('[aria-label="Add Paragraph block"]').element.disabled).toBe(true);
    await field(wrapper, 'Search available modules').setValue('missing');
    expect(wrapper.get('.palette-no-matches').text()).toBe('No matching modules.');
  });

  it('preserves text changed during an in-flight save and saves the newer value next', async () => {
    const { wrapper, initial } = await editor();
    let finish;
    put.mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
    await wrapper.findAll('.canvas-inline-edit')[0].trigger('click');
    await wrapper.get('.inline-text-editor textarea').setValue('First edit');
    await button(wrapper, 'Save draft').trigger('click');
    const sent = clone(put.mock.calls[0][1]);
    await wrapper.get('.inline-text-editor textarea').setValue('Newer edit while saving');
    finish({ page: { ...clone(initial), ...sent, version: 2 } }); await flushPromises();
    expect(wrapper.get('.inline-text-editor textarea').element.value).toBe('Newer edit while saving');
    expect(wrapper.get('.editor-status').text()).toContain('Unsaved changes');
    await button(wrapper, 'Save draft').trigger('click'); await flushPromises();
    expect(put.mock.calls[1][1].document.sections[0].blocks[0].props.text).toBe('Newer edit while saving');
    expect(put.mock.calls[1][1].version).toBe(2);
  });

  it('moves modules between sections in both directions through native drag events', async () => {
    const { wrapper, initial } = await editor();
    await wrapper.findAll('.layout-choice').find(item => item.text().includes('Two columns')).trigger('click');
    const transfer = { setData: vi.fn(), effectAllowed: '' };
    const sections = () => wrapper.findAll('.canvas-section');
    await sections()[0].findAll('.block-drag')[1].trigger('dragstart', { dataTransfer: transfer });
    await sections()[2].get('.section-end').trigger('drop');
    expect(sections()[2].get('.canvas-block').attributes('data-block-id')).toBe(initial.document.sections[0].blocks[1].id);
    await sections()[2].get('.block-drag').trigger('dragstart', { dataTransfer: transfer });
    await sections()[0].get('.section-end').trigger('drop');
    expect(sections()[0].findAll('.canvas-block')).toHaveLength(2);
    expect(sections()[2].findAll('.canvas-block')).toHaveLength(0);
    await sections()[0].findAll('.block-drag')[1].trigger('dragstart', { dataTransfer: transfer });
    await sections()[2].get('.section-end').trigger('drop');
    expect(sections()[2].get('.canvas-block').attributes('data-block-id')).toBe(initial.document.sections[0].blocks[1].id);
  });

  it('allows a viewer to inspect modes and layers but disables content mutations', async () => {
    const { wrapper } = await editor('viewer');
    expect(wrapper.find('.canvas-inline-edit').exists()).toBe(false);
    expect(wrapper.get('[aria-label="Add module to section 1"]').element.disabled).toBe(true);
    expect(wrapper.get('[aria-label="Insert section after section 1"]').element.disabled).toBe(true);
    expect(button(wrapper, 'Save draft').element.disabled).toBe(true);
    await button(wrapper, 'Wireframe view').trigger('click');
    await button(wrapper, 'Layers').trigger('click');
    await wrapper.get('[aria-label="Select Paragraph module 1 in section 1"]').trigger('click');
    expect(field(wrapper, 'Paragraph text').element.disabled).toBe(true);
    expect(wrapper.findAll('.wireframe-summary')).toHaveLength(3);
    expect(put).not.toHaveBeenCalled();
  });

  it('uses the public plugin registry APIs for custom layouts, insertion, rendering, fields, layers and saving', async () => {
    const { wrapper } = await editor();
    const type = 'test/visual-builder/notice', layout = 'test/visual-builder/notices';
    registerBlock({ id: type, label: 'Partner notice', defaults: { text: 'Plugin default' }, fields: [{ key: 'text', label: 'Notice text', type: 'text', max: 120 }] });
    registerBlockRenderer(type, { props: ['props'], setup: props => () => h('p', { class: 'partner-notice' }, props.props.text) });
    registerLayout({ id: layout, label: 'Partner notices', slots: ['default'], allowedBlocks: [type], responsive: { mobile: 1, tablet: 1, desktop: 2 }, maxBlocks: 4 });
    await flushPromises();
    expect(wrapper.find('[aria-label="Add Partner notice block"]').exists()).toBe(true);
    await wrapper.get('[aria-label="Insert section after section 1"]').trigger('click');
    await searchPicker('Partner notices');
    dialogButton('Insert Partner notices').click(); await flushPromises();
    await wrapper.get('[aria-label="Add module to section 2"]').trigger('click');
    expect(dialogButton('Insert Paragraph').disabled).toBe(true);
    dialogButton('Insert Partner notice').click(); await flushPromises();
    await field(wrapper, 'Notice text').setValue('A third-party message');
    expect(wrapper.get('.partner-notice').text()).toBe('A third-party message');
    await button(wrapper, 'Layers').trigger('click');
    expect(wrapper.get('[aria-label="Select Partner notice module 1 in section 2"]').text()).toContain('A third-party message');
    await button(wrapper, 'Save draft').trigger('click'); await flushPromises();
    expect(put.mock.calls[0][1].document.sections[1]).toMatchObject({ layout, blocks: [{ type, props: { text: 'A third-party message' } }] });
  });
});
