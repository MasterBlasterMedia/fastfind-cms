<script setup>
import { computed } from 'vue';
import { blockRenderer } from '../builder/renderers';
const props = defineProps({ block: { type: Object, required: true } });
const renderer = computed(() => blockRenderer(props.block.type));
</script>
<template>
  <div class="content-block" :class="'block-' + block.type" :data-block-type="block.type">
    <component v-if="renderer" :is="renderer" :props="block.props || {}" />
    <p v-else class="unsupported-block" role="status">This content block is unavailable.</p>
  </div>
</template>
