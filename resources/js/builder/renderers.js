import { h, shallowReactive } from 'vue';
import { cells, lines, safeUrl, videoEmbed } from './registry';

const renderers = shallowReactive(new Map());
export function registerBlockRenderer(id, component) {
  if (renderers.has(id)) throw new Error(`Renderer already registered for ${id}.`);
  renderers.set(id, component);
}
export const blockRenderer = (id) => renderers.get(id);
export function forgetPluginRenderers(pluginId) {
  if (!/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/.test(pluginId)) return;
  for (const id of renderers.keys()) if (id.startsWith(`${pluginId}/`)) renderers.delete(id);
}
const paragraph = text => h('p', { class: 'body-copy' }, text);
const link = (url, text, className = 'btn primary') => safeUrl(url) ? h('a', { href: safeUrl(url), class: className }, text || 'Learn more') : h('span', { class: className }, text || 'Add a destination');
const image = p => h('figure', { class: 'image-block' }, [safeUrl(p.src, { image: true }) ? h('img', { src: safeUrl(p.src, { image: true }), alt: p.alt || '', loading: 'lazy' }) : h('div', { class: 'image-placeholder' }, 'No image selected'), p.caption ? h('figcaption', p.caption) : null]);
const card = p => h('article', { class: 'content-card' }, [h('h3', p.title), paragraph(p.text), p.url ? link(p.url, p.linkText || 'Read more', 'text-link') : null]);
const faq = p => h('details', { class: 'content-faq' }, [h('summary', p.question), paragraph(p.answer)]);
const core = {
  heading: p => h([1, 2, 3, 4].includes(Number(p.level)) ? `h${p.level}` : 'h2', p.text),
  text: p => paragraph(p.text),
  image,
  gallery: p => h('div', { class: 'block-gallery', style: { '--gallery-columns': Math.max(1, Math.min(4, Number(p.columns) || 3)) } }, lines(p.items).map(row => { const [src, alt, caption] = cells(row); return image({ src, alt, caption }); })),
  video: p => videoEmbed(p.url) ? h('iframe', { src: videoEmbed(p.url), class: 'video-embed', title: p.title || 'Video', loading: 'lazy', referrerpolicy: 'no-referrer', sandbox: 'allow-scripts allow-same-origin allow-presentation', allow: 'fullscreen; picture-in-picture', allowfullscreen: true }) : paragraph('Add a supported YouTube or Vimeo URL.'),
  button: p => link(p.url, p.text),
  'button-group': p => h('div', { class: 'button-group' }, lines(p.items).map(row => { const [label, url] = cells(row); return link(url, label); })),
  card,
  'card-collection': p => h('div', { class: 'card-collection' }, lines(p.items).map(row => { const [title, text, url] = cells(row); return card({ title, text, url }); })),
  divider: () => h('hr', { class: 'content-divider' }),
  spacer: p => h('div', { 'aria-hidden': 'true', style: { height: `${Math.max(8, Math.min(160, Number(p.height) || 32))}px` } }),
  quote: p => h('blockquote', [paragraph(p.text), p.by ? h('cite', `— ${p.by}`) : null]),
  list: p => h('div', [p.title ? h('h3', p.title) : null, h(p.ordered ? 'ol' : 'ul', { class: 'feature-list' }, lines(p.items).map(item => h('li', item)))]),
  table: p => { const headers = cells(p.headers); return h('div', { class: 'content-table-scroll', tabindex: 0, role: 'region', 'aria-label': p.caption || 'Table' }, [h('table', { class: 'content-table' }, [h('caption', p.caption), h('thead', [h('tr', headers.map(title => h('th', { scope: 'col' }, title)))]), h('tbody', lines(p.rows).map(row => { const values = cells(row); return h('tr', headers.map((_, index) => h('td', values[index] || ''))); }))])]); },
  icon: p => h('span', { class: 'content-icon', role: 'img', 'aria-label': p.label || 'Icon' }, ({ star: '✦', check: '✓', arrow: '↗', heart: '♥', info: 'ⓘ' })[p.symbol] || '✦'),
  callout: p => h('aside', { class: 'content-callout' }, [h('span', { class: 'callout-symbol', 'aria-hidden': 'true' }, '✦'), h('div', [h('strong', p.title), paragraph(p.text)])]),
  cta: p => h('div', { class: 'content-cta' }, [h('h2', p.title), paragraph(p.text), link(p.url, p.textButton)]),
  code: p => h('figure', { class: 'content-code' }, [p.language ? h('figcaption', p.language) : null, h('pre', { tabindex: 0 }, [h('code', p.code)])]),
  'accordion-item': faq,
  'tab-content': p => h('div', [h('h3', p.title), paragraph(p.text)]),
  stat: p => h('div', { class: 'content-stat' }, [h('strong', p.value), h('span', p.label)]),
  faq,
};
Object.entries(core).forEach(([id, render]) => registerBlockRenderer(id, { props: ['props'], setup: component => () => render(component.props || {}) }));
