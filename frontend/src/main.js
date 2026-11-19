// C:\laragon\www\control-finanzas\frontend\src\main.js
import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
// Fuentes e iconos propios (antes venían de Google Fonts y de un CDN: tres
// dominios externos que había que resolver antes de pintar la pantalla).
import '@fontsource/outfit/latin-300.css'
import '@fontsource/outfit/latin-400.css'
import '@fontsource/outfit/latin-500.css'
import '@fontsource/outfit/latin-600.css'
import '@fontsource/outfit/latin-700.css'
import '@fortawesome/fontawesome-free/css/fontawesome.min.css'
import '@fortawesome/fontawesome-free/css/solid.min.css'
import '@fortawesome/fontawesome-free/css/regular.min.css'
import '@fortawesome/fontawesome-free/css/brands.min.css'
import './assets/main.css'
import './registerServiceWorker'

createApp(App).use(router).mount('#app')
