<template>
  <div class="chat-container">
    <div class="view-header chat-header-top">
      <div>
        <h1 class="view-title text-gradient-purple">Asesor Financiero IA</h1>
        <p class="view-subtitle">Tu mentor financiero personal y tutor interactivo de Ábaco</p>
      </div>
      <button class="btn-clear-history" @click="clearHistory" title="Limpiar historial de conversación">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="btn-icon">
          <polyline points="3 6 5 6 21 6"></polyline>
          <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
        </svg>
        <span>Limpiar Chat</span>
      </button>
    </div>

    <!-- Banner de API Key (Informativo e interactivo) -->
    <div class="ai-key-banner glass-card" :class="{ 'has-key': hasCustomKey }">
      <div class="banner-content">
        <svg v-if="hasCustomKey" class="banner-icon text-success" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
          <polyline points="22 4 12 14.01 9 11.01"></polyline>
        </svg>
        <svg v-else class="banner-icon text-warning" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"></circle>
          <line x1="12" y1="16" x2="12" y2="12"></line>
          <line x1="12" y1="8" x2="12.01" y2="8"></line>
        </svg>
        <span v-if="hasCustomKey">
          🔐 Conectado a tu cuenta de **Google Gemini** (API Key Personal).
        </span>
        <span v-else>
          💡 Usando IA compartida del servidor. Puedes vincular tu API Key de Gemini en <router-link to="/settings" class="banner-link">Ajustes</router-link>.
        </span>
      </div>
    </div>

    <!-- Contenedor del Chat -->
    <div class="glass-card chat-card">
      <div class="messages-container" ref="messagesBox">
        <!-- Mensaje de bienvenida del sistema -->
        <div class="message assistant">
          <div class="message-avatar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
              <polyline points="2 17 12 22 22 17"></polyline>
              <polyline points="2 12 12 17 22 12"></polyline>
            </svg>
          </div>
          <div class="message-bubble">
            <p>¡Hola, {{ userName }}! Soy tu asesor financiero y mentor en Ábaco. Mantengo el contexto de nuestra conversación para ayudarte a ahorrar, administrar tus préstamos y aplicar las mejores leyes de riqueza. ¿En qué trabajamos hoy?</p>
            <div class="suggestions-list">
              <button class="btn-suggestion" @click="sendPredefined('¿Cómo funciona el módulo de préstamos cuando le presto dinero a alguien?')">
                🤝 Préstamos a personas
              </button>
              <button class="btn-suggestion" @click="sendPredefined('Dame consejos de ahorro basados en El Hombre Más Rico de Babilonia.')">
                📖 Regla del 10% (Babilonia)
              </button>
              <button class="btn-suggestion" @click="sendPredefined('¿Cómo puedo aumentar mis ingresos siguiendo la regla 10X de Grant Cardone?')">
                🚀 Estrategia 10X (Cardone)
              </button>
              <button class="btn-suggestion" @click="sendPredefined('Dame un tutorial completo de cómo usar Ábaco.')">
                📘 Tutorial de Ábaco
              </button>
            </div>
          </div>
        </div>

        <!-- Mensajes del chat históricos -->
        <div v-for="(msg, index) in messages" :key="index" :class="['message', msg.role]">
          <div class="message-avatar" v-if="msg.role === 'assistant'">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
              <polyline points="2 17 12 22 22 17"></polyline>
              <polyline points="2 12 12 17 22 12"></polyline>
            </svg>
          </div>
          <div class="message-bubble">
            <p class="formatted-text">{{ msg.text }}</p>
          </div>
        </div>

        <!-- Indicador de que la IA está respondiendo -->
        <div class="message assistant" v-if="loading">
          <div class="message-avatar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
            </svg>
          </div>
          <div class="message-bubble loading-bubble">
            <div class="typing-dots">
              <span></span>
              <span></span>
              <span></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Barra de entrada de texto -->
      <form @submit.prevent="sendMessage" class="chat-input-bar">
        <input type="text" v-model="userInput" placeholder="Escribe tu consulta o pide un consejo..." :disabled="loading" required />
        <button type="submit" class="btn-send btn-primary" :disabled="loading">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="22" y1="2" x2="11" y2="13"></line>
            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
          </svg>
        </button>
      </form>
    </div>
  </div>
