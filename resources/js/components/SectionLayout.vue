<script setup>
import { computed, ref, watch } from 'vue';
import Block from './Block.vue';
import { layoutDefinition, layoutStyle } from '../builder/registry';
const props = defineProps({ section: { type: Object, required: true } });
const active = ref(0);
const blocks = computed(() => props.section.blocks || []);
const definition = computed(() => layoutDefinition(props.section.layout));
watch(() => blocks.value.length, length => { active.value = Math.max(0, Math.min(active.value, length - 1)); });
const prefix = computed(() => `section-${props.section.id}`);
function changeTab(event, index) {
  let next = index;
  if (event.key === 'ArrowRight') next = (index + 1) % blocks.value.length;
  else if (event.key === 'ArrowLeft') next = (index + blocks.value.length - 1) % blocks.value.length;
  else if (event.key === 'Home') next = 0;
  else if (event.key === 'End') next = blocks.value.length - 1;
  else return;
  event.preventDefault();
  active.value = next;
  event.currentTarget.parentElement.children[next]?.focus();
}
</script>
<template>
  <section class="rendered-section" :data-layout="section.layout" :style="layoutStyle(section)" :class="['section-bg-' + (section.settings?.background || 'plain'), 'section-width-' + (section.settings?.width || 'normal'), 'section-spacing-' + (section.settings?.spacing || 'normal'), 'section-align-' + (section.settings?.align || 'left'), 'layout-' + section.layout]">
    <p v-if="!definition" role="status">This section layout is unavailable.</p>
    <template v-else-if="section.layout === 'tabs' && blocks.length">
      <div role="tablist" aria-label="Content tabs" class="content-tabs">
        <button v-for="(block, index) in blocks" :key="block.id" :id="`${prefix}-tab-${index}`" role="tab" :aria-selected="active === index" :aria-controls="`${prefix}-panel-${index}`" :tabindex="active === index ? 0 : -1" @click="active = index" @keydown="changeTab($event, index)">{{ block.props?.title || `Tab ${index + 1}` }}</button>
      </div>
      <div v-for="(block, index) in blocks" :key="block.id" :id="`${prefix}-panel-${index}`" role="tabpanel" :aria-labelledby="`${prefix}-tab-${index}`" :hidden="active !== index" tabindex="0"><Block :block="block" /></div>
    </template>
    <div v-else-if="section.layout === 'carousel' && blocks.length" class="content-carousel" role="region" aria-roledescription="carousel" aria-label="Content carousel">
      <div class="carousel-controls"><button aria-label="Previous slide" @click="active = (active + blocks.length - 1) % blocks.length">←</button><span role="status">Slide {{ active + 1 }} of {{ blocks.length }}</span><button aria-label="Next slide" @click="active = (active + 1) % blocks.length">→</button></div>
      <div v-for="(block, index) in blocks" :key="block.id" :hidden="active !== index" role="group" aria-roledescription="slide" :aria-label="`${index + 1} of ${blocks.length}`"><Block :block="block" /></div>
    </div>
    <div v-else class="rendered-blocks registered-grid"><Block v-for="block in blocks" :key="block.id" :block="block" /></div>
  </section>
</template>
