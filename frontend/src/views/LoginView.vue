<template>
  <div class="login-container">
    <div class="glass-card auth-card">
      <div class="auth-header">
        <!-- Logo oficial de Ábaco en lugar de icono SVG -->
        <div class="logo-brand-container">
          <img src="../assets/logo-white.png" class="logo-img logo-dark" alt="Ábaco" />
          <img src="../assets/logo-black.png" class="logo-img logo-light" alt="Ábaco" />
        </div>
        <p class="auth-subtitle">Controla tus finanzas inteligentes</p>
      </div>

      <form @submit.prevent="handleLogin" class="auth-form">
        <div class="form-group">
          <label for="email">Correo Electrónico</label>
          <input type="email" id="email" v-model="email" placeholder="ejemplo@correo.com" required />
        </div>

        <div class="form-group">
          <label for="password">Contraseña</label>
          <input type="password" id="password" v-model="password" placeholder="••••••••" required />
        </div>

        <div v-if="errorMessage" class="error-msg">
          {{ errorMessage }}
        </div>

        <button type="submit" class="btn-primary btn-block" :disabled="loading">
          <span v-if="loading">Cargando...</span>
          <span v-else>Iniciar Sesión</span>
        </button>
      </form>

      <!-- Divisor para inicio con Google -->
      <div class="auth-divider">
        <span>o continúa con</span>
      </div>

      <!-- Botón de Google elegante estilo Apple HIG -->
      <button type="button" class="btn-google btn-block" @click="showGoogleSelector = true" :disabled="loading">
        <svg class="google-icon" viewBox="0 0 24 24" fill="currentColor">
          <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
          <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
          <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" fill="#FBBC05"/>
          <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
        </svg>
        <span>Iniciar sesión con Google</span>
      </button>

      <div class="auth-footer">
        <p>¿No tienes cuenta? <router-link to="/register" class="auth-link">Regístrate gratis</router-link></p>
      </div>
    </div>

    <!-- Modal de Selección de Cuentas de Google (Simulada e Integrada al Backend) -->
    <div v-if="showGoogleSelector" class="google-modal-overlay" @click.self="showGoogleSelector = false">
      <div class="google-modal-card glass-card">
        <div v-if="googleError" class="error-msg" style="margin-bottom: 14px; background: rgba(255, 69, 58, 0.08); border-color: rgba(255, 69, 58, 0.2); color: var(--color-danger); padding: 10px; border-radius: var(--radius-sm); font-size: 13.5px;">
          {{ googleError }}
        </div>
        <div class="google-modal-header">
          <svg class="google-modal-logo" viewBox="0 0 24 24" fill="currentColor">
            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" fill="#FBBC05"/>
            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
          </svg>
          <h3>Elige una cuenta</h3>
          <p>para continuar en Ábaco Finanzas</p>
        </div>

        <div class="google-accounts-list">
          <div class="google-account-item" @click="handleGoogleOAuth('abaco.finance@gmail.com', 'Ábaco Finance')">
            <div class="account-avatar">AF</div>
            <div class="account-details">
              <strong>Ábaco Finance</strong>
              <span>abaco.finance@gmail.com</span>
            </div>
          </div>

          <div class="google-account-item" @click="handleGoogleOAuth('demo.finanzas@gmail.com', 'Demo Finanzas')">
            <div class="account-avatar">DF</div>
            <div class="account-details">
              <strong>Demo Finanzas</strong>
              <span>demo.finanzas@gmail.com</span>
            </div>
          </div>

          <!-- Opción de personalizar o usar otra -->
          <div class="google-account-item custom-email-item" v-if="!showCustomInput" @click="showCustomInput = true">
            <div class="account-avatar-plus">+</div>
            <div class="account-details">
              <strong>Usar otra cuenta</strong>
              <span>Ingresa un correo electrónico personalizado</span>
            </div>
          </div>

          <div class="google-custom-input-form" v-else>
            <input type="text" v-model="customName" placeholder="Nombre completo" class="google-input" />
            <input type="email" v-model="customEmail" placeholder="correo@gmail.com" class="google-input" />
            <div class="google-input-actions">
              <button class="btn-google-cancel" @click="showCustomInput = false">Atrás</button>
              <button class="btn-google-submit" @click="submitCustomGoogle">Continuar</button>
            </div>
          </div>
        </div>

        <div class="google-modal-footer">
          <p>Para continuar, Google compartirá tu nombre, dirección de correo electrónico y foto de perfil con Ábaco Finanzas.</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { API_BASE } from '../config.js'

