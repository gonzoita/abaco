<template>
  <div class="app-container">
    <!-- Botón de tema flotante (Siempre visible) -->
    <button class="theme-toggle-floating" @click="toggleTheme" title="Cambiar Tema">
      <svg v-if="currentTheme === 'dark'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px; height:18px; display:block;">
        <circle cx="12" cy="12" r="5"></circle>
        <line x1="12" y1="1" x2="12" y2="3"></line>
        <line x1="12" y1="21" x2="12" y2="23"></line>
        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
        <line x1="1" y1="12" x2="3" y2="12"></line>
        <line x1="21" y1="12" x2="23" y2="12"></line>
        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
      </svg>
      <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px; height:18px; display:block;">
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
      </svg>
    </button>

    <!-- Cabecera Móvil (Solo visible en pantallas móviles) -->
    <div v-if="isAuthenticated" class="mobile-header">
      <div class="mobile-logo-container">
        <img src="./assets/logo-white.png" class="logo-img logo-dark" alt="Ábaco" style="max-height: 28px;" />
        <img src="./assets/logo-black.png" class="logo-img logo-light" alt="Ábaco" style="max-height: 28px;" />
      </div>
      <div class="mobile-header-actions">
        <button class="theme-toggle-btn-mobile" @click="toggleTheme" title="Cambiar tema" style="background:none; border:none; color:var(--text-primary); font-size:18px; cursor:pointer;">
          <i :class="currentTheme === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon'"></i>
        </button>
      </div>
    </div>

    <!-- Barra de navegación (Sidebar en desktop, Bottom bar en móvil) - Solo visible si está autenticado -->
    <nav v-if="isAuthenticated" class="mobile-nav">
      <!-- Logotipo oficial de Ábaco (Solo visible en desktop) -->
      <div class="sidebar-logo">
        <img src="./assets/logo-white.png" class="logo-img logo-dark" alt="Ábaco" />
        <img src="./assets/logo-black.png" class="logo-img logo-light" alt="Ábaco" />
      </div>

      <!-- Dashboard -->
      <router-link to="/" class="nav-item mobile-visible">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="7" height="9"></rect>
          <rect x="14" y="3" width="7" height="5"></rect>
          <rect x="14" y="12" width="7" height="9"></rect>
          <rect x="3" y="16" width="7" height="5"></rect>
        </svg>
        <span>Dashboard</span>
      </router-link>

      <!-- Cuentas y Tarjetas -->
      <router-link to="/accounts" class="nav-item mobile-visible">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
          <line x1="1" y1="10" x2="23" y2="10"></line>
        </svg>
        <span>Cuentas</span>
      </router-link>

      <!-- Botón Flotante Central "+" (Acciones Rápidas - Solo Móvil) -->
      <button class="nav-item mobile-only-action mobile-visible btn-central-plus" @click="showQuickActions = true">
        <div class="plus-icon-circle">
          <i class="fa-solid fa-plus"></i>
        </div>
        <span>Nuevo</span>
      </button>

      <!-- IA Asistente -->
      <router-link to="/ai" class="nav-item mobile-visible">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"></path>
          <path d="M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10z"></path>
        </svg>
        <span>Asesor IA</span>
      </router-link>

      <!-- Presupuestos -->
      <router-link to="/budgets" class="nav-item">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="12" y1="1" x2="12" y2="23"></line>
          <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
        </svg>
        <span>Presupuestos</span>
      </router-link>

      <!-- Préstamos -->
      <router-link to="/loans" class="nav-item">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"></circle>
          <path d="M12 8v8M9 10h4.5a1.5 1.5 0 0 0 0-3H9M15 14H10.5a1.5 1.5 0 0 1 0-3H15"></path>
        </svg>
        <span>Préstamos</span>
      </router-link>

      <!-- Configuración -->
      <router-link to="/settings" class="nav-item">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"></circle>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
        </svg>
        <span>Ajustes</span>
      </router-link>

      <!-- Administración -->
      <router-link v-if="isAdmin" to="/admin" class="nav-item">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
        </svg>
        <span>Admin</span>
      </router-link>

      <!-- Botón "Más" (Menú Alternativo - Solo Móvil) -->
      <button class="nav-item mobile-only-menu mobile-visible" @click="showMoreMenu = true">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="3" y1="12" x2="21" y2="12"></line>
          <line x1="3" y1="6" x2="21" y2="6"></line>
          <line x1="3" y1="18" x2="21" y2="18"></line>
        </svg>
        <span>Más</span>
      </button>

      <!-- Cerrar Sesión -->
      <a href="#" @click.prevent="logout" class="nav-item">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
          <polyline points="16 17 21 12 16 7"></polyline>
          <line x1="21" y1="12" x2="9" y2="12"></line>
        </svg>
        <span>Salir</span>
      </a>
    </nav>

    <!-- Bottom Sheet de Acciones Rápidas (Solo Móvil) -->
    <div class="quick-actions-overlay" v-if="showQuickActions" @click.self="showQuickActions = false">
      <div class="quick-actions-sheet glass-card">
        <div class="sheet-indicator"></div>
        <h3 class="sheet-title">Nueva Transacción</h3>
        <div class="sheet-options">
          <button class="sheet-btn expense" @click="triggerQuickAction('expense')">
            <div class="btn-icon-circle"><i class="fa-solid fa-minus"></i></div>
            <span>Gasto</span>
          </button>
          
          <button class="sheet-btn income" @click="triggerQuickAction('income')">
            <div class="btn-icon-circle"><i class="fa-solid fa-plus"></i></div>
            <span>Ingreso</span>
          </button>
          
          <button class="sheet-btn scan" @click="triggerQuickAction('scan')">
            <div class="btn-icon-circle"><i class="fa-solid fa-sparkles"></i></div>
            <span>Escanear Recibo</span>
          </button>
        </div>
        <button class="btn-sheet-close" @click="showQuickActions = false">Cancelar</button>
      </div>
    </div>

    <!-- Menú "Más" Fullscreen Overlay (Solo Móvil) -->
    <div class="more-menu-overlay" v-if="showMoreMenu" @click.self="showMoreMenu = false">
      <div class="more-menu-card glass-card">
        <div class="menu-header">
          <h3>Opciones</h3>
          <button class="btn-menu-close" @click="showMoreMenu = false">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
        <div class="menu-list">
          <router-link to="/budgets" class="menu-item" @click="showMoreMenu = false">
            <i class="fa-solid fa-chart-pie"></i>
            <span>Presupuestos</span>
          </router-link>
          
          <router-link to="/loans" class="menu-item" @click="showMoreMenu = false">
            <i class="fa-solid fa-hand-holding-dollar"></i>
            <span>Préstamos</span>
          </router-link>
          
          <router-link to="/settings" class="menu-item" @click="showMoreMenu = false">
            <i class="fa-solid fa-gear"></i>
            <span>Ajustes y Categorías</span>
          </router-link>
          
          <router-link v-if="isAdmin" to="/admin" class="menu-item" @click="showMoreMenu = false">
            <i class="fa-solid fa-shield-halved"></i>
            <span>Administración</span>
          </router-link>
          
          <hr class="menu-divider" style="border:0; border-top:1px solid var(--card-border); margin:12px 0;" />
          
          <button class="menu-item logout-btn" @click="handleMobileLogout" style="background:none; border:none; color:#ff453a; width:100%; text-align:left; cursor:pointer;">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>Cerrar Sesión</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Contenido principal -->
    <main class="content-wrapper">
      <router-view v-slot="{ Component }">
        <transition name="fade" mode="out-in">
          <component :is="Component" @auth-change="checkAuth" />
        </transition>
      </router-view>
    </main>
  </div>
