<script setup>
import { computed, inject } from 'vue';
import { useRoute } from 'vue-router';
import StudioFrame from '../components/StudioFrame.vue';
import { extensionView } from '../extensions';
defineProps({ user: { type: Object, required: true } });
const emit = defineEmits(['logout']);
const route = useRoute();
const { extensions } = inject('session');
const pluginId = computed(() => `${route.params.vendor}/${route.params.name}`);
const descriptor = computed(() => extensions.value.admin_views?.find(view => view.plugin_id === pluginId.value && view.id === route.params.view));
const component = computed(() => descriptor.value && extensionView(pluginId.value, route.params.view));
</script>
<template><StudioFrame :user="user" :page-title="descriptor?.label || 'Extension unavailable'" @logout="emit('logout')"><component v-if="component" :is="component" :plugin-id="pluginId" /><div v-else class="admin-panel"><h2>Extension unavailable</h2><p>The extension is inactive, unauthorized, or its administration module has not been built.</p><RouterLink to="/studio" class="btn outline">Return to dashboard</RouterLink></div></StudioFrame></template>
