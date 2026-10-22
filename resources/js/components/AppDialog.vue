<script setup>
import { nextTick, onMounted, onUnmounted, ref } from 'vue';
defineProps({ title: { type: String, required: true } });
const emit = defineEmits(['close']);
const panel = ref(null);
let previous;
function keydown(event) {
  if (event.key === 'Escape') { event.preventDefault(); emit('close'); }
  if (event.key !== 'Tab') return;
  const items = [...panel.value.querySelectorAll('button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex="0"]')];
  if (!items.length) { event.preventDefault(); panel.value.focus(); return; }
  if (event.shiftKey && document.activeElement === items[0]) { event.preventDefault(); items.at(-1).focus(); }
  else if (!event.shiftKey && document.activeElement === items.at(-1)) { event.preventDefault(); items[0].focus(); }
}
onMounted(async () => { previous = document.activeElement; await nextTick(); panel.value.querySelector('button')?.focus(); });
onUnmounted(() => previous?.focus());
</script>
<template><Teleport to="body"><div class="modal-backdrop" @click.self="emit('close')"><div ref="panel" class="history-modal" role="dialog" aria-modal="true" :aria-label="title" tabindex="-1" @keydown="keydown"><div class="panel-heading"><h2>{{ title }}</h2><button class="icon-button" aria-label="Close dialog" @click="emit('close')">×</button></div><slot /></div></div></Teleport></template>
