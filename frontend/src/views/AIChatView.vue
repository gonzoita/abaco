<template>
  <div class="chat-container">
    <div class="view-header chat-header-top">
      <div>
        <h1 class="view-title text-gradient-purple">Asesor Financiero IA</h1>
        <p class="view-subtitle">Tu mentor financiero personal y tutor interactivo de Ábaco</p>
      </div>
      <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
        <button class="btn-diagnosis-header" @click="openDiagnosisModal" title="Generar informe completo de 5 fases">
          <i class="fa-solid fa-stethoscope" style="color:#38bdf8;"></i>
          <span>Diagnóstico 360°</span>
        </button>
        <button class="btn-clear-history" @click="clearHistory" title="Limpiar historial de conversación">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="btn-icon">
            <polyline points="3 6 5 6 21 6"></polyline>
            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
          </svg>
          <span>Limpiar</span>
        </button>
      </div>
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
    <div class="chat-card">
      <div class="messages-container" ref="messagesBox">
        <!-- Mensaje de bienvenida del sistema -->
        <div class="message assistant">
          <div class="message-avatar">
            <i class="fa-solid fa-brain" style="font-size:16px;"></i>
          </div>
          <div class="message-bubble">
            <div class="message-sender-title">Asesor Ábaco IA</div>
            <div class="formatted-text">
              ¡Hola, {{ userName }}! Soy tu mentor financiero en Ábaco. Puedo ayudarte a ahorrar, optimizar tus costos, gestionar tus préstamos o guiarte en el uso de la app. ¿En qué trabajamos hoy?
            </div>
            <div class="suggestions-list">
              <button class="btn-suggestion" @click="openDiagnosisModal" style="background:rgba(56,189,248,0.15); color:#38bdf8; border-color:rgba(56,189,248,0.3);">
                🩺 Diagnóstico Financiero 360°
              </button>
              <button class="btn-suggestion" @click="sendPredefined('¿Cómo funciona el módulo de préstamos cuando le presto dinero a alguien?')">
                🤝 Préstamos a personas
              </button>
              <button class="btn-suggestion" @click="sendPredefined('Dame consejos de ahorro basados en El Hombre Más Rico de Babilonia.')">
                📖 Regla del 10% (Babilonia)
              </button>
              <button class="btn-suggestion" @click="sendPredefined('¿Cómo puedo aumentar mis ingresos en mi negocio?')">
                🚀 Estrategia de Ventas
              </button>
            </div>
          </div>
        </div>

        <!-- Mensajes del chat históricos -->
        <div v-for="(msg, index) in messages" :key="index" :class="['message', msg.role]">
          <div class="message-avatar" v-if="msg.role === 'assistant'">
            <i class="fa-solid fa-brain" style="font-size:16px;"></i>
          </div>
          <div class="message-bubble">
            <div v-if="msg.role === 'assistant'" class="message-sender-title">Asesor Ábaco IA</div>
            <div class="formatted-text" v-html="renderFormattedText(msg.text)"></div>
            <button 
              v-if="msg.role === 'assistant' && (msg.isDiagnosis || msg.text.includes('Diagnóstico general'))" 
              @click="printDiagnosisPdf(msg.text)" 
              class="btn-primary" 
              style="margin-top:12px; height:34px; font-size:12px; border-radius:8px; display:flex; align-items:center; gap:6px; background:linear-gradient(135deg, #6366f1, #a855f7);"
            >
              <i class="fa-solid fa-file-pdf"></i> Descargar Diagnóstico en PDF
            </button>
          </div>
        </div>

        <!-- Indicador de que la IA está respondiendo -->
        <div class="message assistant" v-if="loading">
          <div class="message-avatar">
            <i class="fa-solid fa-brain" style="font-size:16px;"></i>
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

      <!-- Barra de entrada de texto flotante estilo WhatsApp/Telegram -->
      <form @submit.prevent="sendMessage" class="chat-input-bar">
        <input 
          type="text" 
          v-model="userInput" 
          placeholder="Escribe un mensaje..." 
          :disabled="loading" 
          required 
          class="chat-pill-input"
        />
        <button type="submit" class="btn-send-pill" :disabled="loading">
          <i class="fa-solid fa-paper-plane"></i>
        </button>
      </form>
    </div>

    <!-- MODAL DIAGNÓSTICO FINANCIERO 360° -->
    <div v-if="showDiagnosisModal" class="modal-overlay" @click.self="showDiagnosisModal = false" style="position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; z-index:9999; padding:16px;">
      <div class="glass-card modal-content" style="max-width:540px; width:100%; padding:22px; border-radius:16px; background:var(--card-bg); border:1px solid var(--card-border); box-shadow:0 20px 50px rgba(0,0,0,0.5);">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--card-border); padding-bottom:12px; margin-bottom:14px;">
          <h3 style="font-size:17px; font-weight:700; color:var(--text-primary); margin:0; display:flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-stethoscope" style="color:#38bdf8;"></i> Diagnóstico Financiero 360°
          </h3>
          <button class="btn-close" @click="showDiagnosisModal = false" style="background:none; border:none; color:var(--text-secondary); font-size:22px; cursor:pointer;">&times;</button>
        </div>

        <p style="font-size:13px; color:var(--text-secondary); line-height:1.5; margin-bottom:14px;">
          La IA recopilará tus <strong>saldos de cuentas, movimientos del mes, deudas y presupuestos reales</strong> guardados en Ábaco para realizar un análisis estructurado de 5 fases.
        </p>

        <div class="form-group" style="margin-bottom:16px;">
          <label style="display:block; font-size:12px; font-weight:700; color:var(--text-primary); margin-bottom:6px;">
            Situación Actual / Metas (Opcional - 2 a 4 líneas):
          </label>
          <textarea 
            v-model="diagnosisNotes" 
            placeholder="Ej: Mis ingresos son $3.5M, quiero pagar una tarjeta de $1.2M y empezar a ahorrar para vacaciones..." 
            rows="3" 
            style="width:100%; border-radius:8px; border:1px solid var(--card-border); background:rgba(0,0,0,0.2); color:var(--text-primary); padding:10px; font-size:13px; outline:none; resize:none;"
          ></textarea>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px;">
          <button type="button" class="btn-secondary" @click="showDiagnosisModal = false" style="height:40px; padding:0 16px; border-radius:8px;">Cancelar</button>
          <button type="button" class="btn-primary" @click="runDiagnosis" :disabled="loading" style="height:40px; padding:0 20px; border-radius:8px; display:flex; align-items:center; gap:8px; background:linear-gradient(135deg, #0ea5e9, #6366f1);">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Generar Diagnóstico IA
          </button>
        </div>
      </div>
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

    const renderFormattedText = (raw) => {
      if (!raw) return ''
      let text = raw.replace(/</g, '&lt;').replace(/>/g, '&gt;')
      text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
      text = text.replace(/^\s*[-*]\s+(.+)$/gm, '<li style="margin-left:14px; list-style-type:disc;">$1</li>')
      text = text.replace(/\n/g, '<br>')
      return text
    }

    const showDiagnosisModal = ref(false)
    const diagnosisNotes = ref('')

    const openDiagnosisModal = () => {
      showDiagnosisModal.value = true
    }

    const runDiagnosis = async () => {
      showDiagnosisModal.value = false
      loading.value = true

      messages.value.push({
        role: 'user',
        text: `🩺 **Solicitud de Diagnóstico Financiero 360°**\n${diagnosisNotes.value ? 'Notas del usuario: ' + diagnosisNotes.value : 'Análisis automático basado en mis datos guardados en Ábaco.'}`,
        sender: 'user'
      })
      saveHistory()
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

        const response = await fetch(`${API_BASE}/ai.php?action=financial_diagnosis`, {
          method: 'POST',
          headers,
          body: JSON.stringify({ user_notes: diagnosisNotes.value })
        })

        const rawText = await response.text()
        let data = {}
        try {
          data = JSON.parse(rawText)
        } catch (e) {
          throw new Error('Error al procesar el diagnóstico en el servidor.')
        }

        if (!response.ok || data.error) {
          throw new Error(data.error || 'Error al generar el diagnóstico.')
        }

        messages.value.push({
          role: 'assistant',
          text: data.diagnosis,
          sender: 'assistant',
          isDiagnosis: true
        })
        saveHistory()
        diagnosisNotes.value = ''
      } catch (err) {
        messages.value.push({
          role: 'assistant',
          text: `Error al generar diagnóstico: ${err.message}`,
          sender: 'assistant'
        })
        saveHistory()
      } finally {
        loading.value = false
        scrollToBottom()
      }
    }

    const printDiagnosisPdf = (diagnosisText) => {
      const printWindow = window.open('', '_blank')
      if (!printWindow) return

      let formattedBody = diagnosisText.replace(/</g, '&lt;').replace(/>/g, '&gt;')
      formattedBody = formattedBody.replace(/###\s*(.+)/g, '<h3 style="color:#4f46e5; border-bottom:2px solid #e2e8f0; padding-bottom:6px; margin-top:24px;">$1</h3>')
      formattedBody = formattedBody.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
      formattedBody = formattedBody.replace(/^\s*[-*]\s+(.+)$/gm, '<li style="margin-left:16px; margin-bottom:6px;">$1</li>')
      formattedBody = formattedBody.replace(/\n/g, '<br>')

      printWindow.document.write(`
        <!DOCTYPE html>
        <html lang="es">
        <head>
          <meta charset="UTF-8">
          <title>Diagnóstico Financiero Personal 360° - Ábaco</title>
          <style>
            body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #1e293b; padding: 40px; background: #fff; line-height: 1.65; }
            .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #6366f1; padding-bottom: 16px; margin-bottom: 24px; }
            .logo { font-size: 26px; font-weight: 800; color: #6366f1; text-transform: uppercase; letter-spacing: 1px; }
            .subtitle { font-size: 13px; color: #64748b; margin-top: 4px; }
            ul, ol { padding-left: 20px; }
            .footer { margin-top: 50px; border-top: 1px solid #e2e8f0; padding-top: 16px; font-size: 11px; color: #94a3b8; text-align: center; }
            @media print {
              .no-print { display: none; }
              body { padding: 0; }
            }
          </style>
        </head>
        <body>
          <div class="no-print" style="margin-bottom: 20px; text-align: right;">
            <button onclick="window.print()" style="background:#6366f1; color:#fff; border:none; padding:10px 20px; border-radius:8px; font-weight:bold; cursor:pointer;">
              🖨️ Imprimir / Guardar en PDF
            </button>
          </div>

          <div class="header">
            <div>
              <div class="logo">Ábaco Control Financiero IA</div>
              <div class="subtitle">Informe Ejecutivo de Diagnóstico Financiero Personal 360°</div>
            </div>
            <div style="text-align: right;">
              <strong style="font-size:15px;">${userName.value}</strong><br>
              <span class="subtitle">Generado el: ${new Date().toLocaleDateString('es-ES')}</span>
            </div>
          </div>

          <div class="content">
            ${formattedBody}
          </div>

          <div class="footer">
            Diagnóstico Financiero Proporcional generado automáticamente por <strong>Ábaco Asesor IA</strong> &bull; ${new Date().toLocaleString('es-ES')}
          </div>
        </body>
        </html>
      `)
      printWindow.document.close()
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
      clearHistory,
      renderFormattedText,
      showDiagnosisModal,
      diagnosisNotes,
      openDiagnosisModal,
      runDiagnosis,
      printDiagnosisPdf
    }
  }
}
</script>

