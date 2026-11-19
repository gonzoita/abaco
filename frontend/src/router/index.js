// C:\laragon\www\control-finanzas\frontend\src\router\index.js
import { createRouter, createWebHashHistory } from 'vue-router'
import DashboardView from '../views/DashboardView.vue'

const routes = [
  {
    path: '/',
    name: 'dashboard',
    component: DashboardView,
    meta: { requiresAuth: true }
  },
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue')
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('../views/RegisterView.vue')
  },
  {
    path: '/legal',
    name: 'legal',
    component: () => import('../views/LegalView.vue')
  },
  {
    path: '/accounts',
    name: 'accounts',
    component: () => import('../views/AccountsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/ai',
    name: 'ai',
    component: () => import('../views/AIChatView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/budgets',
    name: 'budgets',
    component: () => import('../views/BudgetsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/loans',
    name: 'loans',
    component: () => import('../views/LoansView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/settings',
    name: 'settings',
    component: () => import('../views/SettingsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/tutorials',
    name: 'tutorials',
    component: () => import('../views/TutorialsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/reports',
    name: 'reports',
    component: () => import('../views/ReportsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/financial-health',
    name: 'financial-health',
    component: () => import('../views/FinancialHealthView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/admin',
    name: 'admin',
    component: () => import('../views/AdminView.vue'),
    meta: { requiresAuth: true, requiresAdmin: true }
  },
  {
    path: '/admin/prompts',
    name: 'admin-prompts',
    component: () => import('../views/AdminPromptsView.vue'),
    meta: { requiresAuth: true, requiresAdmin: true }
  }
]

const router = createRouter({
  // Usar hash history es ideal para PWA estáticas y evitar problemas de redirección 404 en subcarpetas de Hostinger
  history: createWebHashHistory(),
  routes
})

// Guardia de navegación para proteger rutas autenticadas
router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('token')
  const user = JSON.parse(localStorage.getItem('user') || '{}')
  
  if (to.meta.requiresAuth && !token) {
    next({ name: 'login' })
  } else if (to.meta.requiresAdmin && user.role !== 'admin') {
    next({ name: 'dashboard' })
  } else if ((to.name === 'login' || to.name === 'register') && token) {
    next({ name: 'dashboard' })
  } else {
    next()
  }
})

export default router
