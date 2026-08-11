import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@inlayphp/resources': new URL('../frontend/src/index.ts', import.meta.url).pathname,
    },
    dedupe: ['react', 'react-dom'],
  },
  test: { environment: 'jsdom', setupFiles: ['./vitest.setup.ts'] },
})