<style scoped>
.ai-key-banner {
  padding: 10px 16px;
  margin-bottom: 12px;
  border-radius: 12px;
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
  gap: 10px;
  font-size: 12.5px;
  color: var(--text-secondary);
}
.banner-icon {
  width: 18px;
  height: 18px;
  flex-shrink: 0;
}

.chat-header-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.btn-diagnosis-header {
  display: flex;
  align-items: center;
  gap: 6px;
  background: rgba(56, 189, 248, 0.12);
  border: 1px solid rgba(56, 189, 248, 0.3);
  color: #38bdf8;
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 11.5px;
  font-weight: 700;
  cursor: pointer;
  transition: var(--transition-smooth);
}
.btn-diagnosis-header:hover {
  background: rgba(56, 189, 248, 0.25);
  box-shadow: 0 4px 12px rgba(56, 189, 248, 0.2);
}
.btn-clear-history {
  display: flex;
  align-items: center;
  gap: 6px;
  background: rgba(239, 68, 68, 0.1);
  border: 1px solid rgba(239, 68, 68, 0.25);
  color: #ef4444;
  padding: 6px 12px;
  border-radius: 20px;
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
  transition: var(--transition-smooth);
}

.chat-container {
  display: flex;
  flex-direction: column;
  height: calc(100vh - 110px);
  position: relative;
  animation: fadeIn 0.3s ease-out;
}

