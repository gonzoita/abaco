<template>
  <div class="fh-container fade-in">
    <div class="view-header">
      <h1 class="view-title">Salud & Autonomía Financiera</h1>
      <p class="view-subtitle">Análisis automático con IA de tu bienestar económico</p>
    </div>

    <!-- Resumen numérico -->
    <div v-if="insightsData" class="fh-summary-grid">
      <div class="glass-card fh-summary-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
          <h4 style="margin:0; font-size:12.5px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); font-weight:700;">
            <i class="fa-solid fa-heart-pulse" style="color:var(--color-danger); margin-right:6px;"></i> Salud Financiera
          </h4>
          <div class="health-badge" :style="{ backgroundColor: insightsData.health_color + '20', color: insightsData.health_color, border: '1px solid ' + insightsData.health_color }" style="padding:3px 10px; border-radius:20px; font-weight:700; font-size:11px;">
            {{ insightsData.health_status }}
          </div>
        </div>
        <div style="display:flex; align-items:baseline; gap:8px; margin-bottom:8px;">
          <div style="font-size:34px; font-weight:800; line-height:1;" :style="{ color: insightsData.health_color }">
            {{ insightsData.health_score }}
          </div>
          <div style="font-size:13px; color:var(--text-muted); font-weight:600;">/ 100 pts</div>
        </div>
        <p style="font-size:12px; color:var(--text-secondary); margin:0;">{{ insightsData.recommendation }}</p>
      </div>

      <div class="glass-card fh-summary-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
          <h4 style="margin:0; font-size:12.5px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); font-weight:700;">
            <i class="fa-solid fa-shield-halved" style="color:var(--color-primary); margin-right:6px;"></i> Autonomía Financiera
          </h4>
        </div>
        <div style="font-size:22px; font-weight:800; color:var(--text-primary); margin-bottom:6px;">
          {{ insightsData.runway.months }} Meses
          <span style="font-size:12.5px; font-weight:500; color:var(--text-secondary);">({{ insightsData.runway.days }} días)</span>
        </div>
        <p style="font-size:12px; color:var(--text-secondary); margin:0 0 8px 0;">
          Saldos líquidos: {{ formatCurrency(insightsData.runway.liquid_balance) }} · Gasto promedio: {{ formatCurrency(insightsData.runway.avg_monthly_expense) }}/mes
        </p>
        <div style="display:flex; justify-content:space-between; align-items:center; background:rgba(255,255,255,0.04); padding:6px 10px; border-radius:8px; border:1px solid var(--card-border); font-size:11.5px;">
          <span>Predicción fin de mes:</span>
          <strong :style="{ color: insightsData.forecast.projected_savings >= 0 ? 'var(--color-success)' : 'var(--color-danger)' }">
            {{ insightsData.forecast.projected_savings >= 0 ? '+' : '' }}{{ formatCurrency(insightsData.forecast.projected_savings) }}
          </strong>
        </div>
      </div>
    </div>

    <!-- Análisis de IA -->
    <div class="glass-card fh-analysis-card">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h4 style="margin:0; font-size:13px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); font-weight:700;">
          <i class="fa-solid fa-wand-magic-sparkles" style="color:#a855f7; margin-right:6px;"></i> Análisis del Asesor IA
        </h4>
        <button v-if="!loadingAnalysis" @click="runAnalysis" class="btn-secondary" style="height:32px; padding:0 12px; font-size:12px; border-radius:8px; display:flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-rotate"></i> Regenerar
        </button>
      </div>

      <div v-if="loadingAnalysis" class="fh-loading">
        <div class="spinner"></div>
        <span>La IA está analizando tu salud financiera...</span>
      </div>

      <div v-else-if="analysisError" style="color:var(--color-danger); font-size:13px; padding:10px;">
        {{ analysisError }}
        <button @click="runAnalysis" class="btn-primary" style="margin-top:10px; height:34px; padding:0 14px; font-size:12px; border-radius:8px;">Reintentar</button>
      </div>

      <div v-else class="formatted-text" v-html="renderFormattedText(analysis)"></div>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue'
import { API_BASE } from '../config.js'