</template>

<script>
import { ref, onMounted, nextTick } from 'vue'
import { API_BASE } from '../config.js'

export default {
  name: 'AIChatView',
  setup() {
    const userName = ref('Usuario')
    const hasCustomKey = ref(!!localStorage.getItem('gemini_api_key'))
    const userInput = ref('')
    const messages = ref([])
    const loading = ref(false)
    const messagesBox = ref(null)

    const loadHistory = () => {
      const saved = localStorage.getItem('abaco_ai_chat_history')
      if (saved) {
        try {
          messages.value = JSON.parse(saved)
        } catch (e) {
          messages.value = []
        }
      }
    }

    const saveHistory = () => {
      localStorage.setItem('abaco_ai_chat_history', JSON.stringify(messages.value))
    }

    const clearHistory = () => {
      if (confirm('¿Deseas borrar el historial de conversación con el Asesor IA?')) {
        messages.value = []
        localStorage.removeItem('abaco_ai_chat_history')
      }
    }

    const checkUserName = () => {
      const stored = localStorage.getItem('user')
      if (stored) {
        const u = JSON.parse(stored)
        userName.value = u.name || 'Usuario'
      }
    }

    const scrollToBottom = async () => {
      await nextTick()
      if (messagesBox.value) {
        messagesBox.value.scrollTop = messagesBox.value.scrollHeight
      }
    }

    const sendMessage = async () => {
      const text = userInput.value.trim()
      if (!text) return

      // Agregar mensaje del usuario a la lista
      messages.value.push({ role: 'user', text, sender: 'user' })
      saveHistory()
      userInput.value = ''
      loading.value = true
      scrollToBottom()

      const token = localStorage.getItem('token')

      try {
        const customApiKey = localStorage.getItem('gemini_api_key') || ''
        const headers = {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        }
        if (customApiKey) {
          headers['X-Gemini-API-Key'] = customApiKey
        }

        // Obtener los últimos 8 mensajes para mantener contexto de la conversación sin exceder tokens
        const historyToSend = messages.value.slice(-9, -1).map(m => ({
          sender: m.role === 'user' ? 'user' : 'assistant',
          text: m.text
        }))

        const response = await fetch(`${API_BASE}/ai.php?action=get_advice`, {
          method: 'POST',
          headers,
          body: JSON.stringify({ 
            message: text,
            history: historyToSend
          })
        })

        const rawText = await response.text()
        let data = {}
        try {
          data = JSON.parse(rawText)
        } catch (e) {
          throw new Error('Respuesta no válida del servidor. Por favor reintenta.')
        }

        if (!response.ok) {
          throw new Error(data.error || 'Error al conectar con el Asesor IA.')
        }

        // Agregar respuesta del asistente
        messages.value.push({ role: 'assistant', text: data.response || 'No se recibió texto de respuesta.', sender: 'assistant' })
        saveHistory()
      } catch (err) {
        messages.value.push({ role: 'assistant', text: `Lo siento, ocurrió un error: ${err.message}`, sender: 'assistant' })
        saveHistory()
      } finally {
        loading.value = false
        scrollToBottom()
      }
    }

    const sendPredefined = (text) => {
      userInput.value = text
      sendMessage()
    }

    onMounted(() => {
      checkUserName()
      loadHistory()
      scrollToBottom()
    })

    return {
      userName,
      userInput,
      messages,
      loading,
      messagesBox,
      sendMessage,
      sendPredefined,
      scrollToBottom,
      hasCustomKey,
      clearHistory
    }
  }
}
</script>

<style scoped>
.ai-key-banner {
  padding: 12px 18px;
  margin-bottom: 16px;
  border-radius: var(--radius-sm);
  background: rgba(255, 159, 10, 0.04);
  border: 1px dashed rgba(255, 159, 10, 0.2);
  transition: var(--transition-smooth);
}
.ai-key-banner.has-key {
  background: rgba(48, 209, 88, 0.04);
  border: 1px solid rgba(48, 209, 88, 0.2);
}
.banner-content {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 13.5px;
  color: var(--text-secondary);
}
.banner-icon {
  width: 20px;
  height: 20px;
  flex-shrink: 0;
}
.text-warning {
  color: var(--color-warning);
}
.text-success {
  color: var(--color-success);
}
.banner-link {
  color: var(--color-primary);
  text-decoration: underline;
  font-weight: 500;
}

