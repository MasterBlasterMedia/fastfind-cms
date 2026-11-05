<script setup>
import { computed, inject, ref } from 'vue';
import { useRoute } from 'vue-router';
const props=defineProps({ user: { type:Object, required:true }, pageTitle: {type:String,default:'Overview'}, subtitle:{type:String,default:'Your content, your canvas.'} });
const emit=defineEmits(['logout']); const route=useRoute();
const { extensions = ref({ admin_views: [] }), colorMode = ref('light'), toggleColorMode = () => {} } = inject('session', {});
const canManage=computed(()=>['owner','admin'].includes(props.user?.role));
const items=computed(()=>[{to:'/studio',symbol:'▦',name:'Overview'},{to:'/studio/pages',symbol:'▤',name:'Pages'},{to:'/studio/media',symbol:'▧',name:'Media library'}, ...(canManage.value?[{to:'/studio/navigation',symbol:'⇄',name:'Navigation'},{to:'/studio/themes',symbol:'◈',name:'Themes'},{to:'/studio/settings',symbol:'⚙',name:'Site settings'}, ...(extensions.value.admin_views || []).map(view=>({to:`/studio/extensions/${view.plugin_id}/${view.id}`,symbol:'✦',name:view.label}))]:[]), ...(props.user?.role==='owner'?[{to:'/studio/plugins',symbol:'⊞',name:'Plugins'},{to:'/studio/marketplace',symbol:'◈',name:'Marketplace'},{to:'/studio/team',symbol:'♧',name:'Team members'},{to:'/studio/health',symbol:'◉',name:'System health'}]:[])]);
</script>
<template>
<div class="studio-shell" :data-color-mode="colorMode">
  <a href="#studio-main" class="skip-link">Skip to workspace</a>
  <aside class="studio-sidebar">
    <RouterLink to="/" class="brand brand--light studio-brand"><span class="brand-mark">✳</span><span>FastFind<span class="brand-period">.</span></span><span class="studio-tag">STUDIO</span></RouterLink>
    <div class="sidebar-eyebrow">WORKSPACE</div>
    <nav aria-label="Studio navigation" class="sidebar-nav"><RouterLink v-for="item in items" :key="item.to" :to="item.to" :aria-label="item.name" :title="item.name" :class="{'active-nav':(item.to==='/studio' ? route.path==='/studio' : route.path.startsWith(item.to))}"><span class="nav-symbol" aria-hidden="true">{{ item.symbol }}</span>{{ item.name }}<span class="nav-chevron" aria-hidden="true">↗</span></RouterLink></nav>
    <div class="sidebar-spacer"></div>
    <div class="sidebar-bottom"><RouterLink to="/" target="_blank" class="sidebar-website">↗ <span>View live website</span></RouterLink><div class="sidebar-profile"><div class="avatar">{{ user?.name?.slice(0,1).toUpperCase() }}</div><div class="profile-info"><strong>{{ user?.name }}</strong><small>{{ user?.role }}</small></div><button @click="emit('logout')" title="Sign out" class="icon-button" aria-label="Sign out">⇥</button></div></div>
  </aside>
  <main id="studio-main" class="studio-main" tabindex="-1"><header class="studio-topbar"><div><div class="crumb"><span>WORKSPACE</span><span>/</span><span>{{ pageTitle }}</span></div><h1>{{ pageTitle }}</h1><p>{{ subtitle }}</p></div><div class="topbar-actions"><button class="btn outline small color-mode-toggle" @click="toggleColorMode">{{ colorMode === 'dark' ? 'Light mode' : 'Dark mode' }}</button><slot name="toolbar" /></div></header><div class="studio-content"><slot /></div></main>
</div>
</template>
