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

    <!-- Puntos por ahorro -->
    <div v-if="points" class="glass-card fh-points-card">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; gap:10px; flex-wrap:wrap;">
        <h4 style="margin:0; font-size:12.5px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); font-weight:700;">
          <i class="fa-solid fa-trophy" style="color:#f59e0b; margin-right:6px;"></i> Puntos por Ahorro
        </h4>
        <div class="fh-level-badge">Nivel {{ points.level.name }}</div>
      </div>

      <div style="display:flex; align-items:baseline; gap:8px; margin-bottom:8px;">
        <div style="font-size:34px; font-weight:800; line-height:1; color:#f59e0b;">{{ points.total }}</div>
        <div style="font-size:13px; color:var(--text-muted); font-weight:600;">puntos en los últimos 12 meses</div>
      </div>

      <div class="fh-progress-bg"><div class="fh-progress-fill" :style="{ width: Math.round(points.level.progress * 100) + '%' }"></div></div>
      <p style="font-size:12px; color:var(--text-secondary); margin:6px 0 12px 0;">
        <template v-if="points.level.next_name">Te faltan <strong>{{ points.level.points_to_next }}</strong> puntos para nivel {{ points.level.next_name }}.</template>
        <template v-else>Estás en el nivel máximo.</template>
        <template v-if="points.streak >= 2"> Llevas una racha de <strong>{{ points.streak }} meses</strong> seguidos ahorrando.</template>
      </p>

      <div class="fh-points-month">
        <div style="display:flex; justify-content:space-between; font-size:12.5px; font-weight:700; margin-bottom:4px;">
          <span>{{ capitalize(points.current_month.label) }} · en juego (cierre estimado)</span>
          <span style="color:#f59e0b;">{{ points.current_month.points }} pts</span>
        </div>
        <div v-for="(b, i) in points.current_month.breakdown" :key="'c' + i" class="fh-points-row">
          <span>{{ b.label.replace('Ahorraste', 'Vas para ahorrar').replace('Cerraste', 'Vas a cerrar') }}</span><span>+{{ b.points }}</span>
        </div>
        <p style="font-size:11.5px; color:var(--text-muted); margin:6px 0 0 0;">
          Es una proyección de cómo cerraría el mes al ritmo que llevas. Los puntos se suman al cierre, con lo que hayas ahorrado de verdad.
          <template v-if="points.current_month.points === 0">Ahorra parte de tus ingresos y mantente dentro del presupuesto para empezar a sumar.</template>
        </p>
      </div>

      <div v-for="m in points.months.slice(0, showAllPoints ? 12 : 3)" :key="m.year + '-' + m.month" class="fh-points-month">
        <div style="display:flex; justify-content:space-between; font-size:12.5px; font-weight:700; margin-bottom:4px;">
          <span>{{ capitalize(m.label) }} · ahorraste {{ formatCurrency(m.saved) }}</span>
          <span :style="{ color: m.points > 0 ? '#f59e0b' : 'var(--text-muted)' }">{{ m.points }} pts</span>
        </div>
        <div v-for="(b, i) in m.breakdown" :key="i" class="fh-points-row">
          <span>{{ b.label }}</span><span>+{{ b.points }}</span>
        </div>
        <div v-if="m.breakdown.length === 0" class="fh-points-row"><span>Este mes gastaste más de lo que ingresó: no sumó puntos.</span><span></span></div>
      </div>
      <button v-if="points.months.length > 3" @click="showAllPoints = !showAllPoints" class="btn-secondary" style="height:30px; padding:0 12px; font-size:12px; border-radius:8px; margin-top:8px;">
        {{ showAllPoints ? 'Ver menos' : 'Ver los 12 meses' }}
      </button>

      <p style="font-size:11.5px; color:var(--text-muted); margin:12px 0 0 0;">
        Cómo se ganan: 10 puntos por cada 1% de tus ingresos que ahorres (máx. 500 al mes) · 100 por cerrar dentro del presupuesto · 50 si ninguna categoría se pasa · 50 por abonar a tus metas · 25 por cada mes de racha (máx. 150).
      </p>
    </div>

    <!-- Plan de inversión -->
    <div v-if="investment" class="glass-card fh-analysis-card" style="margin-bottom:20px;">
      <h4 style="margin:0 0 10px 0; font-size:13px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); font-weight:700;">
        <i class="fa-solid fa-seedling" style="color:var(--color-success); margin-right:6px;"></i> ¿Puedo invertir?
      </h4>
      <div class="fh-stage" :class="'fh-stage-' + investment.stage">{{ investment.headline }}</div>

      <div class="fh-invest-grid">
        <div><span>Reserva de emergencia</span><strong>{{ investment.emergency_months === null ? '—' : investment.emergency_months + ' meses' }}</strong></div>
        <div><span>Deudas</span><strong>{{ formatCurrency(investment.debt_total) }}</strong></div>
        <div><span>Excedente mensual promedio</span><strong>{{ formatCurrency(investment.monthly_surplus) }}</strong></div>
        <div><span>Puedes invertir al mes</span><strong>{{ formatCurrency(investment.investable_monthly) }}</strong></div>
      </div>

      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-top:12px;">
        <label class="fh-field">
          <span>¿Para cuándo necesitas la plata?</span>
          <select v-model="investHorizon">
            <option value="corto">En menos de 1 año</option>
            <option value="medio">Entre 1 y 5 años</option>
            <option value="largo">En más de 5 años</option>
          </select>
        </label>
        <label class="fh-field">
          <span>¿Cuánto riesgo toleras?</span>
          <select v-model="investRisk">
            <option value="conservador">Conservador</option>
            <option value="moderado">Moderado</option>
            <option value="arriesgado">Arriesgado</option>
          </select>
        </label>
        <button @click="runInvestmentPlan" :disabled="loadingPlan" class="btn-primary" style="height:38px; padding:0 16px; font-size:13px; border-radius:8px;">
          <i class="fa-solid fa-wand-magic-sparkles"></i> {{ loadingPlan ? 'Armando tu plan...' : (investmentPlan ? 'Volver a generar' : 'Armar mi plan con IA') }}
        </button>
      </div>

      <div v-if="loadingPlan" class="fh-loading"><div class="spinner"></div><span>La IA está armando tu plan con tus cifras reales...</span></div>
      <div v-else-if="planError" style="color:var(--color-danger); font-size:13px; padding:10px 0;">{{ planError }}</div>
      <div v-else-if="investmentPlan" class="formatted-text" style="margin-top:14px;" v-html="renderFormattedText(investmentPlan)"></div>
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
    const points = ref(null)
    const showAllPoints = ref(false)
    const investment = ref(null)
    const investHorizon = ref('medio')
    const investRisk = ref('moderado')
    const investmentPlan = ref('')
    const loadingPlan = ref(false)
    const planError = ref('')

    const capitalize = (text) => text ? text.charAt(0).toUpperCase() + text.slice(1) : ''

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
          points.value = insightsData.value.points || null
          investment.value = insightsData.value.investment || null
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

    const runInvestmentPlan = async () => {
      loadingPlan.value = true
      planError.value = ''
      const token = localStorage.getItem('token')
      const customApiKey = localStorage.getItem('gemini_api_key') || ''
      const headers = { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` }
      if (customApiKey) headers['X-Gemini-API-Key'] = customApiKey

      try {
        const response = await fetch(`${API_BASE}/ai.php?action=investment_plan`, {
          method: 'POST',
          headers,
          body: JSON.stringify({ horizon: investHorizon.value, risk: investRisk.value })
        })
        let data
        try {
          data = await response.json()
        } catch (parseErr) {
          throw new Error('El Asesor IA tardó demasiado en responder o tuvo un problema temporal. Intenta de nuevo en unos segundos.')
        }
        if (!response.ok || data.error) {
          throw new Error([data.error, data.details].filter(Boolean).join(' ') || 'No se pudo generar el plan.')
        }
        investmentPlan.value = data.plan || ''
      } catch (err) {
        planError.value = err.message || 'Error al conectar con el Asesor IA.'
      } finally {
        loadingPlan.value = false
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
      runAnalysis,
      points,
      showAllPoints,
      capitalize,
      investment,
      investHorizon,
      investRisk,
      investmentPlan,
      loadingPlan,
      planError,
      runInvestmentPlan
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

.fh-points-card {
  padding: 16px;
  margin-bottom: 20px;
}

.fh-level-badge {
  padding: 3px 12px;
  border-radius: 20px;
  font-weight: 700;
  font-size: 11.5px;
  color: #f59e0b;
  background: rgba(245, 158, 11, 0.14);
  border: 1px solid rgba(245, 158, 11, 0.45);
}

.fh-progress-bg {
  height: 8px;
  border-radius: 6px;
  background: rgba(255, 255, 255, 0.08);
  overflow: hidden;
}

.fh-progress-fill {
  height: 100%;
  border-radius: 6px;
  background: linear-gradient(90deg, #f59e0b, #fbbf24);
}

.fh-points-month {
  padding: 10px 12px;
  margin-top: 8px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid var(--card-border);
}

.fh-points-row {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  font-size: 12px;
  color: var(--text-secondary);
  padding: 2px 0;
}

.fh-stage {
  padding: 10px 12px;
  border-radius: 10px;
  font-size: 13px;
  line-height: 1.45;
  color: var(--text-primary);
  background: rgba(245, 158, 11, 0.12);
  border: 1px solid rgba(245, 158, 11, 0.35);
}

.fh-stage-listo {
  background: rgba(16, 185, 129, 0.12);
  border-color: rgba(16, 185, 129, 0.35);
}

.fh-stage-pagar_deuda_cara {
  background: rgba(239, 68, 68, 0.12);
  border-color: rgba(239, 68, 68, 0.35);
}

.fh-invest-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 8px;
  margin-top: 10px;
}

.fh-invest-grid > div {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 8px 10px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid var(--card-border);
}

.fh-invest-grid span {
  font-size: 11px;
  color: var(--text-muted);
}

.fh-invest-grid strong {
  font-size: 14px;
  color: var(--text-primary);
}

.fh-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 11.5px;
  color: var(--text-secondary);
  flex: 1 1 160px;
}

.fh-field select {
  height: 38px;
  border-radius: 8px;
  padding: 0 10px;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid var(--card-border);
  color: var(--text-primary);
  font-size: 13px;
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
