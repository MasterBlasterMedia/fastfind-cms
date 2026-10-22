import { canPlace, clone, layoutDefinition, uid } from './registry';

export function duplicateBlock(block) { return { ...clone(block), id: uid() }; }
export function duplicateSection(section) { return { ...clone(section), id: uid(), blocks: section.blocks.map(duplicateBlock) }; }
export function moveSection(document, from, to) {
  if (from < 0 || from >= document.sections.length || to < 0 || to >= document.sections.length || from === to) return false;
  document.sections.splice(to, 0, document.sections.splice(from, 1)[0]);
  return true;
}
export function moveBlock(document, fromSection, fromIndex, toSection, toIndex) {
  const source = document.sections[fromSection];
  const target = document.sections[toSection];
  const block = source?.blocks[fromIndex];
  if (!block || !target || !canPlace(target, block.type, source === target ? 0 : 1)) return false;
  const insertion = Math.max(0, Math.min(toIndex, target.blocks.length));
  source.blocks.splice(fromIndex, 1);
  target.blocks.splice(source === target && fromIndex < insertion ? insertion - 1 : insertion, 0, block);
  return true;
}
export function changeLayout(section, layout) {
  const definition = layoutDefinition(layout);
  if (!definition || section.blocks.length > definition.maxBlocks || section.blocks.some(b => definition.allowedBlocks && !definition.allowedBlocks.includes(b.type))) return false;
  section.layout = layout;
  return true;
}
export function pagePayload(page) {
  return clone({ version: page.version, title: page.title, slug: page.slug, summary: page.summary, show_in_search: !!page.show_in_search, document: page.document, extensions: page.extensions || {} });
}
export function contentFingerprint(page) {
  if (!page) return '';
  const value = pagePayload(page);
  delete value.version;
  return JSON.stringify(value);
}
// Server state advances even when the user kept typing. Preserve those local edits.
export function applySaveResponse(current, sent, saved) {
  if (contentFingerprint(current) === contentFingerprint(sent)) return clone(saved);
  return { ...clone(saved), ...pagePayload(current), version: saved.version, status: saved.status, published_at: saved.published_at };
}
