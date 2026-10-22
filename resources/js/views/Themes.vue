<script setup>
import { inject, onMounted, ref } from 'vue';
import { get, put, failure } from '../lib';
import StudioFrame from '../components/StudioFrame.vue';
const props = defineProps({ user: { type: Object, required: true } });
const emit = defineEmits(['logout']);
const { toast, reloadSite } = inject('session');
const themes = ref([]), active = ref(''), settings = ref({}), error = ref(''), busy = ref(false), selected = ref('');
async function load() { try { const data = await get('/studio/themes'); themes.value = data.themes; active.value = data.active; selected.value = data.active; settings.value = { ...data.settings }; } catch (e) { error.value = failure(e); } }
onMounted(load);
function choose(theme) { selected.value = theme.id; settings.value = { ...theme.defaults }; }
async function save() { busy.value = true; error.value = ''; try { await put('/studio/themes', { id: selected.value, settings: settings.value }); await load(); await reloadSite(); toast('Public theme updated.'); } catch (e) { error.value = failure(e); } finally { busy.value = false; } }
</script>
<template><StudioFrame :user="user" page-title="Themes" subtitle="Style the public website independently of the editor." @logout="emit('logout')"><p v-if="error" class="alert error" role="alert">{{ error }}</p><section class="admin-panel"><h2>Public website theme</h2><div class="theme-grid"><button v-for="theme in themes" :key="theme.id" class="theme-card" :class="{ selected: selected === theme.id }" :aria-pressed="selected === theme.id" @click="choose(theme)"><span class="theme-swatch" :class="`swatch-${theme.id}`"><b>FastFind.</b><span>A place for your ideas.</span></span><strong>{{ theme.name }}</strong><small>{{ theme.description }}</small><span v-if="active === theme.id" class="state-pill">Active</span></button></div><form v-if="selected" class="settings-form" @submit.prevent="save"><label>Accent color<input v-model="settings.accent" type="color" /></label><label>Typography<select v-model="settings.font"><option value="sans">Sans serif</option><option value="serif">Serif</option></select></label><label>Content width<select v-model="settings.width"><option value="normal">Comfortable</option><option value="wide">Wide</option></select></label><button class="btn primary" :disabled="busy || !['owner', 'admin'].includes(user.role)">{{ busy ? 'Applying…' : 'Apply theme' }}</button></form><p>Theme changes preserve saved content and published snapshots.</p></section></StudioFrame></template>