export default {
  name: 'FinancialHealthView',
  setup() {
    const insightsData = ref(null)
    const analysis = ref('')
    const loadingAnalysis = ref(false)
    const analysisError = ref('')
    const currencyCode = ref('COP')

    const formatCurrency = (val) => {
      return new Intl.NumberFormat('es-CO', {
        style: 'currency',
        currency: currencyCode.value,
        minimumFractionDigits: currencyCode.value === 'USD' || currencyCode.value === 'EUR' ? 2 : 0,
        maximumFractionDigits: currencyCode.value === 'USD' || currencyCode.value === 'EUR' ? 2 : 0
      }).format(val || 0)
    }

    const renderFormattedText = (raw) => {
      if (!raw) return ''
      let text = raw.replace(/</g, '&lt;').replace(/>/g, '&gt;')
      text = text.replace(/^###\s+(.+)$/gm, '<h4 style="margin:16px 0 8px 0; color:var(--text-primary);">$1</h4>')
      text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
      text = text.replace(/^\s*[-*]\s+(.+)$/gm, '<li style="margin-left:14px; list-style-type:disc;">$1</li>')
      text = text.replace(/\n/g, '<br>')
      return text
    }

    const fetchInsights = async () => {
      const token = localStorage.getItem('token')
      const ws = localStorage.getItem('active_workspace') || 'personal'
      try {
        const user = JSON.parse(localStorage.getItem('user') || '{}')
        if (user.currency) currencyCode.value = user.currency

        const res = await fetch(`${API_BASE}/insights.php`, {
          headers: { 'Authorization': `Bearer ${token}`, 'X-Workspace': ws }
        })
        if (res.ok) {
          insightsData.value = await res.json()
        }
      } catch (err) {
        console.error('Error al cargar insights:', err)
      }
    }

    const runAnalysis = async () => {
      loadingAnalysis.value = true
      analysisError.value = ''
      analysis.value = ''
      const token = localStorage.getItem('token')
      const customApiKey = localStorage.getItem('gemini_api_key') || ''
      const headers = {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      }
      if (customApiKey) headers['X-Gemini-API-Key'] = customApiKey

      try {
        const response = await fetch(`${API_BASE}/ai.php?action=financial_diagnosis`, {
          method: 'POST',
          headers,
          body: JSON.stringify({ user_notes: '' })
        })
        let data
        try {
          data = await response.json()
        } catch (parseErr) {
          // El servidor devolvió algo que no es JSON (p. ej. su propia
          // página de error HTML si tardó demasiado): no mostrar el error
          // crudo de parseo, dar un mensaje que se entienda.
          throw new Error('El Asesor IA tardó demasiado en responder o tuvo un problema temporal. Intenta de nuevo en unos segundos.')
        }
        if (!response.ok || data.error) {
          throw new Error(data.error || 'No se pudo generar el análisis.')
        }
        analysis.value = data.diagnosis || 'No se pudo generar el análisis en este momento.'
      } catch (err) {
        analysisError.value = err.message || 'Error al conectar con el Asesor IA.'
      } finally {
        loadingAnalysis.value = false
      }
    }

    onMounted(async () => {
      await fetchInsights()
      runAnalysis()
    })

    return {
      insightsData,
      analysis,
      loadingAnalysis,
      analysisError,
      formatCurrency,
      renderFormattedText,
      runAnalysis
    }
  }
}
</script>

<style scoped>
.fh-summary-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 20px;
}

@media (max-width: 480px) {
  .fh-summary-grid {
    grid-template-columns: 1fr;
  }
}

.fh-summary-card {
  padding: 16px;
}

.fh-analysis-card {
  padding: 20px;
}

.fh-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
  padding: 40px 20px;
  color: var(--text-secondary);
  font-size: 13.5px;
}

.spinner {
  width: 28px;
  height: 28px;
  border: 3px solid rgba(255, 255, 255, 0.1);
  border-radius: 50%;
  border-top-color: var(--color-primary);
  animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.formatted-text {
  font-size: 13.5px;
  line-height: 1.6;
  color: var(--text-primary);
}
</style>
