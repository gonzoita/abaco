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

      <div v-if="googleError" class="error-msg" style="margin-bottom: 16px; text-align: center;">
        {{ googleError }}
      </div>

      <!-- Contenedor Oficial del Botón de Google -->
      <div class="google-btn-wrapper" style="display:flex; justify-content:center; margin: 24px 0 16px 0;">
        <div id="google-btn-container"></div>
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
  name: 'LoginView',
  emits: ['auth-change'],
  setup(props, { emit }) {
    const activeTab = ref('ios')
    const loading = ref(false)
    const googleError = ref('')
    const router = useRouter()

    const handleGoogleCredentialResponse = async (response) => {
      loading.value = true
      googleError.value = ''
      
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
          throw new Error(data.error || 'Error al autenticar con Google.')
        }

        localStorage.setItem('token', data.token)
        localStorage.setItem('user', JSON.stringify(data.user))
        
        emit('auth-change')
        router.push('/')
      } catch (err) {
        googleError.value = err.message
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
              text: "signin_with"
            }
          )
        }
      }, 100)
    })

    return {
      activeTab,
      loading,
      googleError,
      handleGoogleCredentialResponse
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
