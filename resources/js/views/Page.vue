<script setup>
import { onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { get } from '../lib';
import ContentPage from '../components/ContentPage.vue';
import { applyPageHead } from '../head';
const route = useRoute(); const page = ref(null); const error = ref(''); const loading = ref(true);
async function load() { loading.value=true; error.value=''; try { page.value=(await get('/pages/'+encodeURIComponent(route.params.slug))).page; applyPageHead(page.value.head, {title:page.value.title+' | FastFind',description:page.value.summary}); } catch { page.value=null; error.value='This page may have moved or is no longer available.'; applyPageHead({}, {title:'Page unavailable | FastFind'}); } finally { loading.value=false; } }
onMounted(load); watch(() => route.params.slug, load);
</script>
<template>
  <main class="reading-shell"><div class="container"><div v-if="loading" class="empty-state">Opening page…</div><div v-else-if="error" class="empty-state"><span class="eyebrow">PAGE NOT FOUND</span><h1>Nothing here yet.</h1><p>{{ error }}</p><RouterLink to="/" class="btn primary">Back to discovery ↗</RouterLink></div><template v-else><div class="breadcrumbs"><RouterLink to="/">Home</RouterLink><span>/</span><span>{{ page.title }}</span></div><header class="reading-header"><span class="eyebrow">FASTFIND LIBRARY</span><h1>{{ page.title }}</h1><p v-if="page.summary">{{ page.summary }}</p><div class="reading-meta"><span>✳ FASTFIND EDITORIAL</span><span>{{ new Date(page.published_at).toLocaleDateString(undefined,{year:'numeric',month:'long',day:'numeric'}) }}</span></div></header><ContentPage :document="page.document"/><div class="reading-end"><span>✳</span><h2>Keep exploring.</h2><RouterLink class="btn outline" to="/">Back to all stories <b>↗</b></RouterLink></div></template></div></main>
</template>
