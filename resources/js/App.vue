<script setup>
import { computed, onMounted, provide, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get, post, remove, failure } from './lib';

const route = useRoute();
const router = useRouter();
const isStudio = computed(() => route.path.startsWith('/studio'));
const user = ref(null);
const site = ref({ brand: { name: 'FastFind', tagline: 'Everything worth discovering, in one place.' }, navigation: [] });
const ready = ref(false);
const loginError = ref('');
const credentials = ref({ email: '', password: '' });
const authBusy = ref(false);
const globalSearch = ref('');
const mobileMenu = ref(false);
const notice = ref('');

function toast(message) { notice.value = message; setTimeout(() => { if (notice.value === message) notice.value = ''; }, 5000); }
provide('session', { user, toast, reloadSite });
async function reloadSite() {
  try { site.value = await get('/site'); } catch { /* default site settings */ }
}
async function loadUser() {
  try { user.value = (await get('/session')).user; } catch { user.value = null; }
}
async function login() {
  authBusy.value = true;
  loginError.value = '';
  try { user.value = (await post('/session', credentials.value)).user; credentials.value.password = ''; }
  catch (e) { loginError.value = failure(e); }
  finally { authBusy.value = false; }
}
async function logout() {
  const reply = await remove('/session'); const meta = document.querySelector('meta[name="csrf-token"]'); if(meta && reply.csrf_token) meta.content = reply.csrf_token; user.value = null; router.push('/');
}
function search() {
  if (globalSearch.value.trim().length < 2) return;
  router.push({ path: '/search', query: { q: globalSearch.value.trim() } });
}
watch(() => route.fullPath, () => { mobileMenu.value = false; });
onMounted(async () => { await Promise.all([loadUser(), reloadSite()]); ready.value = true; });
</script>

<template>
  <div v-if="!ready" class="initial-load">Opening your workspace…</div>
  <div v-else-if="isStudio && !user" class="auth-shell">
    <div class="auth-art">
      <RouterLink class="brand brand--light" to="/"><span class="brand-mark">✳</span><span>FastFind<span class="brand-period">.</span></span></RouterLink>
      <div class="auth-art-body"><div class="eyebrow light">A NEW WAY TO CREATE</div><h1>A home for<br><em>every idea.</em></h1><p>Beautiful publishing tools. Thoughtful details. All yours.</p><div class="auth-orbit">✳</div></div>
      <div class="auth-art-foot">FASTFIND CONTENT STUDIO  /  INDEPENDENT BY DESIGN</div>
    </div>
    <main class="auth-main">
      <div class="login-panel"><span class="eyebrow">YOUR WORKSPACE</span><h2>Welcome back.</h2><p>Sign in to shape something great.</p>
        <form @submit.prevent="login" class="stack-field">
          <label>Email address<input v-model.trim="credentials.email" autocomplete="username" type="email" required placeholder="you@example.com" /></label>
          <label>Password<input v-model="credentials.password" type="password" autocomplete="current-password" required placeholder="Your password" /></label>
          <p v-if="loginError" class="alert error" role="alert">{{ loginError }}</p>
          <button class="btn primary wide" :disabled="authBusy">{{ authBusy ? 'Signing in…' : 'Sign in to studio' }} <span>↗</span></button>
        </form>
        <p class="footnote">Accounts are created by a workspace owner. Public registration is disabled.</p>
        <RouterLink to="/" class="text-link">← Back to website</RouterLink>
      </div>
    </main>
  </div>
  <template v-else-if="isStudio"><RouterView :key="route.path" :user="user" @logout="logout" /></template>
  <div v-else class="public-shell">
    <header class="public-header container">
      <RouterLink to="/" class="brand"><span class="brand-mark">✳</span><span>{{ site.brand?.name || 'FastFind' }}<span class="brand-period">.</span></span></RouterLink>
      <nav :class="['public-nav', { open: mobileMenu }]" aria-label="Main navigation">
        <RouterLink v-for="(item, i) in site.navigation" :key="i" :to="item.url">{{ item.label }}</RouterLink>
      </nav>
      <div class="public-actions"><form class="nav-search" @submit.prevent="search"><span>⌕</span><input v-model="globalSearch" placeholder="Search anything" aria-label="Search site" /><kbd>↵</kbd></form><RouterLink class="btn ghost small" to="/studio">Studio <span>↗</span></RouterLink><button class="mobile-toggle" @click="mobileMenu=!mobileMenu" aria-label="Toggle navigation">☰</button></div>
    </header>
    <RouterView :key="route.fullPath" />
    <footer class="public-footer"><div class="container footer-flex"><div><RouterLink to="/" class="brand"><span class="brand-mark">✳</span><span>FastFind<span class="brand-period">.</span></span></RouterLink><p>Publish ideas worth discovering.</p></div><div class="foot-links"><RouterLink to="/">Explore</RouterLink><RouterLink to="/search">Search</RouterLink><RouterLink to="/studio">Content studio</RouterLink></div><span class="footnote">© {{ new Date().getFullYear() }} FastFind</span></div></footer>
  </div>
  <div v-if="notice" class="toast" role="status">{{ notice }}</div>
</template>
