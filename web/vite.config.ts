import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 5173,
    // precisa ouvir em todas as interfaces para ficar acessível
    // de fora do container (não só do localhost do container)
    host: true,
    watch: {
      // bind mount do Docker nem sempre propaga eventos nativos de FS
      // (comum no Docker Desktop/Mac/Windows); polling garante que o
      // Vite detecte as mudanças salvas na sua máquina
      usePolling: !!process.env.DOCKER,
    },
  },
  test: {
    environment: 'jsdom',
    environmentOptions: { jsdom: { url: 'http://localhost/' } }, // origem real: sessionStorage/localStorage funcionam e limpam sem erro
    globals: true,
    setupFiles: ['./tests/setup.ts'],
  },
});