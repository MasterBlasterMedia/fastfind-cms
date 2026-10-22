<script setup>
import { computed, inject, onMounted, onUnmounted, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRoute } from 'vue-router';
import { get, put, post, failure } from '../lib';
import { blockDefinitions, blockDefinition, layoutDefinitions, layoutDefinition, starterBlock, newSection, canPlace, layoutStyle } from '../builder/registry';
import { duplicateBlock, duplicateSection, moveSection as reorderSection, moveBlock as reorderBlock, changeLayout, pagePayload, contentFingerprint, applySaveResponse } from '../builder/operations';
import StudioFrame from '../components/StudioFrame.vue';
import ContentPage from '../components/ContentPage.vue';
import Block from '../components/Block.vue';
import SchemaFields from '../components/SchemaFields.vue';
import AppDialog from '../components/AppDialog.vue';
const props = defineProps({ user: { type: Object, required: true } });
const emit = defineEmits(['logout']);
const route = useRoute();
const { toast, extensions = ref({ page_fields: [] }) } = inject('session');
const page = ref(null), loading = ref(true), busy = ref(false), error = ref(''), preview = ref(false);
const tab = ref('content'), selected = ref({ kind: 'section', id: null }), drag = ref(null);
const showHistory = ref(false), revisions = ref([]), media = ref([]), savedContent = ref('');
const undoStack = ref([]), redoStack = ref([]), announcement = ref(''), previewWidth = ref('100%');
let pauseHistory = false;
let ignoreFingerprint = '';
const canEdit = computed(() => ['owner', 'admin', 'editor'].includes(props.user.role));
const sections = computed(() => page.value?.document?.sections || []);
const activeBlock = computed(() => selected.value.kind === 'block' ? sections.value.flatMap(s => s.blocks).find(b => b.id === selected.value.id) : null);
const activeSection = computed(() => selected.value.kind === 'section' ? sections.value.find(s => s.id === selected.value.id) : sections.value.find(s => s.blocks.some(b => b.id === selected.value.id)));
const fingerprint = computed(() => contentFingerprint(page.value));
const dirty = computed(() => !!page.value && fingerprint.value !== savedContent.value);
const definitions = computed(blockDefinitions), layoutOptions = computed(layoutDefinitions);
const pageFields = computed(() => extensions.value.page_fields || []);
watch(fingerprint, (value, old) => {
  if (pauseHistory || value === ignoreFingerprint || !old || !value || value === old) return;
  undoStack.value.push(old); if (undoStack.value.length > 50) undoStack.value.shift(); redoStack.value = [];
}, { flush: 'post' });
function normalize(value) {
  if (!value.extensions || Array.isArray(value.extensions)) value.extensions = {};
  pageFields.value.forEach(group => { value.extensions[group.plugin_id] ||= {}; group.fields.forEach(field => { if (value.extensions[group.plugin_id][field.key] === undefined && field.default !== undefined) value.extensions[group.plugin_id][field.key] = field.default; }); });
  value.document.sections.forEach(section => { section.settings = { background: 'plain', width: 'normal', spacing: 'normal', align: 'left', ...section.settings }; });
  return value;
}
function setLoaded(value) {
  pauseHistory = true; page.value = normalize(value); savedContent.value = contentFingerprint(page.value); undoStack.value = []; redoStack.value = []; ignoreFingerprint = contentFingerprint(page.value); pauseHistory = false;
  selected.value = { kind: 'section', id: page.value.document.sections[0]?.id || null };
}
async function load() {
  loading.value = true; error.value = '';
  try { setLoaded((await get('/studio/pages/' + route.params.id)).page); }
  catch (e) { error.value = failure(e); } finally { loading.value = false; }
}
function unload(event) { if (dirty.value) { event.preventDefault(); event.returnValue = ''; } }
function shortcut(event) {
  if (!(event.ctrlKey || event.metaKey)) return;
  if (event.key.toLowerCase() === 's') { event.preventDefault(); save(); }
}
onMounted(() => { load(); window.addEventListener('beforeunload', unload); window.addEventListener('keydown', shortcut); });
onUnmounted(() => { window.removeEventListener('beforeunload', unload); window.removeEventListener('keydown', shortcut); });
onBeforeRouteLeave(() => !dirty.value || window.confirm('Leave this page and discard unsaved changes?'));
function selectSection(section) { selected.value = { kind: 'section', id: section.id }; tab.value = 'content'; }
function selectBlock(block) { selected.value = { kind: 'block', id: block.id }; tab.value = 'content'; }
function announce(message) { announcement.value = message; }
function appendSection(layout = 'stack') {
  if (!canEdit.value || sections.value.length >= 40) return;
  const section = newSection(layout); sections.value.push(section); selectSection(section); announce('Section added.');
}
function appendBlock(type) {
  if (!canEdit.value) return;
  if (!sections.value.length) appendSection();
  const section = activeSection.value || sections.value.at(-1);
  if (!canPlace(section, type) || sections.value.reduce((sum, item) => sum + item.blocks.length, 0) >= 300) { toast('This block is not supported here, or the content limit has been reached.'); return; }
  const block = starterBlock(type); section.blocks.push(block); selectBlock(block); announce(`${blockDefinition(type).label} added.`);
}
function cloneBlock(si, bi) {
  if (!canEdit.value || !canPlace(sections.value[si], sections.value[si].blocks[bi].type)) return;
  const block = duplicateBlock(sections.value[si].blocks[bi]); sections.value[si].blocks.splice(bi + 1, 0, block); selectBlock(block); announce('Block duplicated.');
}
function cloneSection(si) { if (!canEdit.value || sections.value.length >= 40) return; const section = duplicateSection(sections.value[si]); sections.value.splice(si + 1, 0, section); selectSection(section); announce('Section duplicated.'); }
function removeBlock(si, bi) { if (!canEdit.value) return; sections.value[si].blocks.splice(bi, 1); selectSection(sections.value[si]); announce('Block deleted. Undo is available.'); }
function removeSection(si) { if (!canEdit.value) return; sections.value.splice(si, 1); selected.value = { kind: 'section', id: sections.value[0]?.id }; announce('Section deleted. Undo is available.'); }
function moveSection(from, to) { if (canEdit.value && reorderSection(page.value.document, from, to)) announce(`Section moved to position ${to + 1}.`); }
function moveBlock(si, bi, toSi, toBi) {
  if (!canEdit.value) return;
  const block = sections.value[si]?.blocks[bi];
  if (!reorderBlock(page.value.document, si, bi, toSi, toBi)) { toast('This section does not accept that block, or it is full.'); return; }
  selectBlock(block); announce('Block moved.');
}
function moveKeyboardBlock(si, bi, delta) { const target = bi + delta; if (target < 0 || target >= sections.value[si].blocks.length) return; moveBlock(si, bi, si, target + (delta > 0 ? 1 : 0)); }
function moveToSection(event) { const target = Number(event.target.value); const si = sections.value.indexOf(activeSection.value); const bi = activeSection.value.blocks.indexOf(activeBlock.value); moveBlock(si, bi, target, sections.value[target].blocks.length); event.target.value = ''; }
function selectLayout(event) { if (!changeLayout(activeSection.value, event.target.value)) { event.target.value = activeSection.value.layout; toast('Move incompatible blocks out of this section before changing its layout.'); } }
function setDrag(event, type, si, bi) { if (!canEdit.value) return; drag.value = { type, si, bi }; event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', 'fastfind'); }
function dropOnSection(event, to) { event.preventDefault(); if (!drag.value) return; if (drag.value.type === 'section') moveSection(drag.value.si, to); else moveBlock(drag.value.si, drag.value.bi, to, sections.value[to].blocks.length); drag.value = null; }
function dropOnBlock(event, si, bi) { event.preventDefault(); event.stopPropagation(); if (drag.value?.type === 'block') moveBlock(drag.value.si, drag.value.bi, si, bi); else if (drag.value?.type === 'section') moveSection(drag.value.si, si); drag.value = null; }
function undo() { if (!canEdit.value || !undoStack.value.length || busy.value) return; const previous = undoStack.value.pop(); redoStack.value.push(fingerprint.value); pauseHistory = true; page.value = { ...page.value, ...JSON.parse(previous) }; ignoreFingerprint = contentFingerprint(page.value); pauseHistory = false; announce('Change undone.'); }
function redo() { if (!canEdit.value || !redoStack.value.length || busy.value) return; const next = redoStack.value.pop(); undoStack.value.push(fingerprint.value); pauseHistory = true; page.value = { ...page.value, ...JSON.parse(next) }; ignoreFingerprint = contentFingerprint(page.value); pauseHistory = false; announce('Change redone.'); }
async function save() {
  if (!canEdit.value || busy.value || !page.value) return false;
  busy.value = true; error.value = ''; const sent = pagePayload(page.value);
  try {
    const saved = normalize((await put('/studio/pages/' + page.value.id, sent)).page);
    pauseHistory = true; page.value = applySaveResponse(page.value, sent, saved); savedContent.value = contentFingerprint(saved); ignoreFingerprint = contentFingerprint(page.value); pauseHistory = false;
    toast(dirty.value ? 'Saved. Your more recent edits are still unsaved.' : 'Draft saved successfully.'); return true;
  } catch (e) { error.value = failure(e); toast(error.value); return false; } finally { busy.value = false; }
}
async function stateChange(action) {
  if (busy.value || !canEdit.value || !await save()) return;
  if (dirty.value) { toast('New edits were made during the save. Save them before publishing.'); return; }
  busy.value = true; const sent = pagePayload(page.value);
  try {
    const saved = normalize((await post(`/studio/pages/${page.value.id}/${action}`, { version: page.value.version })).page);
    pauseHistory = true; page.value = applySaveResponse(page.value, sent, saved); savedContent.value = contentFingerprint(saved); ignoreFingerprint = contentFingerprint(page.value); pauseHistory = false;
    toast(action === 'publish' ? 'Your saved page is now live.' : 'Page unpublished.');
  } catch (e) { error.value = failure(e); toast(error.value); } finally { busy.value = false; }
}
async function history() { try { revisions.value = (await get(`/studio/pages/${page.value.id}/revisions`)).revisions; showHistory.value = true; } catch (e) { toast(failure(e)); } }
async function restoreVersion(version) {
  if (!canEdit.value || busy.value || !window.confirm('Restore this revision as a new draft? Unsaved changes will be replaced.')) return;
  busy.value = true; try { setLoaded((await post(`/studio/pages/${page.value.id}/restore`, { revision_version: version, version: page.value.version })).page); showHistory.value = false; toast('Revision restored as a draft.'); } catch (e) { error.value = failure(e); } finally { busy.value = false; }
}
async function openMedia() { try { media.value = (await get('/studio/media')).media; tab.value = 'media'; } catch (e) { toast(failure(e)); } }
function chooseImage(asset) {
  if (!canEdit.value) return;
  if (activeBlock.value?.type === 'image') { activeBlock.value.props.src = asset.url; activeBlock.value.props.alt = asset.alt || ''; tab.value = 'content'; }
  else { appendBlock('image'); if (activeBlock.value?.type === 'image') { activeBlock.value.props.src = asset.url; activeBlock.value.props.alt = asset.alt || ''; } }
}
function discard() { if (!busy.value && confirm('Discard unsaved changes and reload the latest server version?')) load(); }
</script>
<template>
  <StudioFrame :user="user" page-title="Page builder" subtitle="Create, arrange, preview and publish." @logout="emit('logout')">
    <template #toolbar><RouterLink class="btn outline small" to="/studio/pages">← All pages</RouterLink></template>
    <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>
    <div v-if="loading" class="admin-loading" role="status">Opening the canvas…</div>
    <p v-else-if="error && !page" class="alert error" role="alert">{{ error }}</p>
    <div v-else-if="page" class="editor-wrap">
      <div class="editor-top">
        <div><label class="sr-only" for="editor-title">Page title</label><input id="editor-title" v-model="page.title" :disabled="!canEdit" maxlength="180" class="editor-title" /><div class="editor-status"><span :class="['state-pill', page.status]">{{ page.status }}</span><span role="status">{{ busy ? 'Saving…' : dirty ? 'Unsaved changes' : 'All changes saved' }}</span></div></div>
        <div class="editor-actions"><button class="btn outline small" @click="history">History</button><button class="btn outline small" :aria-pressed="preview" @click="preview = !preview">{{ preview ? 'Edit page' : 'Preview' }}</button><button class="btn outline small" :disabled="busy || !canEdit" @click="save">Save draft</button><button class="btn primary small" :disabled="busy || !canEdit" @click="stateChange('publish')">{{ page.status === 'published' ? 'Republish' : 'Publish' }}</button><button v-if="page.status === 'published'" class="btn outline small" :disabled="busy || !canEdit" @click="stateChange('unpublish')">Unpublish</button></div>
      </div>
      <div class="editor-utility"><button class="btn outline small" :disabled="!undoStack.length || busy || !canEdit" @click="undo">Undo</button><button class="btn outline small" :disabled="!redoStack.length || busy || !canEdit" @click="redo">Redo</button><button class="text-link" :disabled="!dirty || busy" @click="discard">Revert to saved</button><span>Ctrl / ⌘ S to save</span></div>
      <div v-if="error" class="alert error" role="alert">{{ error }}<p>Your edits remain in this editor. Copy important text before reloading a newer server version.</p><button class="btn outline small" @click="discard">Reload latest version</button></div>
      <div v-if="preview" class="live-preview"><div class="preview-ribbon"><span>Draft preview</span><label>Viewport<select v-model="previewWidth"><option value="100%">Available width</option><option value="375px">375px mobile</option><option value="768px">768px tablet</option><option value="1280px">1280px desktop</option><option value="1920px">1920px large desktop</option></select></label></div><div class="preview-scroll"><div class="preview-content" :style="{ width: previewWidth }"><header><h1>{{ page.title }}</h1><p>{{ page.summary }}</p></header><ContentPage :document="page.document" /></div></div></div>
      <div v-else class="builder-grid">
        <aside class="builder-palette"><div class="builder-aside-header"><h2>Add content</h2><p>Select a section, then add a compatible block.</p></div><div class="palette-grid"><button v-for="definition in definitions" :key="definition.id" class="palette-item" :disabled="!canEdit || (activeSection && !canPlace(activeSection, definition.id))" :aria-label="`Add ${definition.label} block`" @click="appendBlock(definition.id)"><span aria-hidden="true">{{ definition.icon }}</span><small>{{ definition.label }}</small></button></div><div class="palette-bottom"><h3>Section layouts</h3><button v-for="layout in layoutOptions" :key="layout.id" class="layout-choice" :disabled="!canEdit || sections.length >= 40" @click="appendSection(layout.id)"><span aria-hidden="true">▦</span>{{ layout.label }} <b aria-hidden="true">+</b></button></div></aside>
        <div class="builder-center"><div class="canvas-top"><strong>Page canvas</strong><span class="canvas-hint">Drag handles or use move buttons</span></div><div class="builder-canvas">
          <div v-if="!sections.length" class="empty-canvas"><h2>Your page starts here.</h2><button class="btn primary" :disabled="!canEdit" @click="appendSection()">Add first section</button></div>
          <section v-for="(section, si) in sections" :key="section.id" class="canvas-section" :data-section-id="section.id" :data-layout="section.layout" :class="{ 'is-selected': activeSection?.id === section.id }">
            <div class="section-toolbar" @dragover.prevent @drop="dropOnSection($event, si)"><span class="drag-handle" :draggable="canEdit" @dragstart="setDrag($event, 'section', si)" @dragend="drag = null" aria-hidden="true">⠿</span><button class="section-select" @click="selectSection(section)">Section {{ si + 1 }} · {{ layoutDefinition(section.layout)?.label || section.layout }}</button><span class="section-spacer"></span><button class="tiny-control" :aria-label="`Move section ${si + 1} up`" :disabled="!canEdit || si === 0" @click="moveSection(si, si - 1)">↑</button><button class="tiny-control" :aria-label="`Move section ${si + 1} down`" :disabled="!canEdit || si === sections.length - 1" @click="moveSection(si, si + 1)">↓</button><button class="tiny-control" aria-label="Duplicate section" :disabled="!canEdit" @click="cloneSection(si)">⧉</button><button class="tiny-control danger-ink" aria-label="Delete section" :disabled="!canEdit" @click="removeSection(si)">×</button></div>
            <div class="canvas-section-body registered-grid" :style="layoutStyle(section)" :class="['layout-' + section.layout, 'section-bg-' + section.settings.background]" @dragover.prevent @drop="dropOnSection($event, si)">
              <div v-for="(block, bi) in section.blocks" :key="block.id" class="canvas-block" :data-block-id="block.id" :class="{ 'selected-block': activeBlock?.id === block.id }" @click="selectBlock(block)" @dragover.prevent @drop="dropOnBlock($event, si, bi)"><div class="canvas-block-tools"><span class="block-drag" :draggable="canEdit" @dragstart.stop="setDrag($event, 'block', si, bi)" @dragend="drag = null" aria-hidden="true">⠿</span><button class="block-select" :aria-label="`Edit ${blockDefinition(block.type)?.label || block.type} block`" @click.stop="selectBlock(block)">{{ blockDefinition(block.type)?.label || block.type }}</button><span class="spacer"></span><button aria-label="Move block up" :disabled="!canEdit || bi === 0" @click.stop="moveKeyboardBlock(si, bi, -1)">↑</button><button aria-label="Move block down" :disabled="!canEdit || bi === section.blocks.length - 1" @click.stop="moveKeyboardBlock(si, bi, 1)">↓</button><button aria-label="Duplicate block" :disabled="!canEdit" @click.stop="cloneBlock(si, bi)">⧉</button><button aria-label="Delete block" :disabled="!canEdit" @click.stop="removeBlock(si, bi)">×</button></div><div class="canvas-block-preview" inert><Block :block="block" /></div></div>
              <button v-if="!section.blocks.length" class="empty-section" @click="selectSection(section)">Select this empty section to add compatible blocks.</button>
            </div><div class="section-end" @dragover.prevent @drop="dropOnSection($event, si)"><span>Drop compatible blocks here</span><button @click="selectSection(section)">Section properties</button></div>
          </section><button class="add-section-line" :disabled="!canEdit || sections.length >= 40" @click="appendSection()">+ Add section</button>
        </div></div>
        <aside class="builder-properties"><div class="inspector-tabs"><button :aria-pressed="tab === 'content'" :class="{ selected: tab === 'content' }" @click="tab = 'content'">Properties</button><button :aria-pressed="tab === 'page'" :class="{ selected: tab === 'page' }" @click="tab = 'page'">Page</button><button :aria-pressed="tab === 'media'" :class="{ selected: tab === 'media' }" @click="openMedia">Images</button></div>
          <div v-if="tab === 'content'" class="inspector-body"><template v-if="activeBlock"><h2>{{ blockDefinition(activeBlock.type)?.label || activeBlock.type }}</h2><SchemaFields :fields="blockDefinition(activeBlock.type)?.fields || []" :model="activeBlock.props" :disabled="!canEdit" /><label class="move-section-field">Move to section<select :disabled="!canEdit" value="" @change="moveToSection"><option disabled value="">Choose destination</option><option v-for="(section, index) in sections" :key="section.id" :value="index" :disabled="section.id === activeSection.id || !canPlace(section, activeBlock.type)">Section {{ index + 1 }} · {{ layoutDefinition(section.layout)?.label }}</option></select></label><button v-if="activeBlock.type === 'image'" class="btn outline wide" :disabled="!canEdit" @click="openMedia">Choose image</button></template>
            <template v-else-if="activeSection"><h2>Section settings</h2><div class="stack-field"><label>Layout<select :value="activeSection.layout" :disabled="!canEdit" @change="selectLayout"><option v-for="layout in layoutOptions" :key="layout.id" :value="layout.id">{{ layout.label }}</option></select></label><label>Background<select v-model="activeSection.settings.background" :disabled="!canEdit"><option value="plain">Plain white</option><option value="soft">Soft neutral</option><option value="dark">Deep navy</option><option value="accent">Citrus accent</option></select></label><label>Content width<select v-model="activeSection.settings.width" :disabled="!canEdit"><option value="normal">Comfortable</option><option value="wide">Wide</option></select></label><label>Spacing<select v-model="activeSection.settings.spacing" :disabled="!canEdit"><option value="compact">Compact</option><option value="normal">Normal</option><option value="relaxed">Relaxed</option></select></label><label>Alignment<select v-model="activeSection.settings.align" :disabled="!canEdit"><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select></label></div><p class="inspector-tip">{{ layoutDefinition(activeSection.layout)?.description }}</p></template><p v-else>Select a section or block to edit it.</p>
          </div>
          <div v-if="tab === 'page'" class="inspector-body"><h2>Page details</h2><div class="stack-field"><label>URL slug<input v-model="page.slug" :disabled="!canEdit" pattern="[a-z0-9-]+" /></label><label>Page summary<textarea v-model="page.summary" rows="3" maxlength="500" :disabled="!canEdit" /></label><label class="checkline"><input v-model="page.show_in_search" type="checkbox" :disabled="!canEdit" />Include in search</label></div><section v-for="group in pageFields" :key="group.plugin_id" class="extension-fields"><h3>{{ group.label }}</h3><SchemaFields v-if="page.extensions[group.plugin_id]" :fields="group.fields" :model="page.extensions[group.plugin_id]" :disabled="!canEdit" /></section></div>
          <div v-if="tab === 'media'" class="inspector-body"><h2>Image library</h2><div class="media-choices"><button v-for="asset in media" :key="asset.id" :disabled="!canEdit" :aria-label="`Choose ${asset.filename}`" @click="chooseImage(asset)"><img :src="asset.thumbnail_url || asset.url" :alt="asset.alt" /><small>{{ asset.filename }}</small></button></div><p v-if="!media.length">Upload images in the Media library first.</p><RouterLink class="text-link" to="/studio/media">Open media library</RouterLink></div>
        </aside>
      </div>
      <AppDialog v-if="showHistory" title="Revision history" @close="showHistory = false"><p>Restore a saved revision as a new draft.</p><div v-for="revision in revisions" :key="revision.id" class="revision-row"><strong>Version {{ revision.version }}</strong><small>{{ new Date(revision.created_at).toLocaleString() }}</small><button class="btn outline small" :disabled="busy || !canEdit" @click="restoreVersion(revision.version)">Restore version {{ revision.version }}</button></div><p v-if="!revisions.length">No saved revisions.</p></AppDialog>
    </div>
  </StudioFrame>
</template>
