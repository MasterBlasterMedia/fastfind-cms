const status = document.querySelector('#status'), grid = document.querySelector('#releases'), search = document.querySelector('#search'), type = document.querySelector('#type');
let releases = [], metadataUrls = [];
const text = (tag, value, className) => { const node = document.createElement(tag); node.textContent = String(value || ''); if (className) node.className = className; return node; };
const https = value => { try { const url = new URL(value); return url.protocol === 'https:' && !url.username && !url.password ? url.href : null; } catch { return null; } };
function render() {
  metadataUrls.forEach(url => URL.revokeObjectURL(url)); metadataUrls = []; grid.replaceChildren();
  const matches = releases.filter(release => (type.value === 'all' || type.value === release.type) && `${release.manifest.name} ${release.manifest.description} ${release.publisher}`.toLowerCase().includes(search.value.trim().toLowerCase()));
  status.textContent = releases.length ? `${matches.length} ${matches.length === 1 ? 'release' : 'releases'}` : 'No releases have been published yet. Check back when the collection opens.';
  for (const release of matches) {
    const card = text('article', '', 'card');
    card.append(text('span', release.type === 'theme' ? 'Theme' : 'Plugin', 'tag'), text('h3', release.manifest.name), text('p', release.manifest.description), text('small', `By ${release.publisher} · Version ${release.manifest.version}`));
    const download = text('a', 'Download ZIP ↗', 'button'); download.href = https(release.download.url); download.rel = 'noopener noreferrer';
    const metadata = { type: release.type, id: release.manifest.id, version: release.manifest.version, publisher: release.publisher, sha256: release.download.sha256, signature: release.download.signature };
    const metadataUrl = URL.createObjectURL(new Blob([JSON.stringify(metadata, null, 2)], { type: 'application/json' })); metadataUrls.push(metadataUrl);
    const signature = text('a', 'Download signature metadata', 'metadata'); signature.href = metadataUrl; signature.download = `${release.manifest.id.replace(/[^a-z0-9-]/gi, '-')}-${release.manifest.version}.json`;
    card.append(download, signature); grid.append(card);
  }
}
search.addEventListener('input', render); type.addEventListener('change', render);
try {
  const response = await fetch('./catalog.json', { credentials: 'omit' });
  if (!response.ok) throw new Error('Catalog unavailable');
  const catalog = await response.json();
  if (catalog.schema_version !== 1 || !Array.isArray(catalog.themes) || !Array.isArray(catalog.plugins)) throw new Error('Unsupported catalog');
  releases = [...catalog.themes.map(item => ({ ...item, type: 'theme' })), ...catalog.plugins.map(item => ({ ...item, type: 'plugin' }))].filter(item => item.manifest && item.download && https(item.download.url));
  render();
} catch { status.textContent = 'The collection could not be loaded. Please try again later.'; }
