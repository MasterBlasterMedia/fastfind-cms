<script setup>
import { computed } from 'vue';
const props = defineProps({ block: { type: Object, required: true } });
const p = computed(() => props.block.props || {});
const items = computed(() => String(p.value.items || '').split('\n').map(x => x.trim()).filter(Boolean));
const link = computed(() => { const v = p.value.url || '#'; return v.startsWith('/') || v.startsWith('#') || /^https?:\/\//i.test(v) ? v : '#'; });
const src = computed(() => { const v = p.value.src || ''; return v.startsWith('/') || /^https?:\/\//i.test(v) ? v : ''; });
</script>
<template>
  <div class="content-block" :class="'block-' + block.type">
    <component :is="['h1','h2','h3','h4'].includes('h'+p.level) ? 'h'+p.level : 'h2'" v-if="block.type==='heading'">{{ p.text }}</component>
    <p v-else-if="block.type==='text'" class="body-copy">{{ p.text }}</p>
    <figure v-else-if="block.type==='image'" class="image-block"><img v-if="src" :src="src" :alt="p.alt || ''" loading="lazy" /><div v-else class="image-placeholder">Add an image to this block</div><figcaption v-if="p.caption">{{ p.caption }}</figcaption></figure>
    <a v-else-if="block.type==='button'" :href="link" class="btn primary">{{ p.text || 'Learn more' }} <span>↗</span></a>
    <blockquote v-else-if="block.type==='quote'"><p>“{{ p.text }}”</p><cite v-if="p.by">— {{ p.by }}</cite></blockquote>
    <aside v-else-if="block.type==='callout'" class="content-callout"><span class="callout-symbol">✦</span><div><strong>{{ p.title }}</strong><p>{{ p.text }}</p></div></aside>
    <hr v-else-if="block.type==='divider'" class="content-divider" />
    <div v-else-if="block.type==='list'"><h3 v-if="p.title">{{ p.title }}</h3><ul class="feature-list"><li v-for="(item,i) in items" :key="i">{{ item }}</li></ul></div>
    <div v-else-if="block.type==='stat'" class="content-stat"><strong>{{ p.value }}</strong><span>{{ p.label }}</span></div>
    <details v-else-if="block.type==='faq'" class="content-faq"><summary>{{ p.question }}</summary><p>{{ p.answer }}</p></details>
  </div>
</template>
