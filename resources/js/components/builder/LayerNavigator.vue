<script setup>
import { ref } from 'vue';
import { blockDefinition, layoutDefinition } from '../../builder/registry';
defineProps({ sections: { type: Array, required: true }, selected: { type: Object, required: true } });
const emit = defineEmits(['section', 'block']);
const collapsed = ref(new Set());
function toggle(id) { const next = new Set(collapsed.value); next.has(id) ? next.delete(id) : next.add(id); collapsed.value = next; }
function title(block) { return String(block.props?.title || block.props?.text || block.props?.alt || blockDefinition(block.type)?.label || block.type).slice(0, 65); }
</script>
<template>
  <nav class="builder-layers" aria-label="Page layers">
    <p class="layer-help">Select a section or module to edit it. Changes appear in both views.</p>
    <ol>
      <li v-for="(section, index) in sections" :key="section.id">
        <div class="layer-section">
          <button class="layer-toggle" :aria-label="`${collapsed.has(section.id) ? 'Expand' : 'Collapse'} section ${index + 1} layers`" :aria-expanded="!collapsed.has(section.id)" @click="toggle(section.id)">{{ collapsed.has(section.id) ? '›' : '⌄' }}</button>
          <button class="layer-select" :aria-current="selected.kind === 'section' && selected.id === section.id ? 'true' : undefined" @click="emit('section', section)"><strong>Section {{ index + 1 }}</strong><small>{{ layoutDefinition(section.layout)?.label || section.layout }}</small></button>
        </div>
        <ol v-if="!collapsed.has(section.id)">
          <li v-for="(block, bi) in section.blocks" :key="block.id"><button class="layer-select layer-module" :aria-label="`Select ${blockDefinition(block.type)?.label || block.type} module ${bi + 1} in section ${index + 1}`" :aria-current="selected.kind === 'block' && selected.id === block.id ? 'true' : undefined" @click="emit('block', block)"><span aria-hidden="true">{{ blockDefinition(block.type)?.icon || '◇' }}</span><span><strong>{{ blockDefinition(block.type)?.label || block.type }}</strong><small>{{ title(block) }}</small></span></button></li>
          <li v-if="!section.blocks.length" class="layer-empty">Empty section</li>
        </ol>
      </li>
    </ol>
    <p v-if="!sections.length">Add a section to start building.</p>
  </nav>
</template>
