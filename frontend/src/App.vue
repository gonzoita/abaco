<template>
  <div class="app-container">
    <!-- Cabecera Móvil (Solo visible en pantallas móviles) -->
    <div v-if="isAuthenticated" class="mobile-header">
      <div class="mobile-logo-container">
        <img src="./assets/logo-white.png" class="logo-img logo-dark" alt="Ábaco" style="max-height: 28px;" />
        <img src="./assets/logo-black.png" class="logo-img logo-light" alt="Ábaco" style="max-height: 28px;" />
      </div>
      <div class="mobile-header-actions" style="display: flex; align-items: center;">
        <button class="theme-toggle-btn-mobile" @click="toggleTheme" title="Cambiar tema" style="background:none; border:none; color:var(--text-primary); font-size:18px; cursor:pointer;">
          <i :class="currentTheme === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon'"></i>
        </button>
        <button class="settings-toggle-btn-mobile" @click="showMobileSettings = true" title="Menú de opciones" style="background:none; border:none; padding:0; margin-left: 14px; cursor:pointer;">
          <div class="header-menu-pill">
            <i class="fa-solid fa-bars menu-icon-bar"></i>
            <div class="header-profile-avatar">
              {{ userInitials }}
            </div>
          </div>
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

      <!-- Presupuestos (Solo Desktop) -->
      <router-link to="/budgets" class="nav-item">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="12" y1="1" x2="12" y2="23"></line>
          <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
        </svg>
        <span>Presupuestos</span>
      </router-link>

      <!-- Préstamos (Solo Desktop) -->
      <router-link to="/loans" class="nav-item">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"></circle>
          <path d="M12 8v8M9 10h4.5a1.5 1.5 0 0 0 0-3H9M15 14H10.5a1.5 1.5 0 0 1 0-3H15"></path>
        </svg>
        <span>Préstamos</span>
      </router-link>

      <!-- Configuración (Solo Desktop) -->
      <router-link to="/settings" class="nav-item">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"></circle>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
        </svg>
        <span>Ajustes</span>
      </router-link>

      <!-- Administración (Solo Desktop) -->
      <router-link v-if="isAdmin" to="/admin" class="nav-item">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
        </svg>
        <span>Admin</span>
      </router-link>

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

    <!-- Menú de Ajustes y Perfil Móvil (Estilo Apple iOS HIG) -->
    <div class="mobile-settings-overlay" v-if="showMobileSettings" @click.self="showMobileSettings = false">
      <div class="mobile-settings-sheet glass-card">
        <div class="sheet-indicator"></div>
        <div class="settings-sheet-header">
          <h3>Menú</h3>
          <button class="close-sheet-btn" @click="showMobileSettings = false">✕</button>
        </div>
        
        <div class="user-profile-summary">
          <div class="profile-avatar">
            {{ userInitials }}
          </div>
          <div class="profile-info">
            <strong>{{ user.name }}</strong>
            <span>{{ user.email }}</span>
          </div>
        </div>

        <div class="settings-menu-list">
          <router-link to="/budgets" class="settings-menu-item" @click="showMobileSettings = false">
            <span class="item-icon budgets-color">
              <i class="fa-solid fa-chart-pie"></i>
            </span>
            <span>Presupuestos</span>
            <i class="fa-solid fa-chevron-right arrow-icon"></i>
          </router-link>

          <router-link to="/loans" class="settings-menu-item" @click="showMobileSettings = false">
            <span class="item-icon loans-color">
              <i class="fa-solid fa-hand-holding-dollar"></i>
            </span>
            <span>Préstamos</span>
            <i class="fa-solid fa-chevron-right arrow-icon"></i>
          </router-link>

          <router-link to="/settings" class="settings-menu-item" @click="showMobileSettings = false">
            <span class="item-icon settings-color">
              <i class="fa-solid fa-gear"></i>
            </span>
            <span>Ajustes y Categorías</span>
            <i class="fa-solid fa-chevron-right arrow-icon"></i>
          </router-link>

          <router-link v-if="isAdmin" to="/admin" class="settings-menu-item" @click="showMobileSettings = false">
            <span class="item-icon admin-color">
              <i class="fa-solid fa-shield-halved"></i>
            </span>
            <span>Administración</span>
            <i class="fa-solid fa-chevron-right arrow-icon"></i>
          </router-link>

          <hr class="menu-divider" />

          <a href="#" @click.prevent="logoutAndClose" class="settings-menu-item logout-item">
            <span class="item-icon logout-color">
              <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </span>
            <span>Cerrar Sesión</span>
            <i class="fa-solid fa-chevron-right arrow-icon"></i>
          </a>
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
    const user = ref({ name: '', email: '' })
    const userInitials = ref('')
    const router = useRouter()
    const route = useRoute()

    // Estados de navegación móvil
    const showQuickActions = ref(false)
    const showMobileSettings = ref(false)

    const checkAuth = () => {
      isAuthenticated.value = !!localStorage.getItem('token')
      const userData = JSON.parse(localStorage.getItem('user') || '{}')
      user.value = userData
      isAdmin.value = userData.role === 'admin'
      
      if (userData.name) {
        const parts = userData.name.split(' ')
        userInitials.value = parts.map(p => p[0]).join('').substring(0, 2).toUpperCase()
      } else {
        userInitials.value = 'U'
      }
    }

    const logout = () => {
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      checkAuth()
      router.push('/login')
    }

    const logoutAndClose = () => {
      showMobileSettings.value = false
      logout()
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
      user,
      userInitials,
      checkAuth,
      logout,
      logoutAndClose,
      toggleTheme,
      showQuickActions,
      showMobileSettings,
      triggerQuickAction
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

/* ==========================================================================
   MENÚ AJUSTES MÓVIL ESTILO APPLE (iOS HIG)
   ========================================================================== */
.mobile-settings-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.4);
  z-index: 2000;
  display: flex;
  justify-content: flex-end;
  align-items: flex-end;
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
}

