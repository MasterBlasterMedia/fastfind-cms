import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  plugins: [vue()],
  test: {
    environment: 'jsdom',
    include: ['tests/frontend/**/*.test.js'],
    clearMocks: true,
    coverage: {
      provider: 'v8',
      include: ['resources/js/builder/**/*.js', 'resources/js/components/Block.vue', 'resources/js/components/SectionLayout.vue', 'resources/js/components/SchemaFields.vue'],
      reporter: ['text', 'html', 'json-summary'],
    },
  },
});
