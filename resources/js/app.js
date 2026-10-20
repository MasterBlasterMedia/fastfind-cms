import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import Home from './views/Home.vue';
import Page from './views/Page.vue';
import Search from './views/Search.vue';
import Studio from './views/Studio.vue';
import Editor from './views/Editor.vue';

const router = createRouter({ history: createWebHistory(), routes: [
  { path: '/', component: Home },
  { path: '/p/:slug', component: Page },
  { path: '/search', component: Search },
  { path: '/studio/pages/:id', component: Editor },
  { path: '/studio/:section?', component: Studio },
  { path: '/:pathMatch(.*)*', redirect: '/' },
], scrollBehavior: () => ({ top: 0 }) });

createApp(App).use(router).mount('#app');
