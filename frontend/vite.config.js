import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// Laravel API used by the dev server proxy. Override with VITE_DEV_API_TARGET
// when `php artisan serve` runs on a different host/port.
const apiTarget = process.env.VITE_DEV_API_TARGET || 'http://127.0.0.1:8000'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': { target: apiTarget, changeOrigin: true },
      '/sanctum': { target: apiTarget, changeOrigin: true },
    },
  },
})