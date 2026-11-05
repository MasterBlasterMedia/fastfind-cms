<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { get, post, failure } from '../lib';
import StudioFrame from '../components/StudioFrame.vue';
import AppDialog from '../components/AppDialog.vue';
const props = defineProps({ user: { type: Object, required: true } });
const emit = defineEmits(['logout']);
const route = useRoute();
const catalog = ref(null), loading = ref(true), error = ref(''), success = ref(''), query = ref('');
const type = ref(['theme', 'plugin'].includes(route.query.type) ? route.query.type : 'all');
const selected = ref(null), busy = ref(false);
const owner = computed(() => props.user.role === 'owner');
const items = computed(() => (catalog.value?.items || []).filter(item => (type.value === 'all' || item.type === type.value) && `${item.name} ${item.description} ${item.publisher} ${item.id}`.toLowerCase().includes(query.value.trim().toLowerCase())));
const storefront = computed(() => { try { const url = new URL(catalog.value?.site_url); return url.protocol === 'https:' && !url.username && !url.password ? url.href : null; } catch { return null; } });
async function load() {
  loading.value = true; error.value = '';
  try { catalog.value = await get('/studio/marketplace'); }
  catch (e) { error.value = failure(e); }
  finally { loading.value = false; }
}
function review(item) { if (owner.value && item.can_install && !busy.value) { selected.value = item; error.value = ''; success.value = ''; } }
async function install() {
  if (busy.value || !owner.value || !selected.value?.can_install) return;
  const item = selected.value;
  busy.value = true; error.value = '';
  try {
    await post('/studio/marketplace/install', { type: item.type, id: item.id, version: item.version, sha256: item.sha256 });
    selected.value = null;
    success.value = `${item.name} installed. ${item.type === 'theme' ? 'Open Themes to customize and apply it.' : 'Open Plugins to activate it when ready.'}`;
    await load();
  } catch (e) { error.value = failure(e); }
  finally { busy.value = false; }
}
onMounted(load);
</script>
<template>
  <StudioFrame :user="user" page-title="Marketplace" subtitle="Find a new look or add something useful to your site." @logout="emit('logout')">
    <template #toolbar><a v-if="storefront" class="btn outline small" :href="storefront" target="_blank" rel="noopener noreferrer">Visit marketplace ↗</a></template>
    <p v-if="error && !selected" class="alert error" role="alert">{{ error }} <button class="btn outline small" :disabled="loading || busy" @click="load">Try again</button></p>
    <p v-if="success" class="alert" role="status">{{ success }}</p>
    <div class="marketplace-library-links"><RouterLink to="/studio/themes">Your themes →</RouterLink><RouterLink to="/studio/plugins">Your plugins →</RouterLink></div>
    <p v-if="loading" role="status">Loading marketplace…</p>
    <section v-else-if="catalog && !catalog.configured" class="admin-panel marketplace-empty">
      <span class="eyebrow">YOUR EXTENSION LIBRARY</span><h2>Connect your marketplace</h2>
      <p>FastFind can browse themes and plugins hosted on your separate marketplace website. No marketplace address has been configured for this installation yet.</p>
      <p>Your site operator can connect a catalog and approve its download hosts. You can already upload signed packages from the Themes and Plugins screens.</p>
      <div class="plugin-actions"><RouterLink class="btn primary" to="/studio/themes">Explore themes</RouterLink><RouterLink class="btn outline" to="/studio/plugins">Explore plugins</RouterLink></div>
    </section>
    <section v-else-if="catalog" class="admin-panel">
      <div class="panel-heading"><div><h2>{{ catalog.name || 'FastFind marketplace' }}</h2><p>Packages are downloaded from your connected marketplace. You choose when to activate them.</p></div><button class="btn outline small" :disabled="busy" @click="load">Refresh catalog</button></div>
      <div class="marketplace-filters"><label>Search marketplace<input v-model="query" type="search" placeholder="Name, publisher or feature" /></label><label>Package type<select v-model="type"><option value="all">Themes and plugins</option><option value="theme">Themes</option><option value="plugin">Plugins</option></select></label></div>
      <p class="muted" role="status">{{ items.length }} {{ items.length === 1 ? 'package' : 'packages' }}</p>
      <div class="marketplace-grid">
        <article v-for="item in items" :key="`${item.type}:${item.id}`" class="marketplace-card">
          <div class="panel-heading"><span class="state-pill">{{ item.type === 'theme' ? 'Theme' : 'Plugin' }}</span><span class="muted">v{{ item.version }}</span></div>
          <h3>{{ item.name }}</h3><p>{{ item.description }}</p><p class="plugin-id">By {{ item.publisher }} · {{ item.id }}</p>
          <p v-if="item.installed_version" class="muted">Installed: {{ item.installed_version }}</p>
          <ul v-if="item.issues?.length" class="marketplace-issues"><li v-for="issue in item.issues" :key="issue">{{ issue }}</li></ul>
          <button class="btn primary" :disabled="!owner || !item.can_install || busy" @click="review(item)">{{ item.installed_version === item.version ? 'Installed' : item.installed_version ? 'Review update' : 'Review installation' }}</button>
        </article>
      </div>
      <p v-if="!items.length">No packages match your search.</p>
    </section>
    <AppDialog v-if="selected" :title="`Install ${selected.name}`" @close="!busy && (selected = null)">
      <p><strong>{{ selected.name }} {{ selected.version }}</strong> from {{ selected.publisher }}</p>
      <p>{{ selected.description }}</p>
      <p v-if="selected.type === 'plugin'">Plugins run server code with access to your application. Install packages only from publishers you trust.</p>
      <p v-else>Themes provide a palette, typography and a supported page layout. Your saved pages stay intact.</p>
      <p>FastFind will verify the package signature, integrity and compatibility before installation. {{ selected.type === 'theme' ? 'Apply the theme from your theme library afterward.' : 'Activate the plugin from your plugin library afterward.' }}</p>
      <p v-if="error" class="alert error" role="alert">{{ error }}</p>
      <div class="plugin-actions"><button class="btn primary" :disabled="busy" @click="install">{{ busy ? 'Verifying and installing…' : 'Download and install' }}</button><button class="btn outline" :disabled="busy" @click="selected = null">Cancel</button></div>
    </AppDialog>
  </StudioFrame>
</template>
