<script setup>
import { computed, ref } from 'vue';
import AppDialog from '../AppDialog.vue';
const props = defineProps({ kind: { type: String, required: true }, options: { type: Array, required: true }, context: { type: String, default: '' } });
const emit = defineEmits(['close', 'insert']);
const query = ref('');
const filtered = computed(() => props.options.filter(item => `${item.label} ${item.description || ''} ${item.id}`.toLowerCase().includes(query.value.trim().toLowerCase())));
</script>
<template>
  <AppDialog :title="kind === 'section' ? 'Insert a section' : 'Insert a module'" @close="emit('close')">
    <div class="builder-insert-picker">
      <p>{{ context }}</p>
      <label>Search {{ kind === 'section' ? 'layouts' : 'modules' }}<input v-model="query" type="search" placeholder="Find the right building block…" /></label>
      <div class="insert-results">
        <button v-for="item in filtered" :key="item.id" class="insert-option" :disabled="item.disabled" :aria-label="`Insert ${item.label}`" @click="emit('insert', item.id)">
          <span aria-hidden="true">{{ item.icon || '▦' }}</span><strong>{{ item.label }}</strong><small>{{ item.disabled ? 'Not compatible with this section' : item.description || 'Add to your page' }}</small>
        </button>
      </div>
      <p v-if="!filtered.length" role="status">No matches. Try another name.</p>
    </div>
  </AppDialog>
</template>
