<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
const props=defineProps({ user: { type:Object, required:true }, pageTitle: {type:String,default:'Overview'}, subtitle:{type:String,default:'Your content, your canvas.'} });
const emit=defineEmits(['logout']); const route=useRoute();
const canManage=computed(()=>['owner','admin'].includes(props.user?.role));
const items=computed(()=>[{to:'/studio',symbol:'▦',name:'Overview'},{to:'/studio/pages',symbol:'▤',name:'Pages'},{to:'/studio/media',symbol:'▧',name:'Media library'}, ...(canManage.value?[{to:'/studio/navigation',symbol:'⇄',name:'Navigation'},{to:'/studio/settings',symbol:'⚙',name:'Site settings'}]:[]), ...(props.user?.role==='owner'?[{to:'/studio/team',symbol:'♧',name:'Team members'}]:[])]);
</script>
<template>
<div class="studio-shell">
  <aside class="studio-sidebar">
    <RouterLink to="/" class="brand brand--light studio-brand"><span class="brand-mark">✳</span><span>FastFind<span class="brand-period">.</span></span><span class="studio-tag">STUDIO</span></RouterLink>
    <div class="sidebar-eyebrow">WORKSPACE</div>
    <nav aria-label="Studio navigation" class="sidebar-nav"><RouterLink v-for="item in items" :key="item.to" :to="item.to" :class="{'active-nav':(item.to==='/studio' ? route.path==='/studio' : route.path.startsWith(item.to))}"><span class="nav-symbol">{{ item.symbol }}</span>{{ item.name }}<span class="nav-chevron">↗</span></RouterLink></nav>
    <div class="sidebar-spacer"></div>
    <div class="sidebar-bottom"><RouterLink to="/" target="_blank" class="sidebar-website">↗ <span>View live website</span></RouterLink><div class="sidebar-profile"><div class="avatar">{{ user?.name?.slice(0,1).toUpperCase() }}</div><div class="profile-info"><strong>{{ user?.name }}</strong><small>{{ user?.role }}</small></div><button @click="emit('logout')" title="Sign out" class="icon-button" aria-label="Sign out">⇥</button></div></div>
  </aside>
  <div class="studio-main"><header class="studio-topbar"><div><div class="crumb"><span>WORKSPACE</span><span>/</span><span>{{ pageTitle }}</span></div><h1>{{ pageTitle }}</h1><p>{{ subtitle }}</p></div><div class="topbar-actions"><span class="status-light"><i></i> Your workspace</span><slot name="toolbar" /></div></header><div class="studio-content"><slot /></div></div>
</div>
</template>
