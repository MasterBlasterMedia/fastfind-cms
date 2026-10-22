<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { get } from '../lib';
const router = useRouter();
const query = ref('');
const featured = ref([]);
const loading = ref(true);
const error = ref('');
function submit() { if (query.value.trim().length >= 2) router.push({ path: '/search', query: { q: query.value.trim() } }); }
onMounted(async () => { try { featured.value = (await get('/home')).featured; } catch { error.value = 'Published content could not be loaded. Please try again later.'; } finally { loading.value = false; } });
</script>
<template>
  <main>
    <div class="hero-bg"><div class="container home-hero"><div class="hero-text"><span class="eyebrow"><span class="mini-dot"></span> THE DISCOVERY STARTS HERE</span><h1>Find what<br><em>moves you.</em></h1><p>Ideas, perspectives, and answers worth exploring. Thoughtfully organized. Effortlessly found.</p><form class="hero-search" @submit.prevent="submit"><span class="search-symbol">⌕</span><input v-model="query" aria-label="Search content" placeholder="What are you looking for?"/><button aria-label="Submit search">Explore <span>↗</span></button></form><div class="hero-under">✧ Find clarity. &nbsp; ✧ Stay curious. &nbsp; ✧ Discover more.</div></div><div class="hero-visual"><div class="orbit ring-one"></div><div class="orbit ring-two"></div><div class="orbit ring-three"></div><div class="big-symbol">✳</div><div class="float-card float-top"><span>01 / DISCOVER</span><b>Curiosity<br>is a superpower.</b></div><div class="float-card float-bottom"><div class="flair">↗</div><span>BETTER QUESTIONS.<br>BETTER ANSWERS.</span></div></div></div></div>
    <section class="container discovery-section"><div class="section-heading"><div><span class="eyebrow">THE LIBRARY</span><h2>Made for<br><em>curious minds.</em></h2></div><p>Browse the latest stories and resources. Every page is a doorway to something new.</p></div><div v-if="loading" class="loading-line">Loading discoveries…</div><p v-else-if="error" class="alert error" role="alert">{{error}}</p><div v-else-if="featured.length" class="article-grid"><RouterLink v-for="(page,i) in featured" :key="page.slug" :to="'/p/'+page.slug" class="article-card"><div class="article-illustration" :class="'art'+(i%4)"><span>{{ ['✳','✦','◈','☼'][i%4] }}</span><span class="article-index">0{{ i+1 }}</span></div><div class="article-card-body"><span class="eyebrow">FIELD NOTES / {{ String(i+1).padStart(2,'0') }}</span><h3>{{ page.title }}</h3><p>{{ page.summary }}</p><span class="article-arrow">Read story <b>↗</b></span></div></RouterLink></div><div v-else class="empty-state"><h3>No published pages yet</h3><p>Create your first page in the studio and publish it to see it here.</p><RouterLink class="btn primary" to="/studio">Open studio ↗</RouterLink></div></section>
    <section class="closing-banner"><div class="container closing-content"><span class="eyebrow light">CREATE THE NEXT BIG THING</span><h2>Your ideas deserve<br><em>a place to grow.</em></h2><RouterLink class="btn light" to="/studio">Enter content studio <span>↗</span></RouterLink></div></section>
  </main>
</template>