.mobile-settings-sheet {
  width: 100%;
  max-height: 85vh;
  border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  background: var(--card-bg);
  border: 1px solid var(--card-border);
  border-bottom: none;
  padding: 24px;
  display: flex;
  flex-direction: column;
  animation: slideUpMobile 0.4s cubic-bezier(0.16, 1, 0.3, 1);
  overflow-y: auto;
}

.settings-sheet-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
}

.settings-sheet-header h3 {
  font-size: 20px;
  font-weight: 700;
  color: var(--text-primary);
}

.close-sheet-btn {
  background: rgba(255, 255, 255, 0.08);
  border: none;
  color: var(--text-secondary);
  width: 32px;
  height: 32px;
  border-radius: 50%;
  font-size: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}
body.light-theme .close-sheet-btn {
  background: rgba(0, 0, 0, 0.05);
}

/* Resumen del perfil */
.user-profile-summary {
  display: flex;
  align-items: center;
  gap: 16px;
  background: rgba(255, 255, 255, 0.02);
  padding: 16px;
  border-radius: var(--radius-md);
  border: 1px solid var(--card-border);
  margin-bottom: 24px;
}
body.light-theme .user-profile-summary {
  background: rgba(0, 0, 0, 0.01);
}

.profile-avatar {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  background: var(--color-primary);
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  font-weight: 700;
}

.profile-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.profile-info strong {
  font-size: 16px;
  color: var(--text-primary);
}

.profile-info span {
  font-size: 13px;
  color: var(--text-muted);
}

/* Lista de items */
.settings-menu-list {
  display: flex;
  flex-direction: column;
  background: rgba(255, 255, 255, 0.02);
  border-radius: var(--radius-md);
  border: 1px solid var(--card-border);
  overflow: hidden;
  margin-bottom: 20px;
}
body.light-theme .settings-menu-list {
  background: rgba(0, 0, 0, 0.01);
}

.settings-menu-item {
  display: flex;
  align-items: center;
  padding: 16px;
  text-decoration: none;
  color: var(--text-primary);
  font-size: 15px;
  font-weight: 500;
  transition: var(--transition-smooth);
  border-bottom: 1px solid var(--card-border);
}

.settings-menu-item:last-child {
  border-bottom: none;
}

.settings-menu-item:hover {
  background: rgba(255, 255, 255, 0.05);
}
body.light-theme .settings-menu-item:hover {
  background: rgba(0, 0, 0, 0.02);
}

.item-icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 14px;
  margin-right: 14px;
}

.budgets-color { background: #ff9f0a; }
.loans-color { background: #30d158; }
.settings-color { background: #8e8e93; }
.admin-color { background: #0a84ff; }
.logout-color { background: #ff453a; }

body.light-theme .budgets-color { background: #ff9500; }
body.light-theme .loans-color { background: #2fa84e; }
body.light-theme .settings-color { background: #8e8e93; }
body.light-theme .admin-color { background: #007aff; }
body.light-theme .logout-color { background: #ff3b30; }

.arrow-icon {
  margin-left: auto;
  font-size: 12px;
  color: var(--text-muted);
}

.menu-divider {
  border: 0;
  border-top: 1px solid var(--card-border);
  margin: 0;
}

.logout-item span {
  color: var(--color-danger);
}

.header-profile-avatar {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: var(--color-primary);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 700;
  box-shadow: 0 2px 8px rgba(0, 122, 255, 0.25);
  transition: transform 0.2s ease;
}
.header-profile-avatar:active {
  transform: scale(0.95);
}

.header-menu-pill {
  display: flex;
  align-items: center;
  gap: 8px;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid var(--card-border);
  padding: 3px 3px 3px 10px;
  border-radius: 30px;
  transition: all 0.2s ease;
}
body.light-theme .header-menu-pill {
  background: rgba(0, 0, 0, 0.03);
}
.header-menu-pill:hover, .header-menu-pill:active {
  background: rgba(255, 255, 255, 0.1);
  border-color: rgba(255, 255, 255, 0.15);
}
body.light-theme .header-menu-pill:hover, body.light-theme .header-menu-pill:active {
  background: rgba(0, 0, 0, 0.06);
}
.menu-icon-bar {
  font-size: 13px;
  color: var(--text-secondary);
}
</style>

<style>
/* Estilos globales importados en main.css */
</style>
