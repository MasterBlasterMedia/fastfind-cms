<script setup>
import { onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get } from '../lib';
const route=useRoute(); const router=useRouter(); const term=ref(route.query.q||''); const results=ref([]); const loading=ref(false); const error=ref('');
async function load(){ const q=String(route.query.q||'').trim();term.value=q; if(q.length<2){ results.value=[];return; } loading.value=true;error.value='';try{results.value=(await get('/search?q='+encodeURIComponent(q))).results;}catch{error.value='Search is unavailable right now.';}finally{loading.value=false;} }
function search(){router.push({path:'/search',query:{q:term.value.trim()}});}
onMounted(load);watch(()=>route.query.q,load);
</script>
<template><main class="container search-page"><span class="eyebrow">FIND YOUR NEXT IDEA</span><h1>Explore <em>everything.</em></h1><form class="search-large" @submit.prevent="search"><span>⌕</span><input v-model="term" autofocus placeholder="Search the entire library…" aria-label="Search library"/><button class="btn primary">Search ↗</button></form><div class="search-results"><div class="result-header"><h2>{{ loading?'Searching…': results.length+' results' }}</h2><span v-if="route.query.q">for “{{ route.query.q }}”</span></div><p v-if="error" class="alert error">{{ error }}</p><RouterLink v-for="item in results" :key="item.slug" :to="'/p/'+item.slug" class="result-row"><div><span class="eyebrow">PAGE / FASTFIND</span><h3>{{ item.title }}</h3><p>{{ item.summary }}</p></div><span class="result-arrow">↗</span></RouterLink><div v-if="!loading&&!results.length&&!error" class="empty-state"><span class="empty-symbol">✧</span><h3>Nothing matching just yet.</h3><p>Try another phrase or explore our latest stories.</p><RouterLink to="/" class="text-link">Explore the library →</RouterLink></div></div></main></template>
