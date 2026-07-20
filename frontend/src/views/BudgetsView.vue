<template>
  <div class="budgets-container">
    <div class="view-header">
      <div>
        <h1 class="view-title">Presupuestos Mensuales</h1>
        <p class="view-subtitle">Define límites de gastos, compáralos con tus egresos reales y optimízalos con IA</p>
      </div>
      <button class="btn-primary" @click="openBudgetModal(null)">
        <i class="fa-solid fa-plus"></i> Configurar Presupuesto
      </button>
    </div>

    <!-- Panel de presupuesto global -->
    <div class="global-budget-card glass-card" v-if="globalBudget && globalBudget.limit > 0">
      <div class="budget-meta">
        <div>
          <span class="label">Presupuesto Global del Mes (Automático)</span>
          <div style="display: flex; align-items: center; gap: 12px; margin-top: 4px;">
            <h2 class="amount" style="margin-top: 0; margin-bottom: 0;">{{ formatCurrency(globalBudget.limit) }}</h2>
          </div>
        </div>
        <div class="usage-summary">
          <span class="label">Consumido:</span>
          <h3 class="spent" :class="globalBudget.percentage >= 100 ? 'amount-negative' : 'amount-positive'">
            {{ formatCurrency(globalBudget.spent) }}
          </h3>
        </div>
      </div>

      <div class="progress-bar-bg large">
        <div class="progress-bar-fill" :class="getBarClass(globalBudget.percentage)" :style="{ width: Math.min(globalBudget.percentage, 100) + '%' }"></div>
      </div>

      <div class="budget-footer">
        <span>{{ globalBudget.percentage }}% utilizado</span>
        <span v-if="globalBudget.limit - globalBudget.spent >= 0">
          Disponible: <strong class="amount-positive">{{ formatCurrency(globalBudget.limit - globalBudget.spent) }}</strong>
        </span>
        <span v-else>
          Excedido por: <strong class="amount-negative">{{ formatCurrency(globalBudget.spent - globalBudget.limit) }}</strong>
        </span>
      </div>

      <!-- Alertas de presupuesto global superado o cercano al límite -->
      <div v-if="globalBudget.percentage >= 100" class="budget-alert-banner danger">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><strong>¡Presupuesto Superado!</strong> Has excedido el límite general de tus gastos por {{ formatCurrency(globalBudget.spent - globalBudget.limit) }}.</span>
      </div>
      <div v-else-if="globalBudget.percentage >= 85" class="budget-alert-banner warning">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span><strong>¡Alerta de Consumo!</strong> Estás muy cerca de tu límite presupuestario total ({{ globalBudget.percentage }}% utilizado).</span>
      </div>
    </div>

    <div class="global-budget-card glass-card empty" v-else>
      <i class="fa-solid fa-chart-pie" style="font-size: 32px; color: var(--text-muted); margin-bottom: 12px;"></i>
      <p style="margin: 0; font-size: 14.5px; color: var(--text-secondary);">No has asignado presupuestos a ninguna categoría para este mes.</p>
      <p style="margin: 4px 0 0 0; font-size: 13px; color: var(--text-muted);">Configura un presupuesto por categoría y la suma total se mostrará aquí automáticamente.</p>
    </div>

    <!-- Sección Listado de Presupuestos por Categoría -->
    <div class="glass-card list-section">
      <h3 class="card-title text-gradient-green">
        <i class="fa-solid fa-chart-bar"></i> Presupuestos por Categoría
      </h3>
      <p class="card-subtitle">Límites mensuales asignados a categorías específicas. Recibirás alertas antes de sobrepasarlos.</p>
      
      <div v-if="categoryBudgets.length === 0" class="empty-state">
        <p>No has asignado presupuestos específicos a tus categorías todavía.</p>
        <button class="btn-secondary" @click="openBudgetModal(null)">Crear Primer Presupuesto</button>
      </div>

      <div v-else class="budgets-list">
        <div v-for="b in categoryBudgets" :key="b.id" class="budget-progress-item">
          <div class="budget-item-meta">
            <span class="cat-label">
              <i :class="'fa-solid ' + (b.category_icon || 'fa-tag')" :style="{ color: b.category_color, marginRight: '8px' }"></i>
              <strong>{{ b.category_name }}</strong>
            </span>
            <span class="cat-spent">
              {{ formatCurrency(b.spent) }} / <strong>{{ formatCurrency(b.limit) }}</strong>
            </span>
          </div>
          
          <div class="progress-bar-bg">
            <div class="progress-bar-fill" :class="getBarClass(b.percentage)" :style="{ width: Math.min(b.percentage, 100) + '%' }"></div>
          </div>

          <div class="budget-item-footer">
            <span :class="{ 'text-danger': b.percentage >= 100 }">{{ b.percentage }}% consumido</span>
            <!-- Alertas de categoría -->
            <span v-if="b.percentage >= 100" class="alert-badge danger">
              <i class="fa-solid fa-circle-exclamation"></i> Superado
            </span>
            <span v-else-if="b.percentage >= 85" class="alert-badge warning">
              <i class="fa-solid fa-triangle-exclamation"></i> Cerca
            </span>
            <div style="display: flex; gap: 8px; align-items: center;">
              <button class="btn-delete-budget" @click="openBudgetModal(b.category_id, b.limit)" title="Editar presupuesto">
                <i class="fa-solid fa-pen"></i>
              </button>
              <button class="btn-delete-budget" @click="deleteBudget(b.id)" title="Eliminar presupuesto">
                <i class="fa-solid fa-trash-can"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- SECCIÓN DE IA OPTIMIZACIÓN -->
    <div class="glass-card ai-optimization-section">
      <div class="ai-header">
        <div class="ai-title-wrapper">
          <div class="ai-spark-icon">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
          </div>
          <div>
            <h3 class="text-gradient-purple">Optimizar Presupuestos con IA</h3>
            <p>Deja que Gemini analice tus finanzas y te dé sugerencias inteligentes</p>
          </div>
        </div>
        <button class="btn-primary btn-ai" @click="optimizeBudgets" :disabled="aiLoading">
          <span v-if="aiLoading">Analizando con IA...</span>
          <span v-else>Optimizar Presupuesto</span>
        </button>
      </div>

      <!-- Cargando IA -->
      <div v-if="aiLoading" class="ai-loading-box">
        <div class="spinner"></div>
        <p>Analizando gastos de categorías, deudas y proponiendo ajustes ideales...</p>
      </div>

      <!-- Resultados IA -->
      <div v-if="aiReport" class="ai-results-box">
        <div class="ai-advice-markdown">
          <p class="formatted-text">{{ aiReport.recommendations }}</p>
        </div>

        <div class="proposed-budgets-section" v-if="aiReport.proposed_budgets && aiReport.proposed_budgets.length > 0">
          <h4>Propuesta de límites ajustados por la IA:</h4>
          
          <div class="proposal-table">
            <div class="proposal-header-row">
              <span>Categoría</span>
              <span>Límite Nuevo Propuesto</span>
            </div>
            <div v-for="prop in aiReport.proposed_budgets" :key="prop.category_id" class="proposal-row">
              <span>{{ getCategoryNameById(prop.category_id) }}</span>
              <strong>{{ formatCurrency(prop.amount) }}</strong>
            </div>
          </div>

          <div class="proposal-actions">
            <button class="btn-success" @click="applyAiBudgets" :disabled="aiApplying">
              {{ aiApplying ? 'Aplicando Presupuestos...' : 'Aplicar esta Propuesta' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- MODAL DE CONFIGURAR PRESUPUESTO -->
    <div v-if="showBudgetModal" class="modal-overlay" @click.self="closeBudgetModal">
      <div class="glass-card modal-content">
        <div class="modal-header">
          <h3>Configurar Presupuesto</h3>
          <button class="btn-close" @click="closeBudgetModal">&times;</button>
        </div>

        <form @submit.prevent="saveBudget" class="modal-form">
          <div class="form-group">
            <label for="bud-cat" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
              <span>Categoría de Gasto</span>
              <span v-if="form.category_id" style="font-size:11px; font-weight:700; color:var(--color-primary);">
                Seleccionada: {{ getCategoryNameById(form.category_id) }}
              </span>
            </label>

            <!-- Buscador en tiempo real de Categorías -->
            <div class="category-search-wrapper" style="position:relative; margin-bottom:8px;">
              <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:13px;"></i>
              <input 
                type="text" 
                v-model="budgetCatSearch" 
                placeholder="Buscar o crear categoría..." 
                style="width:100%; height:36px; padding-left:34px; padding-right:30px; border-radius:8px; border:1px solid var(--card-border); background:rgba(255,255,255,0.05); color:var(--text-primary); font-size:13px; outline:none;" 
              />
              <button 
                v-if="budgetCatSearch" 
                type="button" 
                @click="budgetCatSearch = ''" 
                style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); font-size:16px; cursor:pointer;"
              >
                &times;
              </button>
            </div>

            <!-- Rejilla Visual de Categorías -->
            <div class="category-chips-grid" style="display:flex; flex-wrap:wrap; gap:6px; max-height:140px; overflow-y:auto; padding:6px; border:1px solid var(--card-border); border-radius:8px; background:rgba(0,0,0,0.15);">
              <button 
                v-for="cat in searchedExpenseCategories" 
                :key="cat.id" 
                type="button" 
                class="cat-chip-btn"
                :class="{ selected: form.category_id === cat.id }"
                :style="form.category_id === cat.id ? { backgroundColor: (cat.color || '#8b5cf6') + '35', borderColor: cat.color || '#8b5cf6', color: 'var(--text-primary)', fontWeight: '700' } : {}"
                @click="form.category_id = cat.id"
                style="display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:6px; border:1px solid var(--card-border); background:rgba(255,255,255,0.04); color:var(--text-secondary); font-size:12px; font-weight:500; cursor:pointer; transition:all 0.2s ease;"
              >
                <i :class="['fa-solid', cat.icon || 'fa-tag']" :style="{ color: cat.color || '#8b5cf6' }"></i>
                <span>{{ cat.name }}</span>
              </button>

              <!-- Opción para crear categoría al vuelo si no existe -->
              <button 
                v-if="budgetCatSearch.trim() && !searchedExpenseCategories.some(c => c.name.toLowerCase() === budgetCatSearch.trim().toLowerCase())" 
                type="button" 
                class="cat-chip-btn create-new-chip" 
                @click="createBudgetCategoryOnTheFly(budgetCatSearch)"
                style="display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:6px; border:1px dashed var(--color-primary); background:rgba(10,132,255,0.15); color:var(--color-primary); font-size:12px; font-weight:600; cursor:pointer;"
              >
                <i class="fa-solid fa-plus"></i>
                <span>Crear "{{ budgetCatSearch.trim() }}"</span>
              </button>
            </div>
          </div>

          <div class="form-group">
            <label for="bud-amount">Monto del Presupuesto (COP)</label>
            <input type="number" id="bud-amount" v-model.number="form.amount" placeholder="0" required min="1" />
          </div>

          <div v-if="successMsg" class="success-msg">{{ successMsg }}</div>
          <div v-if="errorMsg" class="error-msg">{{ errorMsg }}</div>

          <button type="submit" class="btn-primary btn-block" :disabled="btnLoading">
            {{ btnLoading ? 'Guardando...' : 'Establecer Presupuesto' }}
          </button>
        </form>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { API_BASE } from '../config.js'

export default {
  name: 'BudgetsView',
  setup() {
    const categories = ref([])
    const globalBudget = ref(null)
    const categoryBudgets = ref([])

    const form = ref({
      category_id: '',
      amount: ''
    })

    // UI Estados
    const btnLoading = ref(false)
    const successMsg = ref('')
    const errorMsg = ref('')
    const showBudgetModal = ref(false)
    
    const aiLoading = ref(false)
    const aiReport = ref(null)
    const aiApplying = ref(false)

    const fetchCategories = async () => {
      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/categories.php`, {
          headers: { 'Authorization': `Bearer ${token}` }
        })
        categories.value = await response.json()
      } catch (err) {
        console.error(err)
      }
    }

    const fetchReports = async () => {
      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/reports.php`, {
          headers: { 'Authorization': `Bearer ${token}` }
        })
        const data = await response.json()
        if (response.ok) {
          globalBudget.value = data.global_budget
          categoryBudgets.value = data.category_budgets || []
        }
      } catch (err) {
        console.error(err)
      }
    }

    const saveBudget = async () => {
      btnLoading.value = true
      successMsg.value = ''
      errorMsg.value = ''

      const token = localStorage.getItem('token')
      
      try {
        const response = await fetch(`${API_BASE}/budgets.php`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`
          },
          body: JSON.stringify({
            category_id: form.value.category_id === '' ? null : form.value.category_id,
            amount: form.value.amount
          })
        })

        const data = await response.json()
        if (!response.ok) {
          throw new Error(data.error || 'Error al guardar el presupuesto.')
        }

        successMsg.value = 'Presupuesto guardado exitosamente.'
        form.value.amount = ''
        
        await fetchReports()
        
        // Cerrar modal automáticamente tras éxito
        setTimeout(() => {
          showBudgetModal.value = false
          successMsg.value = ''
        }, 1000)

      } catch (err) {
        errorMsg.value = err.message
      } finally {
        btnLoading.value = false
      }
    }

    const deleteBudget = async (id) => {
      if (!confirm('¿Estás seguro de eliminar este límite de presupuesto?')) return

      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/budgets.php?id=${id}`, {
          method: 'DELETE',
          headers: { 'Authorization': `Bearer ${token}` }
        })

        if (!response.ok) {
          throw new Error('Error al eliminar el presupuesto.')
        }

        await fetchReports()
      } catch (err) {
        alert(err.message)
      }
    }

    const openBudgetModal = (catId, currentLimit) => {
      form.value.category_id = catId === null ? '' : catId
      form.value.amount = currentLimit || ''
      successMsg.value = ''
      errorMsg.value = ''
      showBudgetModal.value = true
    }

    const closeBudgetModal = () => {
      showBudgetModal.value = false
    }

    // AI logic
    const optimizeBudgets = async () => {
      aiLoading.value = true
      aiReport.value = null
      const token = localStorage.getItem('token')

      try {
        const customApiKey = localStorage.getItem('gemini_api_key') || ''
        const headers = {
          'Authorization': `Bearer ${token}`
        }
        if (customApiKey) {
          headers['X-Gemini-API-Key'] = customApiKey
        }

        const response = await fetch(`${API_BASE}/ai.php?action=optimize_budget`, {
          method: 'POST',
          headers
        })

        const responseText = await response.text()
        let data
        try {
          data = JSON.parse(responseText)
        } catch (e) {
          throw new Error('Servidor error (no JSON): ' + responseText.substring(0, 150))
        }

        if (!response.ok) {
          throw new Error(data.error || 'Error al conectar con la IA.')
        }

        aiReport.value = data
      } catch (err) {
        alert('Error IA: ' + err.message)
      } finally {
        aiLoading.value = false
      }
    }

    const applyAiBudgets = async () => {
      if (!aiReport.value || !aiReport.value.proposed_budgets) return
      
      aiApplying.value = true
      const token = localStorage.getItem('token')

      try {
        // Enviar propuestas de presupuesto una por una
        for (const prop of aiReport.value.proposed_budgets) {
          await fetch(`${API_BASE}/budgets.php`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({
              category_id: prop.category_id,
              amount: prop.amount
            })
          })
        }

        alert('¡Los presupuestos propuestos por la IA se han aplicado correctamente!')
        aiReport.value = null // ocultar reporte tras aplicar
        await fetchReports()
      } catch (err) {
        alert('Error al aplicar propuestas: ' + err.message)
      } finally {
        aiApplying.value = false
      }
    }

    // Computeds & Búsqueda
    const budgetCatSearch = ref('')

    const expenseCategories = computed(() => {
      return categories.value.filter(c => c.type === 'egreso')
    })

    const searchedExpenseCategories = computed(() => {
      const list = expenseCategories.value
      if (!budgetCatSearch.value.trim()) return list
      const q = budgetCatSearch.value.toLowerCase().trim()
      return list.filter(c => c.name.toLowerCase().includes(q))
    })

    const createBudgetCategoryOnTheFly = async (catName) => {
      if (!catName || !catName.trim()) return
      const token = localStorage.getItem('token')
      const headers = {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      }
      const payload = {
        name: catName.trim(),
        icon: 'fa-tag',
        color: '#8b5cf6',
        type: 'egreso'
      }
      try {
        const res = await fetch(`${API_BASE}/categories.php`, {
          method: 'POST',
          headers,
          body: JSON.stringify(payload)
        })
        const data = await res.json()
        if (data.category) {
          categories.value.push(data.category)
          form.value.category_id = data.category.id
          budgetCatSearch.value = ''
        }
      } catch (err) {
        console.error(err)
      }
    }

    // Helpers
    const formatCurrency = (val) => {
      let currencyCode = 'COP'
      try {
        const user = JSON.parse(localStorage.getItem('user'))
        if (user && user.currency) {
          currencyCode = user.currency
        }
      } catch (e) {}

      const locale = currencyCode === 'COP' ? 'es-CO' : (currencyCode === 'MXN' ? 'es-MX' : (currencyCode === 'USD' ? 'en-US' : 'de-DE'))
      return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: currencyCode,
        minimumFractionDigits: currencyCode === 'USD' || currencyCode === 'EUR' ? 2 : 0,
        maximumFractionDigits: currencyCode === 'USD' || currencyCode === 'EUR' ? 2 : 0
      }).format(val)
    }

    const getBarClass = (percent) => {
      if (percent >= 100) return 'fill-danger'
      if (percent >= 80) return 'fill-warning'
      return 'fill-success'
    }

    const getCategoryNameById = (id) => {
      if (id === null) return 'Límite Global'
      const matched = categories.value.find(c => c.id === id)
      return matched ? matched.name : 'Categoría'
    }

    const handleWorkspaceChanged = () => {
      fetchCategories()
      fetchReports()
    }

    onMounted(() => {
      fetchCategories()
      fetchReports()
      window.addEventListener('workspace-changed', handleWorkspaceChanged)
    })

    onUnmounted(() => {
      window.removeEventListener('workspace-changed', handleWorkspaceChanged)
    })

    return {
      budgetCatSearch,
      searchedExpenseCategories,
      createBudgetCategoryOnTheFly,
      categories,
      globalBudget,
      categoryBudgets,
      form,
      btnLoading,
      successMsg,
      errorMsg,
      showBudgetModal,
      aiLoading,
      aiReport,
      aiApplying,
      expenseCategories,
      saveBudget,
      deleteBudget,
      openBudgetModal,
      closeBudgetModal,
      optimizeBudgets,
      applyAiBudgets,
      formatCurrency,
      getBarClass,
      getCategoryNameById
    }
  }
}
</script>

