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

      <div v-if="errorMessage" class="error-msg" style="margin-bottom: 16px; text-align: center;">
        {{ errorMessage }}
      </div>

      <!-- Contenedor Oficial del Botón de Google -->
      <div class="google-btn-wrapper" style="display:flex; justify-content:center; margin: 24px 0 16px 0;">
        <div id="google-btn-container"></div>
      </div>

      <div class="auth-footer" style="margin-bottom: 24px;">
        <p>¿Ya tienes cuenta? <router-link to="/login" class="auth-link">Inicia sesión</router-link></p>
      </div>

      <!-- Instrucciones de Instalación PWA (Apple HIG) -->
      <div class="pwa-install-guide">
        <h4 class="guide-title">
          <i class="fa-solid fa-mobile-screen-button"></i> Instala Ábaco en tu celular
        </h4>
        <div class="guide-tabs">
          <button :class="['tab-btn', activeTab === 'ios' ? 'active' : '']" @click="activeTab = 'ios'">
            <i class="fa-brands fa-apple"></i> iPhone (iOS)
          </button>
          <button :class="['tab-btn', activeTab === 'android' ? 'active' : '']" @click="activeTab = 'android'">
            <i class="fa-brands fa-android"></i> Android
          </button>
        </div>
        <div class="guide-content">
          <div v-if="activeTab === 'ios'" class="guide-step-list">
            <div class="step-item">
              <span class="step-num">1</span>
              <p>Abre <strong>Safari</strong> y entra a <code>abaco.briela.app</code></p>
            </div>
            <div class="step-item">
              <span class="step-num">2</span>
              <p>Toca el botón <strong>Compartir</strong> <i class="fa-solid fa-arrow-up-from-bracket" style="color: #007aff;"></i> en la parte inferior</p>
            </div>
            <div class="step-item">
              <span class="step-num">3</span>
              <p>Selecciona la opción <strong>Añadir a pantalla de inicio</strong> <i class="fa-regular fa-square-plus"></i></p>
            </div>
          </div>
          <div v-else class="guide-step-list">
            <div class="step-item">
              <span class="step-num">1</span>
              <p>Abre <strong>Chrome</strong> y entra a <code>abaco.briela.app</code></p>
            </div>
            <div class="step-item">
              <span class="step-num">2</span>
              <p>Toca el menú de <strong>tres puntos</strong> <i class="fa-solid fa-ellipsis-vertical"></i> arriba a la derecha</p>
            </div>
            <div class="step-item">
              <span class="step-num">3</span>
              <p>Selecciona <strong>Instalar aplicación</strong> o <strong>Añadir a pantalla de inicio</strong></p>
            </div>
          </div>
        </div>
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
    const activeTab = ref('ios')
    const loading = ref(false)
    const errorMessage = ref('')
    const router = useRouter()

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

        const responseText = await res.text()
        let data
        try {
          data = JSON.parse(responseText)
        } catch (e) {
          throw new Error('Servidor error (no JSON): ' + responseText.substring(0, 150))
        }

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
      activeTab,
      loading,
      errorMessage,
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

.pwa-install-guide {
  margin-top: 24px;
  padding-top: 20px;
  border-top: 1px solid var(--card-border);
  text-align: left;
}

.guide-title {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--text-primary);
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.guide-tabs {
  display: flex;
  gap: 4px;
  margin-bottom: 16px;
  background: rgba(255, 255, 255, 0.04);
  padding: 4px;
  border-radius: 8px;
}
body.light-theme .guide-tabs {
  background: rgba(0, 0, 0, 0.03);
}

.tab-btn {
  flex: 1;
  background: none;
  border: none;
  padding: 6px 12px;
  font-size: 11.5px;
  font-weight: 500;
  color: var(--text-secondary);
  border-radius: 6px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition: all 0.2s ease;
}

.tab-btn.active {
  background: rgba(255, 255, 255, 0.1);
  color: var(--text-primary);
  font-weight: 600;
}
body.light-theme .tab-btn.active {
  background: #fff;
  color: var(--text-primary);
  box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.guide-step-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.step-item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}

.step-num {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: rgba(10, 132, 255, 0.15);
  color: #0a84ff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10.5px;
  font-weight: 700;
  flex-shrink: 0;
  margin-top: 1px;
}
body.light-theme .step-num {
  background: rgba(0, 122, 255, 0.1);
  color: #007aff;
}

.step-item p {
  margin: 0;
  font-size: 12.5px;
  color: var(--text-secondary);
  line-height: 1.4;
}

.step-item p strong {
  color: var(--text-primary);
}

.step-item p code {
  background: rgba(255, 255, 255, 0.05);
  padding: 2px 4px;
  border-radius: 4px;
  font-family: monospace;
}
body.light-theme .step-item p code {
  background: rgba(0, 0, 0, 0.04);
}
</style>
