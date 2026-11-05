<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import { get, put, failure } from '../lib';
import { themeStyle } from '../themes';
import StudioFrame from '../components/StudioFrame.vue';
import SignedPackageUpload from '../components/SignedPackageUpload.vue';
const props = defineProps({ user: { type: Object, required: true } });
const emit = defineEmits(['logout']);
const { toast, reloadSite } = inject('session');
const themes = ref([]), active = ref(''), settings = ref({}), error = ref(''), busy = ref(false), selected = ref(''), loading = ref(true);
const activeSettings = ref({});
const chosen = computed(() => themes.value.find(theme => theme.id === selected.value));
const canManage = computed(() => ['owner', 'admin'].includes(props.user.role));
const colors = [{ key: 'accent', label: 'Accent color' }, { key: 'background', label: 'Page background' }, { key: 'surface', label: 'Card background' }, { key: 'text', label: 'Text color' }, { key: 'muted', label: 'Secondary text color' }];
async function load() {
  error.value = '';
  try { const data = await get('/studio/themes'); themes.value = data.themes; active.value = selected.value = data.active; activeSettings.value = { ...data.settings }; settings.value = { ...data.settings }; }
  catch (e) { error.value = failure(e); }
  finally { loading.value = false; }
}
onMounted(load);
function choose(theme) { if (!busy.value) { selected.value = theme.id; settings.value = { ...(theme.id === active.value ? activeSettings.value : theme.defaults) }; } }
async function save() {
  if (busy.value || !canManage.value) return;
  busy.value = true; error.value = '';
  try { await put('/studio/themes', { id: selected.value, settings: { ...settings.value } }); await load(); await reloadSite(); toast('Public theme updated.'); }
  catch (e) { error.value = failure(e); }
  finally { busy.value = false; }
}
async function installed(result) { await load(); const theme = themes.value.find(item => item.id === result.theme?.id); if (theme) choose(theme); }
</script>
<template>
  <StudioFrame :user="user" page-title="Themes" subtitle="Give your public website its own identity." @logout="emit('logout')">
    <template #toolbar><RouterLink v-if="user.role === 'owner'" class="btn primary small" to="/studio/marketplace?type=theme">Browse themes</RouterLink></template>
    <p v-if="error" class="alert error" role="alert">{{ error }}</p>
    <p v-if="loading" role="status">Loading themes…</p>
    <section v-else class="admin-panel">
      <div class="panel-heading"><div><h2>Your theme library</h2><p>Choose a theme, refine its design, then apply it to the public site.</p></div><a class="text-link" href="/" target="_blank" rel="noopener">View website ↗</a></div>
      <div class="theme-grid">
        <button v-for="theme in themes" :key="theme.id" class="theme-card" :class="{ selected: selected === theme.id }" :aria-pressed="selected === theme.id" :disabled="busy" @click="choose(theme)">
          <span class="theme-swatch" :style="themeStyle(theme.defaults)"><b>{{ theme.name }}</b><span>A place for your ideas.</span><span class="theme-sample-button">Explore →</span></span>
          <strong>{{ theme.name }}</strong><small>{{ theme.description }}</small><small>{{ theme.version }} · {{ theme.source === 'package' ? 'Custom theme' : 'Included' }}</small><span v-if="active === theme.id" class="state-pill">Active</span>
        </button>
      </div>
      <div v-if="chosen" class="theme-customizer">
        <div class="theme-design-preview" :style="themeStyle(settings)" :data-layout="chosen.layout" aria-label="Theme design sample">
          <span class="eyebrow">DESIGN PREVIEW</span><h3>Your next great story.</h3><p>See your palette, typography and corners together before applying them.</p><div class="theme-preview-card"><strong>Made for curious minds</strong><p>A clear home for everything worth sharing.</p><span class="theme-sample-button">Read story →</span></div>
        </div>
        <form class="settings-form" :aria-busy="busy" @submit.prevent="save">
          <h3>Customize {{ chosen.name }}</h3>
          <div class="theme-color-fields"><label v-for="field in colors" :key="field.key">{{ field.label }}<input v-model="settings[field.key]" type="color" :disabled="busy || !canManage" /></label></div>
          <label>Typography<select v-model="settings.font" :disabled="busy || !canManage"><option value="sans">Sans serif</option><option value="serif">Serif</option></select></label>
          <label>Content width<select v-model="settings.width" :disabled="busy || !canManage"><option value="normal">Comfortable</option><option value="wide">Wide</option></select></label>
          <label>Corner style<select v-model="settings.radius" :disabled="busy || !canManage"><option value="square">Square</option><option value="soft">Soft</option><option value="rounded">Rounded</option></select></label>
          <label>Page spacing<select v-model="settings.spacing" :disabled="busy || !canManage"><option value="compact">Compact</option><option value="normal">Balanced</option><option value="relaxed">Generous</option></select></label>
          <button class="btn primary" :disabled="busy || !canManage">{{ busy ? 'Applying…' : 'Apply theme' }}</button>
          <p class="muted">The sample shows your design choices. Check the live site after applying, including text contrast. Saved pages and published snapshots stay intact.</p>
        </form>
      </div>
    </section>
    <SignedPackageUpload v-if="user.role === 'owner'" kind="theme" @installed="installed" />
  </StudioFrame>
</template>
