<script setup>
import Block from './Block.vue';
defineProps({ document: { type: Object, required: true }, editor: { type: Boolean, default: false } });
</script>
<template>
  <div class="rendered-document">
    <section v-for="section in (document.sections || [])" :key="section.id" class="rendered-section" :class="['section-bg-' + (section.settings?.background || 'plain'), 'section-width-' + (section.settings?.width || 'normal'), 'layout-' + section.layout]">
      <div class="rendered-blocks"><Block v-for="block in section.blocks" :key="block.id" :block="block" /></div>
    </section>
  </div>
</template>
