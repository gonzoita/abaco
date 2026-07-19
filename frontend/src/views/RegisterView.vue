<template>
  <div class="register-container">
    <div class="glass-card auth-card">
      <div class="auth-header">
        <!-- Logo oficial de Ábaco en lugar de icono SVG -->
        <div class="logo-brand-container" style="display:flex; justify-content:center; align-items:center; margin-bottom:16px;">
          <img src="../assets/logo-white.png" class="logo-img logo-dark" alt="Ábaco" style="max-height:48px;" />
          <img src="../assets/logo-black.png" class="logo-img logo-light" alt="Ábaco" style="max-height:48px;" />
        </div>
        <p class="auth-subtitle" style="margin-top: 4px; color: var(--text-secondary); font-size: 14px;">Controla tus finanzas inteligentes</p>
        <h2 class="text-gradient-purple" style="margin-top: 14px; font-size: 24px; font-weight: 800;">Crear Cuenta</h2>
      </div>

      <form @submit.prevent="handleRegister" class="auth-form">
        <div class="form-group">
          <label for="name">Nombre Completo</label>
          <input type="text" id="name" v-model="name" placeholder="Tu nombre" required />
        </div>

        <div class="form-group">
          <label for="email">Correo Electrónico</label>
          <input type="email" id="email" v-model="email" placeholder="ejemplo@correo.com" required />
        </div>

        <div class="form-group">
          <label for="password">Contraseña</label>
          <input type="password" id="password" v-model="password" placeholder="Mínimo 6 caracteres" required minlength="6" />
        </div>

        <div class="form-group">
          <label for="currency">Moneda Principal</label>
          <select id="currency" v-model="currency">
            <option value="COP">Peso Colombiano (COP)</option>
            <option value="USD">Dólar Estadounidense (USD)</option>
            <option value="MXN">Peso Mexicano (MXN)</option>
            <option value="EUR">Euro (EUR)</option>
            <option value="ARS">Peso Argentino (ARS)</option>
            <option value="CLP">Peso Chileno (CLP)</option>
          </select>
        </div>

        <div v-if="errorMessage" class="error-msg">
          {{ errorMessage }}
        </div>
        <div v-if="successMessage" class="success-msg">
          {{ successMessage }}
        </div>

        <button type="submit" class="btn-primary btn-block" :disabled="loading">
          <span v-if="loading">Creando Cuenta...</span>
          <span v-else>Registrarse</span>
        </button>
      </form>

      <!-- Divisor para registro con Google -->
      <div class="auth-divider">
        <span>o regístrate con</span>
      </div>

      <!-- Contenedor Oficial del Botón de Google -->
      <div class="google-btn-wrapper" style="display:flex; justify-content:center; margin: 16px 0;">
        <div id="google-btn-container"></div>
      </div>

      <div class="auth-footer">
        <p>¿Ya tienes cuenta? <router-link to="/login" class="auth-link">Inicia sesión</router-link></p>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { API_BASE, GOOGLE_CLIENT_ID } from '../config.js'

export default {
  name: 'RegisterView',
  emits: ['auth-change'],
  setup(props, { emit }) {
    const name = ref('')
    const email = ref('')
    const password = ref('')
    const currency = ref('COP')
    const loading = ref(false)
    const errorMessage = ref('')
    const successMessage = ref('')
    const router = useRouter()

    const handleRegister = async () => {
      loading.value = true
      errorMessage.value = ''
      successMessage.value = ''

      try {
        const response = await fetch(`${API_BASE}/auth.php?action=register`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            name: name.value,
            email: email.value,
            password: password.value,
            currency: currency.value
          })
        })

        const data = await response.json()

        if (!response.ok) {
          throw new Error(data.error || 'Error al registrar el usuario.')
        }

        successMessage.value = '¡Registro completado! Hemos enviado un enlace de activación a tu correo electrónico. Por favor revisa tu bandeja de entrada para activar tu cuenta antes de iniciar sesión.'
        // Limpiar campos para evitar doble envío
        name.value = ''
        email.value = ''
        password.value = ''
      } catch (err) {
        errorMessage.value = err.message
      } finally {
        loading.value = false
      }
    }

    const handleGoogleCredentialResponse = async (response) => {
      loading.value = true
      errorMessage.value = ''
      successMessage.value = ''
      
      try {
        const res = await fetch(`${API_BASE}/auth.php?action=google_login`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            token: response.credential
          })
        })

        const data = await res.json()

        if (!res.ok) {
          throw new Error(data.error || 'Error al registrar con Google.')
        }

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

    onMounted(() => {
      const checkGoogleSDK = setInterval(() => {
        if (window.google) {
          clearInterval(checkGoogleSDK)
          window.google.accounts.id.initialize({
            client_id: GOOGLE_CLIENT_ID,
            callback: handleGoogleCredentialResponse
          })

          window.google.accounts.id.renderButton(
            document.getElementById("google-btn-container"),
            { 
              theme: document.body.classList.contains('light-theme') ? "outline" : "filled_black", 
              size: "large", 
              width: 360,
              text: "signup_with"
            }
          )
        }
      }, 100)
    })

    return {
      name,
      email,
      password,
      currency,
      loading,
      errorMessage,
      successMessage,
      handleRegister,
      handleGoogleCredentialResponse
    }
  }
}
</script>

<style scoped>
.register-container {
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
  margin-bottom: 24px;
}

.logo-container {
  display: inline-flex;
  padding: 16px;
  background: rgba(139, 92, 246, 0.15);
  border-radius: 50%;
  margin-bottom: 12px;
  border: 1px solid rgba(139, 92, 246, 0.3);
}

.logo-icon {
  width: 32px;
  height: 32px;
  color: var(--color-primary);
}

.auth-header h2 {
  font-size: 24px;
  font-weight: 800;
}

.auth-header p {
  color: var(--text-secondary);
  font-size: 14px;
  margin-top: 4px;
}

.auth-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.btn-block {
  width: 100%;
  padding: 14px;
  margin-top: 10px;
}

.error-msg {
  color: var(--color-danger);
  background: rgba(239, 68, 68, 0.1);
  padding: 10px 14px;
  border-radius: var(--radius-sm);
  border: 1px solid rgba(239, 68, 68, 0.2);
  font-size: 14px;
  text-align: center;
}

.success-msg {
  color: var(--color-success);
  background: rgba(16, 185, 129, 0.1);
  padding: 10px 14px;
  border-radius: var(--radius-sm);
  border: 1px solid rgba(16, 185, 129, 0.2);
  font-size: 14px;
  text-align: center;
}

.auth-footer {
  text-align: center;
  margin-top: 20px;
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