export default {
  name: 'LoginView',
  emits: ['auth-change'],
  setup(props, { emit }) {
    const email = ref('')
    const password = ref('')
    const loading = ref(false)
    const errorMessage = ref('')
    const googleError = ref('')
    const router = useRouter()

    // Control de Google Login
    const showGoogleSelector = ref(false)
    const showCustomInput = ref(false)
    const customName = ref('')
    const customEmail = ref('')

    const handleLogin = async () => {
      loading.value = true
      errorMessage.value = ''
      
      try {
        const response = await fetch(`${API_BASE}/auth.php?action=login`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            email: email.value,
            password: password.value
          })
        })

        const data = await response.json()

        if (!response.ok) {
          throw new Error(data.error || 'Error al iniciar sesión.')
        }

        // Guardar sesión
        localStorage.setItem('token', data.token)
        localStorage.setItem('user', JSON.stringify(data.user))
        
        emit('auth-change')
        router.push('/')
      } catch (err) {
        errorMessage.value = err.message
      } finally {
        loading.value = false
      }
    }

    const handleGoogleOAuth = async (googleEmail, googleName) => {
      loading.value = true
      googleError.value = ''
      errorMessage.value = ''
      
      try {
        const response = await fetch(`${API_BASE}/auth.php?action=google_login`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            email: googleEmail,
            name: googleName
          })
        })

        const data = await response.json()

        if (!response.ok) {
          throw new Error(data.error || 'Error al autenticar con Google.')
        }

        localStorage.setItem('token', data.token)
        localStorage.setItem('user', JSON.stringify(data.user))
        
        showGoogleSelector.value = false
        emit('auth-change')
        router.push('/')
      } catch (err) {
        googleError.value = err.message
        errorMessage.value = err.message
      } finally {
        loading.value = false
      }
    }

    const submitCustomGoogle = () => {
      if (!customName.value || !customEmail.value) {
        alert('Por favor, ingresa tu nombre y correo.')
        return
      }
      handleGoogleOAuth(customEmail.value, customName.value)
    }

    return {
      email,
      password,
      loading,
      errorMessage,
      googleError,
      showGoogleSelector,
      showCustomInput,
      customName,
      customEmail,
      handleLogin,
      handleGoogleOAuth,
      submitCustomGoogle
    }
  }
}
</script>

<style scoped>
.login-container {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 80vh;
}

.auth-card {
  width: 100%;
  max-width: 420px;
  padding: 40px 30px;
  border-radius: var(--radius-lg);
  animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
}

.auth-header {
  text-align: center;
  margin-bottom: 30px;
}

.logo-brand-container {
  display: flex;
  justify-content: center;
  align-items: center;
  margin-bottom: 16px;
  height: 50px;
}

.logo-img {
  max-height: 48px;
  width: auto;
}

.logo-light {
  display: none;
}

:global(body.light-theme) .logo-dark {
  display: none;
}

:global(body.light-theme) .logo-light {
  display: block;
}

.auth-subtitle {
  color: var(--text-secondary);
  font-size: 14px;
  margin-top: 4px;
}

.auth-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.btn-block {
  width: 100%;
  padding: 14px;
  margin-top: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
}

.auth-divider {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 24px 0 14px 0;
}

.auth-divider::before,
.auth-divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--card-border);
}

.auth-divider span {
  font-size: 12.5px;
  color: var(--text-muted);
}

/* Botón de Google */
.btn-google {
  background: var(--card-bg);
  border: 1px solid var(--card-border);
  color: var(--text-primary);
  border-radius: var(--radius-md);
  font-weight: 500;
  cursor: pointer;
  transition: var(--transition-smooth);
}

.btn-google:hover {
  background: rgba(255, 255, 255, 0.05);
  border-color: var(--text-secondary);
}

:global(body.light-theme) .btn-google:hover {
  background: rgba(0, 0, 0, 0.02);
}

.google-icon {
  width: 18px;
  height: 18px;
}

