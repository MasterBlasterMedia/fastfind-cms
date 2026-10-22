import { afterEach, describe, expect, it, vi } from 'vitest';
import { computed, h, nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import { createClientExtensionManager } from '../../resources/js/extensions';
import { blockDefinition, blockDefinitions, forgetPluginDefinitions, layoutDefinition, registerBlock, registerLayout } from '../../resources/js/builder/registry';
import { blockRenderer, forgetPluginRenderers, registerBlockRenderer } from '../../resources/js/builder/renderers';
import Block from '../../resources/js/components/Block.vue';

const pluginId = 'test/lifecycle';
const blockId = `${pluginId}/notice`;
const layoutId = `${pluginId}/grid`;
const definition = { id: blockId, label: 'Notice', defaults: { text: 'Plugin content' }, fields: [{ key: 'text', type: 'text' }] };
const renderer = { props: ['props'], setup: props => () => h('p', props.props.text) };
const wrappers = [];
afterEach(() => { forgetPluginDefinitions(pluginId); forgetPluginRenderers(pluginId); wrappers.splice(0).forEach(wrapper => wrapper.unmount()); });

describe('compiled client extension lifecycle', () => {
  it('does not register inactive modules, cleans up on deactivation, and registers again on reactivation', () => {
    const register = vi.fn(() => {
      registerBlock(definition);
      registerLayout({ id: layoutId, label: 'Custom grid', slots: ['default'], responsive: { mobile: 1, tablet: 2, desktop: 3 }, maxBlocks: 40 });
      registerBlockRenderer(blockId, renderer);
    });
    const view = { template: '<div>Plugin settings</div>' };
    const manager = createClientExtensionManager({ example: { pluginId, register, views: { settings: view } } });
    manager.activate([]);
    expect(register).not.toHaveBeenCalled();
    expect(manager.view(pluginId, 'settings')).toBeUndefined();
    expect(manager.activate([pluginId])).toEqual([]);
    expect(blockDefinition(blockId)).toEqual(definition);
    expect(layoutDefinition(layoutId)).toBeDefined();
    expect(blockRenderer(blockId)).toBe(renderer);
    expect(manager.view(pluginId, 'settings')).toBe(view);
    manager.activate([pluginId]);
    expect(register).toHaveBeenCalledTimes(1);
    manager.activate([]);
    expect(blockDefinition(blockId)).toBeUndefined();
    expect(layoutDefinition(layoutId)).toBeUndefined();
    expect(blockRenderer(blockId)).toBeUndefined();
    expect(manager.view(pluginId, 'settings')).toBeUndefined();
    expect(blockDefinition('heading')).toBeDefined();
    expect(blockRenderer('heading')).toBeDefined();
    expect(manager.activate([pluginId])).toEqual([]);
    expect(register).toHaveBeenCalledTimes(2);
  });

  it('removes a partial registration after a client module throws and can retry later', () => {
    let broken = true;
    const manager = createClientExtensionManager({ example: { pluginId, register: () => { registerBlock(definition); if (broken) throw new Error('Broken module'); registerBlockRenderer(blockId, renderer); } } });
    expect(manager.activate([pluginId])).toEqual([{ pluginId, message: 'Broken module' }]);
    expect(blockDefinition(blockId)).toBeUndefined();
    expect(blockRenderer(blockId)).toBeUndefined();
    broken = false;
    expect(manager.activate([pluginId])).toEqual([]);
    expect(blockRenderer(blockId)).toBe(renderer);
  });

  it('updates mounted content and the palette when its extension is disabled', async () => {
    const manager = createClientExtensionManager({ example: { pluginId, register: () => { registerBlock(definition); registerBlockRenderer(blockId, renderer); } } });
    manager.activate([pluginId]);
    const palette = computed(blockDefinitions);
    expect(palette.value.some(block => block.id === blockId)).toBe(true);
    const wrapper = mount(Block, { props: { block: { id: 'saved-block', type: blockId, props: { text: 'Saved content' } } } });
    wrappers.push(wrapper);
    expect(wrapper.text()).toBe('Saved content');
    manager.activate([]);
    await nextTick();
    expect(palette.value.some(block => block.id === blockId)).toBe(false);
    expect(wrapper.text()).toContain('unavailable');
    manager.activate([pluginId]);
    await nextTick();
    expect(wrapper.text()).toBe('Saved content');
  });
});
