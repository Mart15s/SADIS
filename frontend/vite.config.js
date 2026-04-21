import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    setupFiles: './src/test/setup.js',
    pool: 'forks',
    poolOptions: {
      forks: {
        singleFork: true,
      },
    },
    maxWorkers: 1,
    minWorkers: 1,
  },
  server: {
    proxy: {
      '/api': {
        target: process.env.VITE_BACKEND_URL ?? 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
      '/sanctum': {
        target: process.env.VITE_BACKEND_URL ?? 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})