</template>

<script>
import { ref, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'

export default {
  name: 'App',
  setup() {
    const isAuthenticated = ref(false)
    const isAdmin = ref(false)
    const currentTheme = ref(localStorage.getItem('theme') || 'dark')
    const router = useRouter()
    const route = useRoute()

    // Estados de navegación móvil
    const showQuickActions = ref(false)
    const showMoreMenu = ref(false)

    const checkAuth = () => {
      isAuthenticated.value = !!localStorage.getItem('token')
      const user = JSON.parse(localStorage.getItem('user') || '{}')
      isAdmin.value = user.role === 'admin'
    }

    const logout = () => {
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      checkAuth()
      router.push('/login')
    }

    const toggleTheme = () => {
      const newTheme = currentTheme.value === 'dark' ? 'light' : 'dark'
      currentTheme.value = newTheme
      localStorage.setItem('theme', newTheme)
      document.body.classList.toggle('light-theme', newTheme === 'light')
    }

    const triggerQuickAction = (actionType) => {
      showQuickActions.value = false
      router.push({ path: '/', query: { action: actionType, t: Date.now() } })
    }

    const handleMobileLogout = () => {
      showMoreMenu.value = false
      logout()
    }

    onMounted(() => {
      checkAuth()
      // Cargar e inicializar tema
      const savedTheme = localStorage.getItem('theme') || 'dark'
      currentTheme.value = savedTheme
      document.body.classList.toggle('light-theme', savedTheme === 'light')
    })

    // Monitorear cambios en las rutas para validar autenticación
    watch(() => route.path, () => {
      checkAuth()
    })

    return {
      isAuthenticated,
      isAdmin,
      currentTheme,
      checkAuth,
      logout,
      toggleTheme,
      showQuickActions,
      showMoreMenu,
      triggerQuickAction,
      handleMobileLogout
    }
  }
}
</script>

<style>
/* Botón de Tema Flotante (Apple HIG) */
.theme-toggle-floating {
  position: fixed;
  top: 14px;
  right: 14px;
  z-index: 1000;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--card-bg);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border: 1px solid var(--card-border);
  color: var(--text-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
  transition: var(--transition-smooth);
}
.theme-toggle-floating:hover {
  transform: scale(1.05);
}
.theme-toggle-floating:active {
  transform: scale(0.95);
}

/* Estilos de Logo en Barra Lateral (Apple HIG) */
.sidebar-logo {
  display: none;
  width: 100%;
  padding-bottom: 20px;
  margin-bottom: 10px;
  border-bottom: 1px solid var(--card-border);
  justify-content: center;
  align-items: center;
}
.sidebar-logo .logo-img {
  max-height: 38px;
  width: auto;
}
.sidebar-logo .logo-light {
  display: none;
}
body.light-theme .sidebar-logo .logo-dark {
  display: none;
}
body.light-theme .sidebar-logo .logo-light {
  display: block;
}
@media (min-width: 769px) {
  .sidebar-logo {
    display: flex;
  }
}
</style>

<style>
/* Estilos globales importados en main.css */
</style>
