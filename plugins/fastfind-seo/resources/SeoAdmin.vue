<script setup>
import { inject, onMounted, ref } from 'vue';
import { get, put, failure } from '../../../resources/js/lib.js';
import SchemaFields from '../../../resources/js/components/SchemaFields.vue';

const session = inject('session');
const settings = ref({});
const fields = ref([]);
const redirects = ref([]);
const loading = ref(true);
const busy = ref(false);
const error = ref('');
async function load() {
  loading.value = true;
  try {
    const [config, routes] = await Promise.all([get('/studio/plugins/fastfind/seo/settings'), get('/plugins/fastfind/seo/redirects')]);
    settings.value = { ...Object.fromEntries(config.fields.filter(field => field.default !== undefined).map(field => [field.key, field.default])), ...config.settings };
    fields.value = config.fields;
    redirects.value = routes.redirects;
  } catch (e) { error.value = failure(e); }
  finally { loading.value = false; }
}
async function saveSettings() {
  busy.value = true; error.value = '';
  try { settings.value = (await put('/studio/plugins/fastfind/seo/settings', { settings: settings.value })).settings; session?.toast('SEO settings saved.'); }
  catch (e) { error.value = failure(e); }
  finally { busy.value = false; }
}
async function saveRedirects() {
  busy.value = true; error.value = '';
  try { redirects.value = (await put('/plugins/fastfind/seo/redirects', { redirects: redirects.value })).redirects; session?.toast('Redirects saved.'); }
  catch (e) { error.value = failure(e); }
  finally { busy.value = false; }
}
onMounted(load);
</script>

<template>
  <div class="stack-field">
    <p v-if="loading" role="status">Loading SEO settings…</p>
    <p v-if="error" class="alert error" role="alert">{{ error }}</p>
    <template v-if="!loading">
      <section class="panel">
        <h2>Search and social settings</h2>
        <p>Set individual page metadata in the page editor. Changes appear publicly when the page is published.</p>
        <form @submit.prevent="saveSettings">
          <SchemaFields :model="settings" :fields="fields" :disabled="busy" />
          <button class="btn primary" :disabled="busy">Save SEO settings</button>
        </form>
        <p><a href="/sitemap.xml" target="_blank" rel="noopener">View sitemap index</a></p>
      </section>
      <section class="panel">
        <h2>Redirects</h2>
        <p>Sources are public site paths. Cycles and reserved administration routes are rejected.</p>
        <form @submit.prevent="saveRedirects" class="stack-field">
          <fieldset v-for="(redirect, index) in redirects" :key="index" :disabled="busy" class="stack-field">
            <legend>Redirect {{ index + 1 }}</legend>
            <label>Source path<input v-model="redirect.source" required placeholder="/p/old-page" /></label>
            <label>Destination<input v-model="redirect.destination" required placeholder="/p/new-page" /></label>
            <label>Redirect status<select v-model.number="redirect.status"><option :value="301">301 — Permanent</option><option :value="302">302 — Temporary</option><option :value="307">307 — Temporary, preserve method</option><option :value="308">308 — Permanent, preserve method</option></select></label>
            <button class="btn ghost" type="button" @click="redirects.splice(index, 1)">Remove redirect {{ index + 1 }}</button>
          </fieldset>
          <p v-if="!redirects.length">No redirects configured.</p>
          <div class="action-row"><button type="button" class="btn ghost" :disabled="busy" @click="redirects.push({ source: '', destination: '', status: 301 })">Add redirect</button> <button class="btn primary" :disabled="busy">Save redirects</button></div>
        </form>
      </section>
    </template>
  </div>
</template>
