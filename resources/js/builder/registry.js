import schema from '../../content/registry.json';
import { shallowReactive } from 'vue';

const blocks = shallowReactive(new Map());
const layouts = shallowReactive(new Map());
export const clone = (value) => JSON.parse(JSON.stringify(value));
export const uid = () => crypto.randomUUID();

export function registerBlock(definition) {
  if (!definition?.id || !Array.isArray(definition.fields) || !definition.defaults) throw new Error('Block definitions require an ID, fields and defaults.');
  if (blocks.has(definition.id)) throw new Error(`Block ${definition.id} is already registered.`);
  blocks.set(definition.id, Object.freeze(clone(definition)));
}
export function registerLayout(definition) {
  if (!definition?.id || !definition.responsive || !Array.isArray(definition.slots)) throw new Error('Layouts require an ID, responsive behavior and slots.');
  if (layouts.has(definition.id)) throw new Error(`Layout ${definition.id} is already registered.`);
  layouts.set(definition.id, Object.freeze(clone(definition)));
}
export function forgetPluginDefinitions(pluginId) {
  if (!/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/.test(pluginId)) return;
  for (const registry of [blocks, layouts]) {
    for (const id of registry.keys()) if (id.startsWith(`${pluginId}/`)) registry.delete(id);
  }
}
schema.blocks.forEach(registerBlock);
schema.layouts.forEach(registerLayout);
export const blockDefinitions = () => [...blocks.values()];
export const layoutDefinitions = () => [...layouts.values()];
export const blockDefinition = (id) => blocks.get(id);
export const layoutDefinition = (id) => layouts.get(id);
export const starterBlock = (type) => {
  const definition = blockDefinition(type);
  if (!definition) throw new Error(`Unknown block: ${type}`);
  return { id: uid(), type, props: clone(definition.defaults) };
};
export const newSection = (layout = 'stack') => {
  if (!layoutDefinition(layout)) throw new Error(`Unknown layout: ${layout}`);
  return { id: uid(), layout, settings: { background: 'plain', width: 'normal', spacing: 'normal', align: 'left', ...layoutDefinition(layout).defaultSettings }, blocks: [] };
};
export function canPlace(section, type, additional = 1) {
  const definition = layoutDefinition(section?.layout);
  return !!definition && !!blockDefinition(type) && (!definition.allowedBlocks || definition.allowedBlocks.includes(type)) && section.blocks.length + additional <= definition.maxBlocks;
}
export function layoutStyle(section) {
  const responsive = layoutDefinition(section.layout)?.responsive || { mobile: 1, tablet: 1, desktop: 1 };
  return { '--columns-mobile': responsive.mobile, '--columns-tablet': responsive.tablet, '--columns-desktop': responsive.desktop };
}
export function safeUrl(value, { image = false } = {}) {
  if (typeof value !== 'string' || !value || /[\s\\\u0000-\u001f\u007f]/u.test(value) || value.startsWith('//')) return '';
  if (value.startsWith('/')) return value;
  if (!image && value.startsWith('#')) return value;
  try {
    const parsed = new URL(value);
    return ['https:', 'http:'].includes(parsed.protocol) && !parsed.username && !parsed.password ? value : '';
  } catch { return ''; }
}
export function videoEmbed(value) {
  if (!safeUrl(value, { image: true }) || value.startsWith('/')) return '';
  const url = new URL(value);
  const host = url.hostname.toLowerCase().replace(/^www\./, '');
  let id = '';
  if (host === 'youtu.be') id = url.pathname.slice(1);
  if (['youtube.com', 'youtube-nocookie.com'].includes(host)) id = url.pathname.startsWith('/embed/') ? url.pathname.slice(7) : url.searchParams.get('v') || '';
  if (/^[\w-]{11}$/.test(id)) return `https://www.youtube-nocookie.com/embed/${id}`;
  if (host === 'vimeo.com' && /^\/\d+$/.test(url.pathname)) return `https://player.vimeo.com/video${url.pathname}`;
  return '';
}
export const lines = (value) => typeof value === 'string' ? value.split('\n').map(x => x.trim()).filter(Boolean).slice(0, 40) : [];
export const cells = (value) => String(value || '').split('|').map(x => x.trim());