.error-msg {
  color: var(--color-danger);
  background: rgba(239, 68, 68, 0.08);
  padding: 10px 14px;
  border-radius: var(--radius-sm);
  border: 1px solid rgba(239, 68, 68, 0.2);
  font-size: 14px;
  text-align: center;
}

.auth-footer {
  text-align: center;
  margin-top: 24px;
  font-size: 14px;
  color: var(--text-secondary);
}

.auth-link {
  color: var(--color-primary);
  text-decoration: none;
  font-weight: 600;
  transition: var(--transition-smooth);
}

.auth-link:hover {
  color: var(--color-accent);
  text-decoration: underline;
}

/* Modal de Selección de Google (Diseño Apple HIG) */
.google-modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(15px);
  -webkit-backdrop-filter: blur(15px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
  padding: 20px;
}

.google-modal-card {
  width: 100%;
  max-width: 380px;
  background: rgba(30, 30, 32, 0.85);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: var(--radius-lg);
  padding: 30px 24px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
  animation: slideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}

:global(body.light-theme) .google-modal-overlay {
  background: rgba(255, 255, 255, 0.4);
}

:global(body.light-theme) .google-modal-card {
  background: rgba(255, 255, 255, 0.98);
  border: 1px solid rgba(0, 0, 0, 0.08);
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
}

.google-modal-header {
  text-align: center;
  margin-bottom: 24px;
}

.google-modal-logo {
  width: 32px;
  height: 32px;
  margin-bottom: 12px;
}

.google-modal-header h3 {
  font-size: 19px;
  font-weight: 600;
  color: var(--text-primary);
}

.google-modal-header p {
  font-size: 13.5px;
  color: var(--text-secondary);
  margin-top: 4px;
}

.google-accounts-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 24px;
}

.google-account-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px;
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(255, 255, 255, 0.05);
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: var(--transition-smooth);
}

.google-account-item:hover {
  background: rgba(255, 255, 255, 0.08);
  border-color: rgba(255, 255, 255, 0.15);
}

:global(body.light-theme) .google-account-item {
  background: #ffffff;
  border: 1px solid rgba(0, 0, 0, 0.06);
}

:global(body.light-theme) .google-account-item:hover {
  background: rgba(0, 0, 0, 0.02);
  border-color: rgba(0, 0, 0, 0.15);
}

.account-avatar {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: var(--color-primary);
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 14px;
}

.account-avatar-plus {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.05);
  color: var(--text-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 400;
  font-size: 20px;
  border: 1px dashed var(--card-border);
}

:global(body.light-theme) .account-avatar-plus {
  background: #ffffff;
  color: #000000;
  border-color: rgba(0, 0, 0, 0.15);
}

.account-details {
  display: flex;
  flex-direction: column;
  text-align: left;
}

.account-details strong {
  font-size: 14.5px;
  color: var(--text-primary);
  font-weight: 500;
}

.account-details span {
  font-size: 12px;
  color: var(--text-secondary);
}

.google-custom-input-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 10px 0;
}

.google-input {
  width: 100%;
  padding: 10px 12px;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid var(--card-border);
  border-radius: var(--radius-sm);
  color: var(--text-primary);
  font-size: 13.5px;
}

:global(body.light-theme) .google-input {
  background: #f2f2f7;
  border-color: rgba(0, 0, 0, 0.1);
  color: #000000;
}

:global(body.light-theme) .google-input:focus {
  background: #ffffff;
  border-color: var(--color-primary);
}

:global(body.light-theme) .google-modal-footer p {
  color: rgba(0, 0, 0, 0.4);
}

.google-input-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 6px;
}

.btn-google-cancel {
  background: transparent;
  border: none;
  color: var(--text-secondary);
  font-size: 13.5px;
  cursor: pointer;
  padding: 6px 12px;
}

.btn-google-submit {
  background: var(--color-primary);
  color: #ffffff;
  border: none;
  border-radius: var(--radius-sm);
  padding: 6px 16px;
  font-size: 13.5px;
  cursor: pointer;
  font-weight: 500;
}

.google-modal-footer {
  font-size: 11px;
  color: var(--text-muted);
  line-height: 1.4;
  margin-top: 14px;
  text-align: center;
}

@keyframes slideUp {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>
