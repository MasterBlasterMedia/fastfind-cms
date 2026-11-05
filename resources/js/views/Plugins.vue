<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import { get, post, put, failure } from '../lib';
import StudioFrame from '../components/StudioFrame.vue';
import SchemaFields from '../components/SchemaFields.vue';
import AppDialog from '../components/AppDialog.vue';
import SignedPackageUpload from '../components/SignedPackageUpload.vue';
const props = defineProps({ user: { type: Object, required: true } });
const emit = defineEmits(['logout']);
const { toast, reloadExtensions, reloadSite } = inject('session');
const plugins = ref([]), loading = ref(true), error = ref(''), busy = ref(''), query = ref('');
const settingsPlugin = ref(null), settings = ref({}), fields = ref([]), catalog = ref(null);
const owner = computed(() => props.user.role === 'owner');
const filtered = computed(() => plugins.value.filter(plugin => `${plugin.name} ${plugin.id} ${plugin.description}`.toLowerCase().includes(query.value.toLowerCase())));
const endpoint = plugin => `/studio/plugins/${plugin.id}`;
async function load() { loading.value = true; error.value = ''; try { plugins.value = (await get('/studio/plugins')).plugins; } catch (e) { error.value = failure(e); } finally { loading.value = false; } }
onMounted(load);
async function action(plugin, name) {
  if (busy.value || !owner.value) return;
  if (name === 'uninstall' && !confirm(`Uninstall ${plugin.name}? Review its retained data policy before continuing.`)) return;
  busy.value = plugin.id; error.value = '';
  try { await post(`${endpoint(plugin)}/${name}`); await load(); await reloadExtensions(); await reloadSite(); toast(`${plugin.name}: ${name} completed.`); } catch (e) { error.value = failure(e); } finally { busy.value = ''; }
}
async function openSettings(plugin) { try { const result = await get(`${endpoint(plugin)}/settings`); fields.value = result.fields || []; settings.value = { ...Object.fromEntries(fields.value.filter(field => field.default !== undefined).map(field => [field.key, field.default])), ...result.settings }; settingsPlugin.value = plugin; } catch (e) { error.value = failure(e); } }
async function saveSettings() { busy.value = settingsPlugin.value.id; try { await put(`${endpoint(settingsPlugin.value)}/settings`, { settings: settings.value }); toast('Plugin settings saved.'); settingsPlugin.value = null; } catch (e) { error.value = failure(e); } finally { busy.value = ''; } }
async function loadCatalog() { try { catalog.value = await get('/studio/plugin-catalog'); } catch (e) { error.value = failure(e); } }
</script>
<template><StudioFrame :user="user" page-title="Plugins" subtitle="Extend FastFind with reviewed, compatible packages." @logout="emit('logout')">
  <template #toolbar><RouterLink v-if="owner" class="btn primary small" to="/studio/marketplace?type=plugin">Browse plugins</RouterLink></template>
  <p v-if="error" class="alert error" role="alert">{{ error }}</p><div v-if="loading" class="admin-loading" role="status">Loading plugins…</div>
  <template v-else><div class="admin-panel"><div class="panel-heading"><h2>Plugin library</h2><button class="btn outline" @click="loadCatalog">Check catalog</button></div><label>Search plugins<input v-model="query" type="search" placeholder="Name, publisher or capability" /></label><div class="plugin-grid"><article v-for="plugin in filtered" :key="plugin.id" class="plugin-card"><div class="panel-heading"><h3>{{ plugin.name }}</h3><span class="state-pill">{{ plugin.status }}</span></div><p>{{ plugin.description }}</p><p class="plugin-id">{{ plugin.id }} · Available {{ plugin.version }}<span v-if="plugin.installed_version"> · Installed {{ plugin.installed_version }}</span></p><details><summary>Compatibility and requested permissions</summary><p>{{ plugin.compatible ? 'Compatible with this installation.' : 'Incompatible with this installation.' }}</p><ul><li v-for="issue in plugin.issues" :key="issue">{{ issue }}</li></ul><ul><li v-for="permission in plugin.permissions" :key="permission">{{ permission }}</li></ul><p>Server-side plugins execute trusted PHP code with application access. Declared permissions are not a sandbox.</p></details><div class="plugin-actions"><button v-if="plugin.status === 'available'" class="btn primary small" :disabled="!owner || !!busy || !plugin.compatible" @click="action(plugin, 'install')">Install</button><button v-if="plugin.status === 'inactive'" class="btn primary small" :disabled="!owner || !!busy || !plugin.compatible" @click="action(plugin, 'activate')">Activate</button><button v-if="plugin.status === 'active'" class="btn outline small" :disabled="!owner || !!busy" @click="action(plugin, 'deactivate')">Deactivate</button><button v-if="plugin.installed_version && plugin.version !== plugin.installed_version" class="btn outline small" :disabled="!owner || !!busy || !plugin.compatible" @click="action(plugin, 'upgrade')">Upgrade</button><button v-if="plugin.status === 'active'" class="btn outline small" @click="openSettings(plugin)">Settings</button><button v-if="['inactive', 'error'].includes(plugin.status)" class="btn outline small danger-ink" :disabled="!owner || !!busy" @click="action(plugin, 'uninstall')">Uninstall</button><span v-if="busy === plugin.id" role="status">Working…</span></div></article></div><p v-if="!filtered.length">No matching plugins.</p></div>
  <section v-if="catalog" class="admin-panel"><h2>Configured catalog</h2><p>Source: {{ catalog.source }}. These are catalog records; installation requires a reviewed local package.</p><ul><li v-for="plugin in catalog.plugins" :key="plugin.id"><strong>{{ plugin.name }}</strong> · {{ plugin.version }} — {{ plugin.description }}</li></ul></section>
  <SignedPackageUpload v-if="owner" kind="plugin" @installed="load" /></template>
  <AppDialog v-if="settingsPlugin" :title="`${settingsPlugin.name} settings`" @close="settingsPlugin = null"><form @submit.prevent="saveSettings"><SchemaFields :fields="fields" :model="settings" :disabled="!!busy" /><p v-if="!fields.length">This plugin has no configurable fields.</p><button class="btn primary" :disabled="!!busy">Save settings</button></form></AppDialog>
</StudioFrame></template>