@media (min-width: 769px) {
  .chat-container {
    height: calc(100vh - 40px);
  }
}

.chat-card {
  display: flex;
  flex-direction: column;
  flex: 1;
  background: transparent !important;
  border: none !important;
  box-shadow: none !important;
  overflow: hidden;
  position: relative;
}

.messages-container {
  flex: 1;
  padding: 10px 4px 90px 4px;
  overflow-y: auto;
  overflow-x: hidden !important;
  display: flex;
  flex-direction: column;
  gap: 14px;
  width: 100%;
}

.message {
  display: flex;
  gap: 10px;
  width: 100%;
  align-self: flex-start;
  animation: messageSlideIn 0.25s ease-out;
}

.message.user {
  justify-content: flex-end;
}

.message-avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: linear-gradient(135deg, rgba(168, 85, 247, 0.2), rgba(56, 189, 248, 0.2));
  border: 1px solid rgba(168, 85, 247, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #a855f7;
  flex-shrink: 0;
  margin-top: 2px;
}

.message-sender-title {
  font-size: 10.5px;
  font-weight: 700;
  text-transform: uppercase;
  color: #a855f7;
  letter-spacing: 0.5px;
  margin-bottom: 4px;
}

.message-bubble {
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid var(--card-border);
  padding: 12px 16px;
  border-radius: 18px 18px 18px 4px;
  color: var(--text-primary);
  font-size: 14.5px;
  line-height: 1.55;
  max-width: 85%;
  word-break: break-word;
  overflow-wrap: anywhere;
  overflow-x: hidden !important;
  box-shadow: 0 4px 15px rgba(0,0,0,0.15);
}