<style scoped>
.budgets-container {
  display: flex;
  flex-direction: column;
  gap: 24px;
  animation: fadeIn 0.4s ease-out;
}

.view-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.global-budget-card {
  display: flex;
  flex-direction: column;
  gap: 16px;
  background: var(--card-bg);
  border: 1px solid var(--card-border);
}

.global-budget-card.empty {
  align-items: center;
  justify-content: center;
  height: 180px;
  gap: 16px;
  color: var(--text-secondary);
}

.budget-meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.label {
  font-size: 13px;
  color: var(--text-secondary);
}

.amount {
  font-size: 32px;
  font-weight: 800;
}

.spent {
  font-size: 24px;
  font-weight: 700;
}

.progress-bar-bg {
  width: 100%;
  height: 8px;
  background: var(--bg-tertiary);
  border-radius: 4px;
  overflow: hidden;
}

.progress-bar-bg.large {
  height: 14px;
  border-radius: 7px;
}

.progress-bar-fill {
  height: 100%;
  border-radius: 4px;
  transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
}

.fill-success {
  background: linear-gradient(90deg, var(--color-success), #059669);
  box-shadow: 0 0 12px rgba(16, 185, 129, 0.3);
}

.fill-warning {
  background: linear-gradient(90deg, var(--color-warning), #d97706);
  box-shadow: 0 0 12px rgba(245, 158, 11, 0.3);
}

.fill-danger {
  background: linear-gradient(90deg, var(--color-danger), #dc2626);
  box-shadow: 0 0 12px rgba(239, 68, 68, 0.4);
}

.budget-footer {
  display: flex;
  justify-content: space-between;
  font-size: 13px;
  color: var(--text-secondary);
}

/* Listado de presupuestos */
.list-section {
  padding: 24px;
}

.card-title {
  font-size: 18px;
  font-weight: 700;
  margin-bottom: 6px;
}

.card-subtitle {
  color: var(--text-secondary);
  font-size: 13px;
  margin-bottom: 24px;
}

.budgets-list {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.budget-progress-item {
  display: flex;
  flex-direction: column;
  gap: 8px;
  background: rgba(255, 255, 255, 0.01);
  padding: 12px 16px;
  border-radius: var(--radius-sm);
  border: 1px solid rgba(255, 255, 255, 0.02);
}

.budget-item-meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.cat-label {
  display: flex;
  align-items: center;
  font-size: 15px;
}

.cat-spent {
  font-size: 14px;
  color: var(--text-secondary);
}

.budget-item-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  color: var(--text-secondary);
}

.btn-delete-budget {
  background: transparent;
  border: none;
  color: var(--text-muted);
  font-size: 16px;
  cursor: pointer;
  transition: var(--transition-smooth);
}

.btn-delete-budget:hover {
  color: var(--color-danger);
}

/* IA Sección */
.ai-optimization-section {
  padding: 24px;
  background: var(--card-bg);
  border: 1px solid var(--card-border);
}

.ai-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
}

.ai-title-wrapper {
  display: flex;
  align-items: center;
  gap: 12px;
}

.ai-spark-icon {
  width: 44px;
  height: 44px;
  background: rgba(94, 92, 230, 0.15);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-accent);
  font-size: 20px;
  animation: float 3s ease-in-out infinite;
}

.btn-ai {
  background: var(--color-accent); /* Indigo del sistema Apple */
  border: none;
  box-shadow: 0 4px 12px rgba(94, 92, 230, 0.2);
}

.ai-loading-box {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 30px;
  gap: 12px;
  color: var(--text-secondary);
}

.spinner {
  width: 36px;
  height: 36px;
  border: 3px solid rgba(94, 92, 230, 0.15);
  border-top-color: var(--color-accent);
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

.ai-results-box {
  margin-top: 24px;
  background: var(--bg-primary);
  border: 1px solid var(--card-border);
  border-radius: var(--radius-md);
  padding: 20px;
  animation: slideDown 0.3s ease-out;
}

.formatted-text {
  white-space: pre-line;
  line-height: 1.6;
  font-size: 14px;
}

.proposed-budgets-section {
  margin-top: 24px;
  border-top: 1px solid var(--card-border);
  padding-top: 20px;
}

.proposed-budgets-section h4 {
  font-size: 15px;
  font-weight: 700;
  margin-bottom: 12px;
}

.proposal-table {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-width: 400px;
  margin-bottom: 20px;
}

.proposal-header-row, .proposal-row {
  display: flex;
  justify-content: space-between;
  padding: 8px 12px;
  font-size: 14px;
}

.proposal-header-row {
  border-bottom: 1px solid var(--card-border);
  color: var(--text-secondary);
  font-size: 12px;
  text-transform: uppercase;
  font-weight: 600;
}

.proposal-row {
  background: rgba(255,255,255,0.02);
  border-radius: 6px;
}

.proposal-actions {
  display: flex;
  gap: 12px;
}

/* Modal */
.modal-overlay {
  position: fixed;
  top: 0;
  bottom: 0;
  left: 0;
  right: 0;
  background: rgba(0, 0, 0, 0.7);
  backdrop-filter: blur(8px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 20px;
}

.modal-content {
  width: 100%;
  max-width: 440px;
  border-radius: var(--radius-lg);
  padding: 24px;
  animation: scaleIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  border-bottom: 1px solid var(--card-border);
  padding-bottom: 12px;
}

.modal-header h3 {
  font-size: 20px;
  font-weight: 700;
}

.btn-close {
  background: transparent;
  border: none;
  font-size: 24px;
  color: var(--text-secondary);
  cursor: pointer;
}

.modal-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.input-help-text {
  font-size: 11px;
  color: var(--text-muted);
  margin-top: 4px;
  display: block;
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

.error-msg {
  color: var(--color-danger);
  background: rgba(239, 68, 68, 0.1);
  padding: 10px 14px;
  border-radius: var(--radius-sm);
  border: 1px solid rgba(239, 68, 68, 0.2);
  font-size: 14px;
  text-align: center;
}

.empty-state {
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  height: 180px;
  color: var(--text-muted);
  font-size: 14px;
  border: 1px dashed var(--card-border);
  border-radius: var(--radius-md);
  gap: 12px;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

@keyframes float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-6deg); }
}

@keyframes scaleIn {
  from { transform: scale(0.9); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes slideDown {
  from { transform: translateY(-10px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}

.budget-alert-banner {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  border-radius: var(--radius-sm);
  font-size: 13.5px;
  margin-top: 16px;
  line-height: 1.4;
  animation: slideDown 0.3s ease-out;
}

.budget-alert-banner.danger {
  background: rgba(255, 69, 58, 0.08);
  border: 1px solid rgba(255, 69, 58, 0.2);
  color: var(--color-danger);
}

.budget-alert-banner.warning {
  background: rgba(255, 159, 10, 0.08);
  border: 1px solid rgba(255, 159, 10, 0.2);
  color: var(--color-warning);
}

.alert-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 8px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.alert-badge.danger {
  background: rgba(255, 69, 58, 0.1);
  color: var(--color-danger);
}

.alert-badge.warning {
  background: rgba(255, 159, 10, 0.1);
  color: var(--color-warning);
}
</style>
