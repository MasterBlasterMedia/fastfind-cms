import { describe, expect, it } from 'vitest';
import { newSection, starterBlock, safeUrl, videoEmbed, canPlace, layoutStyle } from '../../resources/js/builder/registry';
import { duplicateSection, moveSection, moveBlock, changeLayout, pagePayload, contentFingerprint, applySaveResponse } from '../../resources/js/builder/operations';

const page = () => ({ version: 1, title: 'Original', slug: 'original', summary: '', show_in_search: true, document: { sections: [newSection()] }, extensions: { 'fastfind-seo': { title: 'SEO' } } });

describe('builder editing and asynchronous saves', () => {
  it('keeps edits typed while saving and adopts the server concurrency version', () => {
    const sent = page();
    const current = structuredClone(sent);
    current.title = 'Typed during save';
    current.extensions['fastfind-seo'].title = 'New SEO';
    const saved = { ...sent, title: 'Server normalized', version: 2, status: 'draft', published_at: null };
    const result = applySaveResponse(current, sent, saved);
    expect(result.title).toBe('Typed during save');
    expect(result.extensions['fastfind-seo'].title).toBe('New SEO');
    expect(result.version).toBe(2);
    expect(result.status).toBe('draft');
    expect(current.version).toBe(1);
  });
  it('accepts normalized saved content when nothing changed in flight', () => {
    const sent = page();
    const saved = { ...sent, title: 'Normalized', version: 2 };
    expect(applySaveResponse(sent, sent, saved)).toEqual(saved);
    expect(applySaveResponse(sent, sent, saved)).not.toBe(saved);
  });
  it('ignores version bumps for dirty state but detects plugin field changes', () => {
    const first = page();
    const second = structuredClone(first);
    second.version++;
    expect(contentFingerprint(second)).toBe(contentFingerprint(first));
    second.extensions['fastfind-seo'].title = 'Changed';
    expect(contentFingerprint(second)).not.toBe(contentFingerprint(first));
  });
  it('sends an independent payload without server-only fields', () => {
    const current = { ...page(), published_document: { private: true }, status: 'published' };
    const payload = pagePayload(current);
    payload.document.sections.length = 0;
    expect(current.document.sections).toHaveLength(1);
    expect(payload).not.toHaveProperty('published_document');
    expect(payload).not.toHaveProperty('status');
  });
  it('duplicates sections with fresh IDs and independent nested block values', () => {
    const section = newSection();
    section.blocks = [starterBlock('card')];
    const copy = duplicateSection(section);
    expect(copy.id).not.toBe(section.id);
    expect(copy.blocks[0].id).not.toBe(section.blocks[0].id);
    copy.blocks[0].props.title = 'Copy only';
    expect(section.blocks[0].props.title).not.toBe('Copy only');
  });
  it('moves blocks across compatible sections and refuses incompatible drops without losing content', () => {
    const source = newSection(); source.blocks = [starterBlock('text')];
    const gallery = newSection('gallery');
    const target = newSection();
    const document = { sections: [source, gallery, target] };
    expect(moveBlock(document, 0, 0, 1, 0)).toBe(false);
    expect(source.blocks).toHaveLength(1);
    expect(moveBlock(document, 0, 0, 2, 0)).toBe(true);
    expect(source.blocks).toHaveLength(0);
    expect(target.blocks[0].type).toBe('text');
  });
  it('reorders within a section without dropping or duplicating blocks', () => {
    const section = newSection(); section.blocks = [starterBlock('heading'), starterBlock('text'), starterBlock('button')];
    expect(moveBlock({ sections: [section] }, 0, 0, 0, 3)).toBe(true);
    expect(section.blocks.map(block => block.type)).toEqual(['text', 'button', 'heading']);
  });
  it('refuses full or incompatible layout changes and invalid section moves', () => {
    const section = newSection(); section.blocks = [starterBlock('text')];
    expect(changeLayout(section, 'tabs')).toBe(false);
    expect(section.layout).toBe('stack');
    expect(changeLayout(section, 'two-columns')).toBe(true);
    const full = newSection('tabs'); full.blocks = Array.from({ length: 12 }, () => starterBlock('tab-content'));
    expect(canPlace(full, 'tab-content')).toBe(false);
    const document = { sections: [section, full] };
    expect(moveSection(document, -1, 1)).toBe(false);
    expect(moveSection(document, 0, 2)).toBe(false);
    expect(moveSection(document, 0, 1)).toBe(true);
    expect(document.sections[0]).toBe(full);
    expect(layoutStyle(section)).toEqual({ '--columns-mobile': 1, '--columns-tablet': 2, '--columns-desktop': 2 });
  });
});

describe('rendering URL boundary', () => {
  it.each(['javascript:alert(1)', 'data:text/html,<script>x</script>', '//evil.test/image', '/\\evil.test', 'https://user:secret@example.test/', 'https://example.test/a b', '\u0000https://example.test'])('rejects unsafe URL %s', value => expect(safeUrl(value)).toBe(''));
  it.each(['/pages/about', '#details', 'https://example.test/image.png', 'http://example.test/'])('allows safe destination %s', value => expect(safeUrl(value)).toBe(value));
  it('disallows fragment-only image sources', () => expect(safeUrl('#details', { image: true })).toBe(''));
  it('restricts video embeds to supported provider IDs', () => {
    expect(videoEmbed('https://youtu.be/dQw4w9WgXcQ')).toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
    expect(videoEmbed('https://vimeo.com/123456')).toBe('https://player.vimeo.com/video/123456');
    expect(videoEmbed('https://youtube.com.attacker.test/watch?v=dQw4w9WgXcQ')).toBe('');
    expect(videoEmbed('https://youtube.com/watch?v=<script>')).toBe('');
  });
});
