import { safeUrl } from './builder/registry';

export function applyPageHead(head = {}, fallback = {}) {
  document.head.querySelectorAll('meta[name="description"], meta[name="robots"], meta[property^="og:"], meta[name^="twitter:"], link[rel="canonical"], script[type="application/ld+json"]').forEach(element => element.remove());
  document.title = head.title || fallback.title || 'FastFind — Your content, beautifully organized';
  const meta = (name, value) => {
    if (typeof value !== 'string') return;
    const element = document.createElement('meta');
    element.setAttribute(name.startsWith('og:') ? 'property' : 'name', name);
    element.content = value;
    document.head.append(element);
  };
  meta('description', head.description || fallback.description || 'FastFind — discover and publish what matters.');
  for (const [name, value] of Object.entries(head.meta || {})) {
    if (/^(robots|og:(title|description|image|url|type|site_name)|twitter:(card|title|description|image))$/.test(name)) meta(name, value);
  }
  if (/^https?:\/\//.test(head.canonical || '') && safeUrl(head.canonical)) {
    const element = document.createElement('link'); element.rel = 'canonical'; element.href = head.canonical; document.head.append(element);
  }
  if (head.structured_data && typeof head.structured_data === 'object') {
    const element = document.createElement('script'); element.type = 'application/ld+json'; element.textContent = JSON.stringify(head.structured_data); document.head.append(element);
  }
}
