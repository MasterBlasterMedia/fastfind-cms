<script setup>
import { nextTick, ref, watch } from 'vue';
import Block from '../Block.vue';
import { blockDefinition, layoutDefinition, layoutStyle } from '../../builder/registry';
const props = defineProps({ sections: { type: Array, required: true }, activeSectionId: String, activeBlockId: String, canEdit: Boolean, mode: { type: String, default: 'visual' }, width: { type: String, default: '100%' } });
const emit = defineEmits(['section', 'block', 'add-first', 'insert-section', 'insert-module', 'move-section', 'clone-section', 'remove-section', 'move-block', 'clone-block', 'remove-block', 'drag-start', 'drag-end', 'drop-section', 'drop-block', 'text']);
const inlineId = ref(null);
const canvas = ref(null);
watch(() => props.activeBlockId, id => { if (inlineId.value !== id) inlineId.value = null; });
watch(() => props.mode, () => { inlineId.value = null; });
async function editText(block) {
  emit('block', block); inlineId.value = block.id;
  await nextTick(); canvas.value?.querySelector('.inline-text-editor textarea')?.focus();
}
function summary(block) { return String(block.props?.title || block.props?.text || block.props?.alt || blockDefinition(block.type)?.description || 'Select to edit module settings').slice(0, 100); }
function editableText(block) { return props.canEdit && ['heading', 'text'].includes(block.type); }
</script>
<template>
  <div ref="canvas" class="builder-center" :data-canvas-mode="mode">
    <div class="canvas-top"><strong>{{ mode === 'wireframe' ? 'Page structure' : 'Visual canvas' }}</strong><span class="canvas-hint">{{ mode === 'wireframe' ? 'Arrange sections and modules' : 'Select any module to edit' }}</span></div>
    <div class="canvas-viewport"><div class="builder-canvas" :style="{ width }" :data-viewport="width">
      <div v-if="!sections.length" class="empty-canvas"><h2>Your page starts here.</h2><button class="btn primary" :disabled="!canEdit" @click="emit('add-first')">Add first section</button></div>
      <section v-for="(section, si) in sections" :key="section.id" class="canvas-section" :data-section-id="section.id" :data-layout="section.layout" :class="{ 'is-selected': activeSectionId === section.id }">
        <div class="section-toolbar" @dragover.prevent @drop="emit('drop-section', $event, si)">
          <span class="drag-handle" :draggable="canEdit" @dragstart="emit('drag-start', $event, 'section', si)" @dragend="emit('drag-end')" aria-hidden="true">⠿</span>
          <button class="section-select" @click="emit('section', section)">Section {{ si + 1 }} · {{ layoutDefinition(section.layout)?.label || section.layout }}</button><span class="section-spacer"></span>
          <button class="tiny-control" :aria-label="`Move section ${si + 1} up`" :disabled="!canEdit || si === 0" @click="emit('move-section', si, si - 1)">↑</button>
          <button class="tiny-control" :aria-label="`Move section ${si + 1} down`" :disabled="!canEdit || si === sections.length - 1" @click="emit('move-section', si, si + 1)">↓</button>
          <button class="tiny-control" aria-label="Duplicate section" :disabled="!canEdit" @click="emit('clone-section', si)">⧉</button>
          <button class="tiny-control danger-ink" aria-label="Delete section" :disabled="!canEdit" @click="emit('remove-section', si)">×</button>
        </div>
        <div class="canvas-section-body registered-grid" :style="layoutStyle(section)" :class="['layout-' + section.layout, 'section-bg-' + section.settings.background, 'canvas-spacing-' + section.settings.spacing, 'canvas-width-' + section.settings.width, 'section-align-' + section.settings.align]" @dragover.prevent @drop="emit('drop-section', $event, si)">
          <div v-for="(block, bi) in section.blocks" :key="block.id" class="canvas-block" :data-block-id="block.id" :class="{ 'selected-block': activeBlockId === block.id }" @click="emit('block', block)" @dragover.prevent @drop="emit('drop-block', $event, si, bi)">
            <div class="canvas-block-tools">
              <span class="block-drag" :draggable="canEdit" @dragstart.stop="emit('drag-start', $event, 'block', si, bi)" @dragend="emit('drag-end')" aria-hidden="true">⠿</span>
              <button class="block-select" :aria-label="`Edit ${blockDefinition(block.type)?.label || block.type} block`" @click.stop="emit('block', block)">{{ blockDefinition(block.type)?.label || block.type }}</button><span class="spacer"></span>
              <button aria-label="Move block up" :disabled="!canEdit || bi === 0" @click.stop="emit('move-block', si, bi, -1)">↑</button>
              <button aria-label="Move block down" :disabled="!canEdit || bi === section.blocks.length - 1" @click.stop="emit('move-block', si, bi, 1)">↓</button>
              <button aria-label="Duplicate block" :disabled="!canEdit" @click.stop="emit('clone-block', si, bi)">⧉</button>
              <button aria-label="Delete block" :disabled="!canEdit" @click.stop="emit('remove-block', si, bi)">×</button>
            </div>
            <p v-if="mode === 'wireframe'" class="wireframe-summary">{{ summary(block) }}</p>
            <div v-else-if="inlineId === block.id && editableText(block)" class="inline-text-editor" @click.stop><label>Edit {{ block.type === 'heading' ? 'heading' : 'paragraph' }} on canvas<textarea :value="block.props.text" :maxlength="blockDefinition(block.type)?.fields.find(field => field.key === 'text')?.max || 10000" :rows="block.type === 'heading' ? 2 : 5" @input="emit('text', block.id, $event.target.value)" /></label><button class="text-link" @click="inlineId = null">Done editing text</button></div>
            <div v-else class="canvas-block-preview" inert><Block :block="block" /></div>
            <div class="canvas-module-actions">
              <button v-if="mode === 'visual' && editableText(block) && inlineId !== block.id" class="canvas-inline-edit" @click.stop="editText(block)">Edit text on canvas</button>
              <button class="canvas-insert-module" :disabled="!canEdit" :aria-label="`Insert module after ${blockDefinition(block.type)?.label || block.type} ${bi + 1} in section ${si + 1}`" @click.stop="emit('insert-module', section.id, bi + 1)">+ Module</button>
            </div>
          </div>
          <button v-if="!section.blocks.length" class="empty-section" :disabled="!canEdit" @click="emit('insert-module', section.id, 0)">+ Add a module to this section</button>
        </div>
        <div class="section-end" @dragover.prevent @drop="emit('drop-section', $event, si)"><span>Drop compatible blocks here</span><button @click="emit('section', section)">Section properties</button><button :disabled="!canEdit" :aria-label="`Add module to section ${si + 1}`" @click="emit('insert-module', section.id, section.blocks.length)">+ Module</button></div>
        <button class="canvas-insert-section" :disabled="!canEdit || sections.length >= 40" :aria-label="`Insert section after section ${si + 1}`" @click="emit('insert-section', si + 1)">+ Section</button>
      </section>
      <button class="add-section-line" :disabled="!canEdit || sections.length >= 40" @click="emit('insert-section', sections.length)">+ Add section</button>
    </div></div>
  </div>
</template>
