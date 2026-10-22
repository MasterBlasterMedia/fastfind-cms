import { h } from 'vue';
import definition from '../block.json';
// Paths below are correct after copying this example into plugins/acme-notice.
import { registerBlock } from '../../../resources/js/builder/registry.js';
import { registerBlockRenderer } from '../../../resources/js/builder/renderers.js';

export const pluginId = 'acme/notice';
export const views = {};
export function register() {
  registerBlock(definition);
  registerBlockRenderer(definition.id, {
    props: ['props'],
    setup: component => () => h('aside', { class: 'content-callout' }, component.props.text),
  });
}