.chat-header-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.btn-clear-history {
  display: flex;
  align-items: center;
  gap: 6px;
  background: rgba(239, 68, 68, 0.1);
  border: 1px solid rgba(239, 68, 68, 0.25);
  color: #ef4444;
  padding: 6px 12px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: var(--transition-smooth);
}
.btn-clear-history:hover {
  background: rgba(239, 68, 68, 0.2);
}
.btn-clear-history svg {
  width: 14px;
  height: 14px;
}

.chat-container {
  display: flex;
  flex-direction: column;
  height: calc(100vh - 120px); /* Ajustar espacio para barra navegación */
  gap: 16px;
  animation: fadeIn 0.4s ease-out;
}

@media (min-width: 769px) {
  .chat-container {
    height: calc(100vh - 48px);
  }
}

.chat-card {
  display: flex;
  flex-direction: column;
  flex: 1;
  padding: 0;
  overflow: hidden;
  height: 100%;
}

.messages-container {
  flex: 1;
  padding: 16px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.message {
  display: flex;
  gap: 10px;
  max-width: 95%;
  width: auto;
  align-self: flex-start;
  animation: messageSlideIn 0.3s ease-out;
}

@media (min-width: 769px) {
  .message {
    max-width: 88%;
  }
}

.message.user {
  align-self: flex-end;
  flex-direction: row-reverse;
}

.message-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: rgba(10, 132, 255, 0.12);
  border: 1px solid rgba(10, 132, 255, 0.25);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-primary);
  flex-shrink: 0;
}

.message.user .message-avatar {
  background: rgba(100, 210, 255, 0.12);
  border-color: rgba(100, 210, 255, 0.25);
  color: var(--color-secondary);
}

.message-avatar svg {
  width: 18px;
  height: 18px;
}

.message-bubble {
  background: var(--bg-tertiary);
  padding: 14px 18px;
  border-radius: 0px var(--radius-md) var(--radius-md) var(--radius-md);
  color: var(--text-primary);
  font-size: 15px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.message.user .message-bubble {
  background: var(--color-primary); /* Azul de iMessage */
  border-radius: var(--radius-md) 0px var(--radius-md) var(--radius-md);
  box-shadow: 0 4px 12px rgba(10, 132, 255, 0.15);
}

.formatted-text {
  white-space: pre-wrap;
  line-height: 1.6;
}

/* Sugerencias de chat */
.suggestions-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 4px;
}

.btn-suggestion {
  background: var(--bg-primary);
  border: 1px solid var(--card-border);
  color: var(--text-secondary);
  border-radius: 9999px;
  padding: 6px 14px;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  transition: var(--transition-smooth);
  font-family: var(--font-main);
}

.btn-suggestion:hover {
  background: rgba(10, 132, 255, 0.12);
  color: var(--color-primary);
  border-color: rgba(10, 132, 255, 0.25);
}

/* Barra de Entrada */
.chat-input-bar {
  display: flex;
  gap: 12px;
  padding: 16px;
  border-top: 1px solid var(--card-border);
  background: var(--bg-secondary);
  align-items: center;
}

.chat-input-bar input {
  flex: 1;
  border-radius: 9999px;
  padding: 12px 24px;
}

.btn-send {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  flex-shrink: 0;
}

.btn-send svg {
  width: 20px;
  height: 20px;
}

/* Animación de tres puntos suspensivos */
.typing-dots {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 4px 8px;
}

.typing-dots span {
  width: 8px;
  height: 8px;
  background-color: var(--text-secondary);
  border-radius: 50%;
  animation: typingDot 1.4s infinite both;
}

.typing-dots span:nth-child(2) {
  animation-delay: .2s;
}

.typing-dots span:nth-child(3) {
  animation-delay: .4s;
}

@keyframes typingDot {
  0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
  40% { transform: scale(1); opacity: 1; }
}

@keyframes messageSlideIn {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
</style>
