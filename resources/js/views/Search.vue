<script setup>
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get } from '../lib';
const route = useRoute(), router = useRouter();
const term = ref(String(route.query.q || '')), results = ref([]), loading = ref(false), error = ref(''), pagination = ref({ page: 1, last_page: 1, total: 0 });
async function load() {
  const query = String(route.query.q || '').trim();
  error.value = ''; results.value = [];
  if (query.length < 2) return;
  loading.value = true;
  try { const response = await get(`/search?q=${encodeURIComponent(query)}&page=${Number(route.query.page) || 1}`); results.value = response.results; pagination.value = response.pagination || { page: 1, last_page: 1, total: response.results.length }; }
  catch { error.value = 'Search is unavailable right now. Please try again.'; }
  finally { loading.value = false; }
}
function search() { router.push({ path: '/search', query: { q: term.value.trim() } }); }
function turnPage(page) { router.push({ path: '/search', query: { q: term.value.trim(), page } }); }
onMounted(load);
</script>
<template><main class="container search-page"><span class="eyebrow">FIND YOUR NEXT IDEA</span><h1>Explore <em>everything.</em></h1><form class="search-large" @submit.prevent="search"><span aria-hidden="true">⌕</span><input v-model="term" minlength="2" maxlength="100" required placeholder="Search the entire library…" aria-label="Search library" /><button class="btn primary">Search ↗</button></form><div class="search-results"><div class="result-header"><h2 role="status">{{ loading ? 'Searching…' : pagination.total + ' results' }}</h2><span v-if="route.query.q">for “{{ route.query.q }}”</span></div><p v-if="error" class="alert error" role="alert">{{ error }}</p><RouterLink v-for="item in results" :key="item.slug" :to="'/p/' + item.slug" class="result-row"><div><span class="eyebrow">PAGE / FASTFIND</span><h3>{{ item.title }}</h3><p>{{ item.summary }}</p></div><span class="result-arrow" aria-hidden="true">↗</span></RouterLink><div v-if="!loading && !results.length && !error" class="empty-state"><h3>No matching pages.</h3><p>Try another phrase or explore our latest stories.</p><RouterLink to="/" class="text-link">Explore the library →</RouterLink></div><nav v-if="pagination.last_page > 1" aria-label="Search pagination" class="plugin-actions"><button class="btn outline" :disabled="pagination.page <= 1" @click="turnPage(pagination.page - 1)">Previous page</button><span>Page {{ pagination.page }} of {{ pagination.last_page }}</span><button class="btn outline" :disabled="pagination.page >= pagination.last_page" @click="turnPage(pagination.page + 1)">Next page</button></nav></div></main></template>
