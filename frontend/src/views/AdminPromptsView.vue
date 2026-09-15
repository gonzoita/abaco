<template>
  <div class="admin-container">
    <div class="admin-header">
      <div>
        <router-link to="/admin" class="back-link">← Volver al panel</router-link>
        <h1 class="page-title text-gradient-purple">Prompts de IA</h1>
        <p class="page-subtitle">Edita el texto que Ábaco envía a Gemini en cada función de IA, sin necesidad de tocar código.</p>
      </div>
    </div>

    <!-- Alertas de Éxito / Error -->
    <div v-if="successMsg" class="alert-box success">
      <i class="fa-solid fa-circle-check"></i>
      <span>{{ successMsg }}</span>
    </div>
    <div v-if="errorMsg" class="alert-box danger">
      <i class="fa-solid fa-circle-exclamation"></i>
      <span>{{ errorMsg }}</span>
    </div>

    <div v-if="loading" class="loading-state">Cargando prompts...</div>

    <div v-else class="prompts-list">
      <div v-for="p in prompts" :key="p.prompt_key" class="prompt-card glass-card">
        <div class="prompt-card-header">
          <div>
            <h3>{{ p.label }}</h3>
            <span v-if="p.is_customized" class="badge-customized">
              Personalizado · {{ formatDate(p.updated_at) }}
            </span>
            <span v-else class="badge-default">Valor por defecto</span>
          </div>
        </div>

        <div v-if="p.placeholders.length" class="placeholders-legend">
          No borres estos marcadores, se reemplazan por datos reales del usuario:
          <code v-for="ph in p.placeholders" :key="ph">{{ ph }}</code>
        </div>

        <textarea
          v-model="p.prompt_text"
          class="prompt-textarea"
          rows="10"
          spellcheck="false"
        ></textarea>
        <div class="char-count">{{ p.prompt_text.length }} caracteres</div>

        <div class="prompt-card-actions">
          <button
            class="btn-secondary"
            @click="resetPrompt(p)"
            :disabled="!p.is_customized || savingKey === p.prompt_key"
          >
            Restaurar por defecto
          </button>
          <button
            class="btn-primary"
            @click="savePrompt(p)"
            :disabled="savingKey === p.prompt_key"
          >
            {{ savingKey === p.prompt_key ? 'Guardando...' : 'Guardar' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue'

export default {
  name: 'AdminPromptsView',
  setup() {
    const API_BASE = window.location.hostname === 'localhost'
      ? 'http://localhost/control-finanzas/backend/api'
      : '/backend/api'

    const prompts = ref([])
    const loading = ref(true)
    const savingKey = ref('')
    const successMsg = ref('')
    const errorMsg = ref('')

    const getHeaders = () => {
      const token = localStorage.getItem('token')
      return {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      }
    }

    const loadPrompts = async () => {
      loading.value = true
      try {
        const response = await fetch(`${API_BASE}/admin.php?action=list_prompts`, {
          headers: getHeaders()
        })
        const data = await response.json()
        if (response.ok) {
          prompts.value = data
        } else {
          errorMsg.value = data.error || 'Error al cargar los prompts'
        }
      } catch (err) {
        errorMsg.value = 'Error de red al conectar con el servidor.'
      } finally {
        loading.value = false
      }
    }

    const savePrompt = async (p) => {
      successMsg.value = ''
      errorMsg.value = ''
      savingKey.value = p.prompt_key

      try {
        const response = await fetch(`${API_BASE}/admin.php?action=update_prompt`, {
          method: 'POST',
          headers: getHeaders(),
          body: JSON.stringify({ prompt_key: p.prompt_key, prompt_text: p.prompt_text })
        })
        const data = await response.json()
        if (response.ok) {
          successMsg.value = `"${p.label}" actualizado con éxito.`
          setTimeout(() => successMsg.value = '', 3000)
          await loadPrompts()
        } else {
          errorMsg.value = data.error || 'Error al guardar el prompt'
        }
      } catch (err) {
        errorMsg.value = 'Fallo de red al intentar guardar cambios.'
      } finally {
        savingKey.value = ''
      }
    }

    const resetPrompt = async (p) => {
      successMsg.value = ''
      errorMsg.value = ''
      savingKey.value = p.prompt_key

      try {
        const response = await fetch(`${API_BASE}/admin.php?action=reset_prompt`, {
          method: 'POST',
          headers: getHeaders(),
          body: JSON.stringify({ prompt_key: p.prompt_key })
        })
        const data = await response.json()
        if (response.ok) {
          successMsg.value = `"${p.label}" restaurado al valor por defecto.`
          setTimeout(() => successMsg.value = '', 3000)
          await loadPrompts()
        } else {
          errorMsg.value = data.error || 'Error al restaurar el prompt'
        }
      } catch (err) {
        errorMsg.value = 'Fallo de red al intentar restaurar el prompt.'
      } finally {
        savingKey.value = ''
      }
    }

    const formatDate = (dateStr) => {
      if (!dateStr) return '-'
      const date = new Date(dateStr.replace(' ', 'T'))
      return date.toLocaleDateString('es-ES', {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
      })
    }

    onMounted(() => {
      loadPrompts()
    })

    return {
      prompts,
      loading,
      savingKey,
      successMsg,
      errorMsg,
      savePrompt,
      resetPrompt,
      formatDate
    }
  }
}
</script>

<style scoped>
.admin-container {
  padding: 30px 24px;
  max-width: 1000px;
  margin: 0 auto;
}

.admin-header {
  margin-bottom: 20px;
}

.back-link {
  display: inline-block;
  color: var(--text-secondary);
  font-size: 13.5px;
  text-decoration: none;
  margin-bottom: 10px;
}

.back-link:hover {
  color: var(--text-primary);
}

.page-title {
  font-size: 28px;
  font-weight: 800;
  margin: 0 0 6px 0;
}

.page-subtitle {
  color: var(--text-secondary);
  font-size: 15px;
  margin: 0;
}

.loading-state {
  text-align: center;
  padding: 60px 0;
  color: var(--text-secondary);
}

.prompts-list {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.prompt-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.prompt-card-header h3 {
  margin: 0 0 4px 0;
  font-size: 17px;
  font-weight: 700;
}

.badge-customized {
  display: inline-block;
  font-size: 12px;
  color: var(--color-accent);
  background: rgba(139, 92, 246, 0.1);
  border: 1px solid rgba(139, 92, 246, 0.2);
  border-radius: 6px;
  padding: 2px 8px;
}

.badge-default {
  display: inline-block;
  font-size: 12px;
  color: var(--text-muted);
}

.placeholders-legend {
  font-size: 12.5px;
  color: var(--text-secondary);
  line-height: 1.6;
}

.placeholders-legend code {
  background: rgba(255, 255, 255, 0.06);
  border-radius: 4px;
  padding: 1px 6px;
  margin-left: 4px;
  font-size: 12px;
  color: var(--color-accent);
}

.prompt-textarea {
  width: 100%;
  min-height: 180px;
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid var(--card-border);
  border-radius: 8px;
  color: var(--text-primary);
  padding: 12px;
  font-size: 13.5px;
  font-family: ui-monospace, 'SF Mono', Consolas, monospace;
  line-height: 1.5;
  resize: vertical;
}

.char-count {
  font-size: 11.5px;
  color: var(--text-muted);
  text-align: right;
}

.prompt-card-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}
</style>
