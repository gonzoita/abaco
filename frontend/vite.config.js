import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig({
  base: './', // Genera rutas relativas para soportar subcarpetas en Laragon y Hostinger
  plugins: [vue()],
  build: {
    // Compila los archivos directamente a la raíz del proyecto de Laragon
    outDir: '../',
    // Evita borrar carpetas del backend o base de datos al compilar
    emptyOutDir: false
  }
})
