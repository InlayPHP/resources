import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@inlayphp/resources': new URL('../frontend/src/index.ts', import.meta.url).pathname,
    },
    dedupe: ['vue'],
  },
  build: {
    lib: { entry: 'src/index.ts', formats: ['es'], fileName: 'index' },
    rollupOptions: { external: ['vue', '@inertiajs/vue3', '@inlayphp/resources', '@inlayphp/actions', '@inlayphp/actions-vue', '@inlayphp/forms-vue', '@inlayphp/tables-vue', '@inlayphp/widgets-vue', '@inlayphp/ui-vue'] },
  },
  test: { environment: 'jsdom', setupFiles: ['./vitest.setup.ts'] },
})
