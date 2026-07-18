// C:\laragon\www\control-finanzas\frontend\src\main.js
import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import './assets/main.css'
import './registerServiceWorker'

createApp(App).use(router).mount('#app')
