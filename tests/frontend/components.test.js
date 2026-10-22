import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import Block from '../../resources/js/components/Block.vue';
import SectionLayout from '../../resources/js/components/SectionLayout.vue';
import SchemaFields from '../../resources/js/components/SchemaFields.vue';
import { blockDefinitions, newSection, starterBlock } from '../../resources/js/builder/registry';

const wrappers = [];
const render = (component, props) => { const wrapper = mount(component, { props, attachTo: document.body }); wrappers.push(wrapper); return wrapper; };
afterEach(() => wrappers.splice(0).forEach(wrapper => wrapper.unmount()));

describe('content rendering', () => {
  it.each(blockDefinitions().map(definition => definition.id))('renders registered %s content', type => {
    const wrapper = render(Block, { block: starterBlock(type) });
    expect(wrapper.find('.unsupported-block').exists()).toBe(false);
    expect(wrapper.attributes('data-block-type')).toBe(type);
  });
  it('renders text and code as text instead of executing embedded HTML', () => {
    for (const type of ['text', 'code']) {
      const value = '<img src=x onerror=alert(1)><script>bad()</script>';
      const wrapper = render(Block, { block: { id: type, type, props: { text: value, code: value } } });
      expect(wrapper.text()).toContain(value);
      expect(wrapper.find('script,img').exists()).toBe(false);
    }
  });
  it('does not render unsafe button destinations or image sources', () => {
    const button = render(Block, { block: { type: 'button', props: { text: 'Unsafe', url: 'javascript:alert(1)' } } });
    const image = render(Block, { block: { type: 'image', props: { src: '//attacker.test/tracker' } } });
    expect(button.find('a').exists()).toBe(false);
    expect(image.find('img').exists()).toBe(false);
  });
  it('provides a readable fallback for a removed plugin renderer', () => {
    const wrapper = render(Block, { block: { type: 'removed-plugin', props: {} } });
    expect(wrapper.get('[role=status]').text()).toContain('unavailable');
  });
  it('supports keyboard tab navigation with matching labels and selected panel', async () => {
    const section = newSection('tabs'); section.blocks = [starterBlock('tab-content'), starterBlock('tab-content')];
    const wrapper = render(SectionLayout, { section });
    const tabs = wrapper.findAll('[role=tab]');
    expect(tabs[0].attributes('aria-selected')).toBe('true');
    await tabs[0].trigger('keydown', { key: 'ArrowRight' });
    expect(tabs[1].attributes('aria-selected')).toBe('true');
    expect(tabs[1].attributes('tabindex')).toBe('0');
    expect(document.activeElement).toBe(tabs[1].element);
    const panels = wrapper.findAll('[role=tabpanel]');
    expect(panels[1].element.hidden).toBe(false);
    expect(panels[0].element.hidden).toBe(true);
    expect(tabs[1].attributes('aria-controls')).toBe(panels[1].attributes('id'));
    await tabs[1].trigger('keydown', { key: 'Home' });
    expect(tabs[0].attributes('aria-selected')).toBe('true');
    await tabs[0].trigger('keydown', { key: 'End' });
    expect(tabs[1].attributes('aria-selected')).toBe('true');
  });
  it('announces manual carousel changes and wraps at the last slide', async () => {
    const section = newSection('carousel'); section.blocks = [starterBlock('card'), starterBlock('card')];
    const wrapper = render(SectionLayout, { section });
    expect(wrapper.get('[role=status]').text()).toBe('Slide 1 of 2');
    await wrapper.get('[aria-label="Previous slide"]').trigger('click');
    expect(wrapper.get('[role=status]').text()).toBe('Slide 2 of 2');
    await wrapper.get('[aria-label="Next slide"]').trigger('click');
    expect(wrapper.get('[role=status]').text()).toBe('Slide 1 of 2');
  });
  it('edits typed plugin fields and disables them when permissions require it', async () => {
    const model = reactive({ title: '', enabled: false, limit: 2 });
    const fields = [{ key: 'title', label: 'SEO title', type: 'text', max: 100 }, { key: 'enabled', label: 'Enabled', type: 'boolean' }, { key: 'limit', label: 'Limit', type: 'number', min: 1, max: 10 }];
    const wrapper = render(SchemaFields, { fields, model });
    await wrapper.get('input[type=text]').setValue('A new title');
    await wrapper.get('input[type=checkbox]').setValue(true);
    await wrapper.get('input[type=number]').setValue('4');
    expect(model).toEqual({ title: 'A new title', enabled: true, limit: 4 });
    await wrapper.setProps({ disabled: true });
    expect(wrapper.findAll('input').every(input => input.element.disabled)).toBe(true);
  });
});
