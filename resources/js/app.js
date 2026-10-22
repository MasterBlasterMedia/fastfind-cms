import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';

const router = createRouter({ history: createWebHistory(), routes: [
  { path: '/', component: () => import('./views/Home.vue') },
  { path: '/p/:slug', component: () => import('./views/Page.vue') },
  { path: '/search', component: () => import('./views/Search.vue') },
  { path: '/studio/pages/:id', component: () => import('./views/Editor.vue') },
  { path: '/studio/plugins', component: () => import('./views/Plugins.vue') },
  { path: '/studio/themes', component: () => import('./views/Themes.vue') },
  { path: '/studio/health', component: () => import('./views/Health.vue') },
  { path: '/studio/extensions/:vendor/:name/:view', component: () => import('./views/Extension.vue') },
  { path: '/studio/:section?', component: () => import('./views/Studio.vue') },
  { path: '/:pathMatch(.*)*', component: () => import('./views/NotFound.vue') },
], scrollBehavior: () => ({ top: 0 }) });

createApp(App).use(router).mount('#app');