body.light-theme .message-bubble {
  background: rgba(0, 0, 0, 0.03);
}

.message.user .message-bubble {
  background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
  color: #ffffff;
  border: none;
  border-radius: 18px 18px 4px 18px;
  box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
}

.formatted-text {
  overflow-x: hidden !important;
  word-break: break-word;
  overflow-wrap: anywhere;
}

/* Sugerencias de chat */
.suggestions-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 10px;
}

.btn-suggestion {
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid var(--card-border);
  color: var(--text-primary);
  border-radius: 20px;
  padding: 6px 12px;
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
  transition: var(--transition-smooth);
}

.btn-suggestion:hover {
  background: rgba(168, 85, 247, 0.2);
  color: #a855f7;
  border-color: rgba(168, 85, 247, 0.4);
}

/* Barra de Entrada Flotante */
.chat-input-bar {
  position: fixed;
  bottom: 68px;
  left: 50%;
  transform: translateX(-50%);
  width: calc(100% - 24px);
  max-width: 760px;
  background: rgba(15, 23, 42, 0.92);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 30px;
  padding: 5px 6px 5px 16px;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 10px 30px rgba(0,0,0,0.4);
  z-index: 90;
}

body.light-theme .chat-input-bar {
  background: rgba(255, 255, 255, 0.92);
  border-color: rgba(0, 0, 0, 0.12);
  box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

@media (min-width: 769px) {
  .chat-input-bar {
    bottom: 20px;
    width: calc(100% - 260px);
    margin-left: 110px;
  }
}

.chat-pill-input {
  background: transparent !important;
  border: none !important;
  outline: none !important;
  color: var(--text-primary);
  font-size: 14.5px;
  flex: 1;
  padding: 8px 4px;
}

.btn-send-pill {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
  color: #ffffff;
  border: none;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  cursor: pointer;
  flex-shrink: 0;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
  box-shadow: 0 4px 12px rgba(168, 85, 247, 0.4);
}

.btn-send-pill:active {
  transform: scale(0.92);
}

/* Animación de tres puntos suspensivos */
.typing-dots {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 4px 6px;
}

.typing-dots span {
  width: 7px;
  height: 7px;
  background-color: #a855f7;
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
    transform: translateY(8px);
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
