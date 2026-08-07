<template>
  <div class="reports-container fade-in">
    <!-- Encabezado de Vista -->
    <div class="view-header">
      <h1 class="view-title">Reportes & Suscripciones</h1>
      <p class="view-subtitle">Exporta tus movimientos y revisa los cargos recurrentes detectados este mes</p>
    </div>

    <!-- Selector de período -->
    <div class="glass-card" style="padding:14px 16px; margin-bottom:16px; display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
      <div style="flex:1 1 140px;">
        <label style="display:block; font-size:11px; color:var(--text-secondary); margin-bottom:4px; font-weight:600;">Mes</label>
        <select v-model.number="reportMonth" style="width:100%; height:38px; border-radius:8px; border:1px solid var(--card-border); background:rgba(255,255,255,0.05); color:var(--text-primary); padding:0 8px; font-size:13px; outline:none;">
          <option value="1">Enero</option>
          <option value="2">Febrero</option>
          <option value="3">Marzo</option>
          <option value="4">Abril</option>
          <option value="5">Mayo</option>
          <option value="6">Junio</option>
          <option value="7">Julio</option>
          <option value="8">Agosto</option>
          <option value="9">Septiembre</option>
          <option value="10">Octubre</option>
          <option value="11">Noviembre</option>
          <option value="12">Diciembre</option>
        </select>
      </div>
      <div style="flex:1 1 100px;">
        <label style="display:block; font-size:11px; color:var(--text-secondary); margin-bottom:4px; font-weight:600;">Año</label>
        <select v-model.number="reportYear" style="width:100%; height:38px; border-radius:8px; border:1px solid var(--card-border); background:rgba(255,255,255,0.05); color:var(--text-primary); padding:0 8px; font-size:13px; outline:none;">
          <option value="2026">2026</option>
          <option value="2025">2025</option>
          <option value="2024">2024</option>
        </select>
      </div>
    </div>

    <!-- Exportación de Reportes -->
    <div class="glass-card" style="padding:18px; margin-bottom:16px;">
      <h4 style="margin:0 0 12px 0; font-size:13px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); font-weight:700;">
        <i class="fa-solid fa-file-export" style="color:var(--color-accent); margin-right:6px;"></i> Exportar movimientos del período
      </h4>
      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button @click="downloadReport('html')" class="btn-primary" style="flex:1 1 140px; height:42px; font-size:13px; border-radius:8px; display:flex; align-items:center; justify-content:center; gap:6px;">
          <i class="fa-solid fa-file-pdf"></i> Reporte PDF
        </button>
        <button @click="downloadReport('csv')" class="btn-secondary" style="flex:1 1 140px; height:42px; font-size:13px; border-radius:8px; display:flex; align-items:center; justify-content:center; gap:6px;">
          <i class="fa-solid fa-file-excel"></i> Excel / CSV
        </button>
      </div>
    </div>

    <!-- Suscripciones detectadas -->
    <div class="glass-card" style="padding:18px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h4 style="margin:0; font-size:13px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); font-weight:700;">
          <i class="fa-solid fa-rotate" style="color:var(--color-accent); margin-right:6px;"></i> Suscripciones detectadas
        </h4>
        <span v-if="insightsData" style="font-size:12px; font-weight:700; color:var(--text-primary);">
          {{ formatCurrency(insightsData.subscriptions.monthly_total) }}/mes
        </span>
      </div>

      <div v-if="loading" style="text-align:center; padding:30px; color:var(--text-muted);">Cargando...</div>

      <div v-else-if="!insightsData || insightsData.subscriptions.items.length === 0" style="text-align:center; padding:24px; color:var(--text-muted); font-size:13px;">
        No se detectaron suscripciones o cargos recurrentes este mes.
      </div>

      <div v-else class="subscriptions-list">
        <div v-for="(sub, idx) in insightsData.subscriptions.items" :key="idx" class="subscription-row">
          <div>
            <strong style="font-size:13.5px; color:var(--text-primary);">{{ sub.name }}</strong>
            <div v-if="sub.category" style="font-size:11.5px; color:var(--text-muted);">{{ sub.category }}</div>
          </div>
          <strong style="font-size:14px; color:var(--color-danger);">{{ formatCurrency(sub.amount) }}</strong>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, onMounted, watch } from 'vue'
import { API_BASE } from '../config.js'

export default {
  name: 'ReportsView',
  setup() {
    const insightsData = ref(null)
    const loading = ref(false)
    const reportMonth = ref(new Date().getMonth() + 1)
    const reportYear = ref(new Date().getFullYear())
    const currencyCode = ref('COP')

    const formatCurrency = (val) => {
      return new Intl.NumberFormat('es-CO', {
        style: 'currency',
        currency: currencyCode.value,
        minimumFractionDigits: currencyCode.value === 'USD' || currencyCode.value === 'EUR' ? 2 : 0,
        maximumFractionDigits: currencyCode.value === 'USD' || currencyCode.value === 'EUR' ? 2 : 0
      }).format(val || 0)
    }

    const downloadReport = (format) => {
      const token = localStorage.getItem('token')
      const url = `${API_BASE}/export_report.php?format=${format}&month=${reportMonth.value}&year=${reportYear.value}&token=${token}`
      window.open(url, '_blank')
    }

    const fetchInsights = async () => {
      loading.value = true
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
        console.error('Error al cargar suscripciones:', err)
      } finally {
        loading.value = false
      }
    }

    onMounted(fetchInsights)
    watch(() => [reportMonth.value, reportYear.value], fetchInsights)

    return {
      insightsData,
      loading,
      reportMonth,
      reportYear,
      formatCurrency,
      downloadReport
    }
  }
}
</script>

<style scoped>
.subscriptions-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.subscription-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 14px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid var(--card-border);
}

body.light-theme .subscription-row {
  background: rgba(0, 0, 0, 0.015);
}
</style>
