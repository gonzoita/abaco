// C:\laragon\www\control-finanzas\frontend\src\router\index.js
import { createRouter, createWebHashHistory } from 'vue-router'
import DashboardView from '../views/DashboardView.vue'
import LoginView from '../views/LoginView.vue'
import RegisterView from '../views/RegisterView.vue'
import AccountsView from '../views/AccountsView.vue'
import AIChatView from '../views/AIChatView.vue'
import BudgetsView from '../views/BudgetsView.vue'
import SettingsView from '../views/SettingsView.vue'
import LoansView from '../views/LoansView.vue'

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
    component: LoginView
  },
  {
    path: '/register',
    name: 'register',
    component: RegisterView
  },
  {
    path: '/accounts',
    name: 'accounts',
    component: AccountsView,
    meta: { requiresAuth: true }
  },
  {
    path: '/ai',
    name: 'ai',
    component: AIChatView,
    meta: { requiresAuth: true }
  },
  {
    path: '/budgets',
    name: 'budgets',
    component: BudgetsView,
    meta: { requiresAuth: true }
  },
  {
    path: '/loans',
    name: 'loans',
    component: LoansView,
    meta: { requiresAuth: true }
  },
  {
    path: '/settings',
    name: 'settings',
    component: SettingsView,
    meta: { requiresAuth: true }
  },
  {
    path: '/tutorials',
    name: 'tutorials',
    component: () => import('../views/TutorialsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/admin',
    name: 'admin',
    component: () => import('../views/AdminView.vue'),
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
