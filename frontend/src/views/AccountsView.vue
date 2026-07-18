<template>
  <div class="accounts-container">
    <div class="view-header">
      <h1 class="view-title">Mis Cuentas y Metas</h1>
      <p class="view-subtitle">Administra tus saldos, tarjetas de crédito y objetivos de ahorro</p>
    </div>

    <!-- Botones para abrir modales -->
    <div class="action-buttons">
      <button class="btn-primary" @click="openAccountModal">
        <i class="fa-solid fa-plus"></i> Nueva Cuenta
      </button>

      <button class="btn-success" @click="openGoalModal">
        <i class="fa-solid fa-bullseye"></i> Nueva Meta de Ahorro
      </button>
    </div>

    <!-- Sección de Cuentas -->
    <div class="section-container">
      <h2 class="section-title">Cuentas Activas</h2>
      <div v-if="accounts.length === 0" class="empty-state">
        <p>No tienes cuentas registradas.</p>
      </div>

      <div v-else class="accounts-grid">
        <div v-for="acc in accounts" :key="acc.id" class="glass-card account-card" :class="acc.type">
          <!-- Logo/Chip de tarjeta si es TC -->
          <div class="card-chip" v-if="acc.type === 'tarjeta_credito'"></div>
          
          <div class="account-card-header">
            <span class="account-type-badge">{{ translateType(acc.type) }}</span>
            <div class="header-right-badges">
              <span class="tax-exempt-badge" v-if="acc.tax_exempt === 1" title="Esta cuenta no cobra el impuesto del 4x1000">
                <i class="fa-solid fa-percent"></i> Exenta 4x1000
              </span>
              <button class="btn-delete-acc" @click="startEditAccount(acc)" title="Editar cuenta" style="margin-right: 4px;">
                <i class="fa-solid fa-pen"></i>
              </button>
              <button class="btn-delete-acc" @click="deleteAccount(acc.id)" title="Eliminar cuenta">
                <i class="fa-solid fa-trash-can"></i>
              </button>
            </div>
          </div>

          <div class="account-card-body">
            <h3 class="account-name">{{ acc.name }}</h3>
            <!-- Nombre del banco e info si existe -->
            <p class="account-bank-info" v-if="acc.bank_name || acc.account_number">
              <span v-if="acc.bank_name"><i class="fa-solid fa-building-columns"></i> {{ acc.bank_name }}</span>
              <span v-if="acc.account_number"> | No. {{ acc.account_number }}</span>
            </p>
            
            <div class="balance-section">
              <span class="balance-label">{{ (acc.type === 'tarjeta_credito' || acc.type === 'prestamo_pagar') ? 'Deuda Restante' : 'Saldo Disponible' }}</span>
              <h2 class="account-balance" :class="(acc.type === 'tarjeta_credito' || acc.type === 'prestamo_pagar') ? 'amount-negative' : 'amount-positive'">
                {{ formatCurrency(acc.balance) }}
              </h2>
            </div>

            <!-- Detalles extra para tarjetas de crédito -->
            <div class="credit-card-details" v-if="acc.type === 'tarjeta_credito'">
              <div class="detail-row">
                <span>Cupo Límite:</span>
                <span class="detail-val">{{ formatCurrency(acc.credit_limit) }}</span>
              </div>
              <div class="detail-row">
                <span>Cupo Disp.:</span>
                <span class="detail-val">{{ formatCurrency(acc.credit_limit + parseFloat(acc.balance)) }}</span>
              </div>
              <div class="detail-row info-dates">
                <span>Corte: Día {{ acc.billing_day }}</span>
                <span>Pago: Día {{ acc.due_day }}</span>
              </div>
            </div>

            <!-- Detalles extra para Préstamos por Pagar -->
            <div class="credit-card-details loan-details" v-if="acc.type === 'prestamo_pagar'">
              <div class="detail-row" v-if="acc.interest_rate">
                <span>Tasa Interés:</span>
                <span class="detail-val">{{ acc.interest_rate }}</span>
              </div>
              <div class="detail-row" v-if="acc.term_months">
                <span>Plazo:</span>
                <span class="detail-val">{{ acc.term_months }} meses</span>
              </div>
              <div class="detail-row" v-if="acc.payment_conditions">
                <span>Condiciones:</span>
                <span class="detail-val notes-val">{{ acc.payment_conditions }}</span>
              </div>
              
              <!-- Botón Asesor IA para este préstamo -->
              <button type="button" class="btn-ai-loan" @click="consultLoanIA(acc)">
                <i class="fa-solid fa-sparkles"></i> Asesor IA Préstamo
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Sección de Metas de Ahorro -->
    <div class="section-container goals-section">
      <h2 class="section-title">Objetivos de Ahorro</h2>
      
      <div v-if="goals.length === 0" class="empty-state">
        <p>No tienes metas de ahorro definidas actualmente.</p>
      </div>

      <div v-else class="goals-grid">
        <div v-for="goal in goals" :key="goal.id" class="glass-card goal-card">
          <div class="goal-header">
            <div>
              <h3 class="goal-title">{{ goal.name }}</h3>
              <!-- Mostrar cuenta vinculada -->
              <p class="goal-linked-account" v-if="goal.account_id">
                <i class="fa-solid fa-piggy-bank"></i> Ahorrado en: 
                <strong>{{ goal.account_name }}</strong> 
                <span v-if="goal.bank_name"> ({{ goal.bank_name }})</span>
              </p>
            </div>
            <div style="display: flex; gap: 6px;">
              <button class="btn-delete-acc" @click="startEditGoal(goal)" title="Editar meta">
                <i class="fa-solid fa-pen"></i>
              </button>
              <button class="btn-delete-acc" @click="deleteGoal(goal.id)" title="Eliminar meta">
                <i class="fa-solid fa-trash-can"></i>
              </button>
            </div>
          </div>

          <div class="goal-progress-section">
            <div class="goal-amounts">
              <span>{{ formatCurrency(goal.current_amount) }}</span>
              <span class="text-muted">de {{ formatCurrency(goal.target_amount) }}</span>
            </div>

            <div class="progress-bar-bg">
              <div class="progress-bar-fill" :style="{ width: getPercentage(goal) + '%', backgroundColor: 'var(--color-secondary)' }"></div>
            </div>
            
            <div class="goal-footer">
              <span class="percent-label">{{ getPercentage(goal) }}% completado</span>
              <span class="date-label" v-if="goal.target_date">Meta: {{ formatDate(goal.target_date) }}</span>
            </div>
          </div>

          <!-- Acción rápida: Ahorrar dinero -->
          <div class="save-action-form">
            <input type="number" placeholder="Monto a abonar" v-model.number="fundAmount[goal.id]" class="input-inline" min="1" />
            <button class="btn-success btn-inline" @click="addFunds(goal.id)" :disabled="btnFunding[goal.id]">
              Abonar
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- MODAL DE CUENTA -->
    <div v-if="showAccountModal" class="modal-overlay" @click.self="closeAccountModal">
      <div class="glass-card modal-content">
        <div class="modal-header">
          <h3>{{ editingAccount ? 'Editar Cuenta' : 'Nueva Cuenta' }}</h3>
          <button class="btn-close" @click="closeAccountModal">&times;</button>
        </div>

        <form @submit.prevent="saveAccount" class="modal-form">
          <div class="form-group">
            <label for="acc-name">Nombre de la Cuenta</label>
            <input type="text" id="acc-name" v-model="accountForm.name" placeholder="Ej: Cuenta de Ahorros, Visa Oro, Efectivo" required />
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="acc-bank-name">Nombre del Banco (Opcional)</label>
              <input type="text" id="acc-bank-name" v-model="accountForm.bank_name" placeholder="Ej: Bancolombia, Davivienda" />
            </div>

            <div class="form-group">
              <label for="acc-number">Número de Cuenta / Identificador (Opcional)</label>
              <input type="text" id="acc-number" v-model="accountForm.account_number" placeholder="Ej: 123-45678-90" />
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="acc-type">Tipo de Cuenta</label>
              <select id="acc-type" v-model="accountForm.type" required>
                <option value="banco">Cuenta Bancaria / Ahorro</option>
                <option value="efectivo">Efectivo / Monedero</option>
                <option value="tarjeta_credito">Tarjeta de Crédito</option>
                <option value="prestamo_pagar">Préstamo por Pagar (Deuda)</option>
                <option value="otro">Otro (Inversiones, etc.)</option>
              </select>
            </div>

            <div class="form-group">
              <label for="acc-balance">{{ (accountForm.type === 'tarjeta_credito' || accountForm.type === 'prestamo_pagar') ? 'Deuda Inicial' : 'Saldo Inicial' }}</label>
              <input type="number" id="acc-balance" v-model.number="accountForm.balance" placeholder="0" required />
            </div>
          </div>

          <!-- Checkbox de exención de 4x1000 (Solo para cuentas bancarias) -->
          <div class="form-group checkbox-group" v-if="accountForm.type === 'banco'">
            <label class="checkbox-label">
              <input type="checkbox" v-model="accountForm.tax_exempt" :true-value="1" :false-value="0" />
              <span>¿Esta cuenta está exenta del impuesto del 4x1000 (GMF)?</span>
            </label>
          </div>

          <!-- Campos adicionales si es tarjeta de crédito -->
          <div v-if="accountForm.type === 'tarjeta_credito'" class="credit-card-fields-wrapper">
            <div class="form-group">
              <label for="acc-limit">Cupo Límite de Crédito</label>
              <input type="number" id="acc-limit" v-model.number="accountForm.credit_limit" placeholder="0" required />
            </div>
            
            <div class="form-row">
              <div class="form-group">
                <label for="acc-billing">Día de Corte (1-31)</label>
                <input type="number" id="acc-billing" v-model.number="accountForm.billing_day" placeholder="15" min="1" max="31" required />
              </div>
              <div class="form-group">
                <label for="acc-due">Día de Pago (1-31)</label>
                <input type="number" id="acc-due" v-model.number="accountForm.due_day" placeholder="5" min="1" max="31" required />
              </div>
            </div>
          </div>

          <!-- Campos adicionales si es préstamo por pagar -->
          <div v-if="accountForm.type === 'prestamo_pagar'" class="credit-card-fields-wrapper">
            <div class="form-row">
              <div class="form-group">
                <label for="acc-interest">Tasa de Interés</label>
                <input type="text" id="acc-interest" v-model="accountForm.interest_rate" placeholder="Ej: 2.5% mensual, 12% E.A." />
              </div>
              <div class="form-group">
                <label for="acc-term">Plazo en Meses (Opcional)</label>
                <input type="number" id="acc-term" v-model.number="accountForm.term_months" placeholder="Ej: 12, 24" min="1" />
              </div>
            </div>
            <div class="form-group">
              <label for="acc-conditions">Condiciones de Pago / Notas</label>
              <textarea id="acc-conditions" v-model="accountForm.payment_conditions" placeholder="Ej: Pago el día 15 de cada mes. Intereses corrientes." rows="2" style="font-family: var(--font-main);"></textarea>
            </div>
          </div>

          <div v-if="accountError" class="error-msg">{{ accountError }}</div>

          <button type="submit" class="btn-primary" :disabled="accountFormLoading">
            {{ accountFormLoading ? 'Guardando...' : (editingAccount ? 'Actualizar Cuenta' : 'Crear Cuenta') }}
          </button>
        </form>
      </div>
    </div>

    <!-- MODAL DE META -->
    <div v-if="showGoalModal" class="modal-overlay" @click.self="closeGoalModal">
      <div class="glass-card modal-content">
        <div class="modal-header">
          <h3>{{ editingGoal ? 'Editar Meta de Ahorro' : 'Nueva Meta de Ahorro' }}</h3>
          <button class="btn-close" @click="closeGoalModal">&times;</button>
        </div>

        <form @submit.prevent="saveGoal" class="modal-form">
          <div class="form-group">
            <label for="goal-name">Nombre de la Meta</label>
            <input type="text" id="goal-name" v-model="goalForm.name" placeholder="Ej: Viaje a Europa, Computador nuevo" required />
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="goal-target">Monto Objetivo (COP)</label>
              <input type="number" id="goal-target" v-model.number="goalForm.target_amount" placeholder="0" required min="1" />
            </div>

            <div class="form-group">
              <label for="goal-current">Monto Inicial Ahorrado</label>
              <input type="number" id="goal-current" v-model.number="goalForm.current_amount" placeholder="0" min="0" />
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="goal-account">¿Dónde se ahorra este dinero?</label>
              <select id="goal-account" v-model="goalForm.account_id">
                <option value="">Ninguna cuenta vinculada (General)</option>
                <option v-for="acc in accounts" :key="acc.id" :value="acc.id">
                  {{ acc.name }} <span v-if="acc.bank_name">({{ acc.bank_name }})</span>
                </option>
              </select>
            </div>

            <div class="form-group">
              <label for="goal-date">Fecha Límite (Opcional)</label>
              <input type="date" id="goal-date" v-model="goalForm.target_date" />
            </div>
          </div>

          <div v-if="goalError" class="error-msg">{{ goalError }}</div>

          <button type="submit" class="btn-primary" :disabled="goalFormLoading">
            {{ goalFormLoading ? 'Guardando...' : (editingGoal ? 'Actualizar Meta' : 'Crear Meta') }}
          </button>
        </form>
      </div>
    </div>

    <!-- MODAL DE ASESORÍA IA DEL PRÉSTAMO -->
    <div v-if="showLoanIAModal" class="modal-overlay" @click.self="closeLoanIAModal">
      <div class="glass-card modal-content loan-ai-modal" style="max-width: 550px;">
        <div class="modal-header">
          <h3 style="display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-sparkles" style="color: var(--color-accent);"></i>
            Análisis IA: {{ selectedLoanForAI?.name }}
          </h3>
          <button class="btn-close" @click="closeLoanIAModal">&times;</button>
        </div>

        <div class="modal-body" style="padding-top: 10px;">
          <div class="loan-summary-box" style="display: flex; justify-content: space-between; background: var(--bg-primary); padding: 12px 16px; border-radius: var(--radius-sm); border: 1px solid var(--card-border); margin-bottom: 20px;">
            <div class="summary-item" style="display: flex; flex-direction: column;">
              <span class="lbl" style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase;">Deuda Restante</span>
              <span class="val negative" style="font-size: 16px; font-weight: 700; color: var(--color-danger);">{{ formatCurrency(selectedLoanForAI?.balance || 0) }}</span>
            </div>
            <div class="summary-item" v-if="selectedLoanForAI?.interest_rate" style="display: flex; flex-direction: column;">
              <span class="lbl" style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase;">Tasa</span>
              <span class="val" style="font-size: 16px; font-weight: 600;">{{ selectedLoanForAI?.interest_rate }}</span>
            </div>
            <div class="summary-item" v-if="selectedLoanForAI?.term_months" style="display: flex; flex-direction: column;">
              <span class="lbl" style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase;">Plazo</span>
              <span class="val" style="font-size: 16px; font-weight: 600;">{{ selectedLoanForAI?.term_months }} meses</span>
            </div>
          </div>

          <div v-if="loanAILoading" class="loan-ai-loading" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 20px; gap: 16px; color: var(--text-secondary);">
            <div class="spinner" style="width: 32px; height: 32px; border: 3px solid rgba(94, 92, 230, 0.15); border-top-color: var(--color-accent); border-radius: 50%; animation: spin 1s linear infinite;"></div>
            <p style="text-align: center; font-size: 14px;">Gemini está analizando tu préstamo y calculando consejos para ahorrar intereses...</p>
          </div>

          <div v-else-if="loanAIError" class="error-msg" style="padding: 12px; margin-bottom: 0;">
            {{ loanAIError }}
          </div>

          <div v-else class="loan-ai-response-content formatted-text" style="font-size: 14px; line-height: 1.6; max-height: 350px; overflow-y: auto;" v-html="formattedLoanAIResult">
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, onMounted, computed } from 'vue'
import { API_BASE } from '../config.js'

export default {
  name: 'AccountsView',
  setup() {
    const accounts = ref([])
    const goals = ref([])

    // Estados modales
    const showAccountModal = ref(false)
    const showGoalModal = ref(false)
    const editingAccount = ref(null)
    const editingGoal = ref(null)
    
    const accountFormLoading = ref(false)
    const goalFormLoading = ref(false)

    const accountError = ref('')
    const goalError = ref('')

    // Formularios vacíos
    const accountForm = ref({
      name: '',
      type: 'banco',
      balance: '',
      credit_limit: '',
      billing_day: '',
      due_day: '',
      bank_name: '',
      account_number: '',
      tax_exempt: 0,
      interest_rate: '',
      term_months: '',
      payment_conditions: ''
    })

    const goalForm = ref({
      name: '',
      target_amount: '',
      current_amount: 0,
      target_date: '',
      account_id: ''
    })

    // Inputs dinámicos para abonos
    const fundAmount = ref({})
    const btnFunding = ref({})

    const fetchAccounts = async () => {
      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/accounts.php`, {
          headers: { 'Authorization': `Bearer ${token}` }
        })
        accounts.value = await response.json()
      } catch (err) {
        console.error('Error al cargar cuentas:', err)
      }
    }

    const fetchGoals = async () => {
      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/savings.php`, {
          headers: { 'Authorization': `Bearer ${token}` }
        })
        goals.value = await response.json()
        
        // Inicializar inputs de abono
        goals.value.forEach(g => {
          if (!fundAmount.value[g.id]) {
            fundAmount.value[g.id] = ''
          }
        })
      } catch (err) {
        console.error('Error al cargar metas de ahorro:', err)
      }
    }

    const deleteAccount = async (id) => {
      if (!confirm('¿Estás seguro de eliminar esta cuenta? Se eliminarán todas sus transacciones asociadas.')) return
      
      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/accounts.php?id=${id}`, {
          method: 'DELETE',
          headers: { 'Authorization': `Bearer ${token}` }
        })

        if (!response.ok) {
          const data = await response.json()
          throw new Error(data.error || 'Error al eliminar la cuenta.')
        }

        await fetchAccounts()
        await fetchGoals() // Recargar metas por si estaban vinculadas
      } catch (err) {
        alert(err.message)
      }
    }

    const deleteGoal = async (id) => {
      if (!confirm('¿Estás seguro de eliminar esta meta?')) return
      
      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/savings.php?id=${id}`, {
          method: 'DELETE',
          headers: { 'Authorization': `Bearer ${token}` }
        })

        if (!response.ok) {
          throw new Error('Error al eliminar la meta.')
        }

        await fetchGoals()
      } catch (err) {
        alert(err.message)
      }
    }

    const addFunds = async (id) => {
      const amount = fundAmount.value[id]
      if (!amount || amount <= 0) return

      btnFunding.value[id] = true
      const token = localStorage.getItem('token')
      
      try {
        const response = await fetch(`${API_BASE}/savings.php?id=${id}&action=add_funds`, {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`
          },
          body: JSON.stringify({ amount })
        })

        if (!response.ok) {
          const data = await response.json()
          throw new Error(data.error || 'Error al abonar.')
        }

        fundAmount.value[id] = ''
        await fetchGoals()
      } catch (err) {
        alert(err.message)
      } finally {
        btnFunding.value[id] = false
      }
    }

    // Modal Helpers
    // Modal de Asesoría IA de Préstamo
    const showLoanIAModal = ref(false)
    const selectedLoanForAI = ref(null)
    const loanAILoading = ref(false)
    const loanAIError = ref('')
    const loanAIResult = ref('')

    const consultLoanIA = async (acc) => {
      selectedLoanForAI.value = acc
      showLoanIAModal.value = true
      loanAILoading.value = true
      loanAIError.value = ''
      loanAIResult.value = ''

      const token = localStorage.getItem('token')
      const customApiKey = localStorage.getItem('gemini_api_key') || ''
      const headers = {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      }
      if (customApiKey) {
        headers['X-Gemini-API-Key'] = customApiKey
      }

      const promptText = `Eres un asesor financiero experto. Analiza el siguiente préstamo que tengo por pagar:
- Nombre del préstamo: ${acc.name}
- Deuda restante: ${formatCurrency(acc.balance)} COP
- Entidad/Banco: ${acc.bank_name || 'No especificado'}
- Tasa de interés: ${acc.interest_rate || 'No especificada'}
- Plazo: ${acc.term_months ? acc.term_months + ' meses' : 'No especificado'}
- Condiciones: ${acc.payment_conditions || 'Ninguna nota adicional'}

Dame un análisis financiero y 3 o 4 consejos específicos sobre cómo puedo pagarlo más rápido (por ejemplo, amortizar capital, refinanciar o renegociar tasa). Mantén la respuesta con formato limpio de viñetas, amigable, directa y muy estructurada.`

      try {
        const response = await fetch(`${API_BASE}/ai.php?action=get_advice`, {
          method: 'POST',
          headers: headers,
          body: JSON.stringify({ prompt: promptText })
        })

        const data = await response.json()
        if (!response.ok) {
          throw new Error(data.error || 'Error al obtener análisis de la IA.')
        }

        loanAIResult.value = data.response
      } catch (err) {
        loanAIError.value = err.message
      } finally {
        loanAILoading.value = false
      }
    }

    const closeLoanIAModal = () => {
      showLoanIAModal.value = false
      selectedLoanForAI.value = null
    }

    const formattedLoanAIResult = computed(() => {
      if (!loanAIResult.value) return ''
      return loanAIResult.value
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.*?)\*/g, '<em>$1</em>')
        .replace(/^- (.*)$/gm, '<li>$1</li>')
        .replace(/^\* (.*)$/gm, '<li>$1</li>')
        .replace(/\n/g, '<br />')
    })

    const openAccountModal = () => {
      editingAccount.value = null
      accountForm.value = {
        name: '',
        type: 'banco',
        balance: '',
        credit_limit: '',
        billing_day: '',
        due_day: '',
        bank_name: '',
        account_number: '',
        tax_exempt: 0,
        interest_rate: '',
        term_months: '',
        payment_conditions: ''
      }
      accountError.value = ''
      showAccountModal.value = true
    }

    const closeAccountModal = () => {
      showAccountModal.value = false
      editingAccount.value = null
    }

    const startEditAccount = (acc) => {
      editingAccount.value = acc
      accountForm.value = {
        name: acc.name,
        type: acc.type,
        balance: Math.abs(acc.balance),
        credit_limit: acc.credit_limit,
        billing_day: acc.billing_day,
        due_day: acc.due_day,
        bank_name: acc.bank_name || '',
        account_number: acc.account_number || '',
        tax_exempt: acc.tax_exempt,
        interest_rate: acc.interest_rate || '',
        term_months: acc.term_months || '',
        payment_conditions: acc.payment_conditions || ''
      }
      accountError.value = ''
      showAccountModal.value = true
    }

    const saveAccount = async () => {
      accountFormLoading.value = true
      accountError.value = ''

      const token = localStorage.getItem('token')
      const isEdit = !!editingAccount.value
      
      let sendBalance = accountForm.value.balance
      if (accountForm.value.type === 'tarjeta_credito' || accountForm.value.type === 'prestamo_pagar') {
        sendBalance = -Math.abs(sendBalance)
      }

      const url = isEdit 
        ? `${API_BASE}/accounts.php?id=${editingAccount.value.id}`
        : `${API_BASE}/accounts.php`

      try {
        const response = await fetch(url, {
          method: isEdit ? 'PUT' : 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`
          },
          body: JSON.stringify({
            ...accountForm.value,
            balance: sendBalance
          })
        })

        const data = await response.json()

        if (!response.ok) {
          throw new Error(data.error || 'Error al guardar la cuenta.')
        }

        closeAccountModal()
        await fetchAccounts()
      } catch (err) {
        accountError.value = err.message
      } finally {
        accountFormLoading.value = false
      }
    }

    const openGoalModal = () => {
      editingGoal.value = null
      goalForm.value = {
        name: '',
        target_amount: '',
        current_amount: 0,
        target_date: '',
        account_id: ''
      }
      goalError.value = ''
      showGoalModal.value = true
    }

    const closeGoalModal = () => {
      showGoalModal.value = false
      editingGoal.value = null
    }

    const startEditGoal = (goal) => {
      editingGoal.value = goal
      goalForm.value = {
        name: goal.name,
        target_amount: goal.target_amount,
        current_amount: goal.current_amount,
        target_date: goal.target_date || '',
        account_id: goal.account_id || ''
      }
      goalError.value = ''
      showGoalModal.value = true
    }

    const saveGoal = async () => {
      goalFormLoading.value = true
      goalError.value = ''

      const token = localStorage.getItem('token')
      const isEdit = !!editingGoal.value
      const url = isEdit 
        ? `${API_BASE}/savings.php?id=${editingGoal.value.id}`
        : `${API_BASE}/savings.php`

      try {
        const response = await fetch(url, {
          method: isEdit ? 'PUT' : 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`
          },
          body: JSON.stringify({
            ...goalForm.value,
            account_id: goalForm.value.account_id === '' ? null : goalForm.value.account_id
          })
        })

        const data = await response.json()

        if (!response.ok) {
          throw new Error(data.error || 'Error al guardar la meta.')
        }

        closeGoalModal()
        await fetchGoals()
      } catch (err) {
        goalError.value = err.message
      } finally {
        goalFormLoading.value = false
      }
    }

    // Traductores y formatters
    const translateType = (type) => {
      switch (type) {
        case 'efectivo': return 'Efectivo / Monedero'
        case 'banco': return 'Banco / Ahorro'
        case 'tarjeta_credito': return 'Tarjeta de Crédito'
        case 'prestamo_pagar': return 'Préstamo por Pagar'
        default: return 'Otro'
      }
    }

    const formatCurrency = (val) => {
      const absVal = Math.abs(val)
      const formatted = new Intl.NumberFormat('es-CO', {
        style: 'currency',
        currency: 'COP',
        minimumFractionDigits: 0
      }).format(absVal)
      
      return val < 0 ? `-${formatted}` : formatted
    }

    const formatDate = (dateStr) => {
      const options = { year: 'numeric', month: 'long', day: 'numeric' }
      return new Date(dateStr + 'T00:00:00').toLocaleDateString('es-ES', options)
    }

    const getPercentage = (goal) => {
      if (goal.target_amount <= 0) return 0
      const percent = (goal.current_amount / goal.target_amount) * 100
      return Math.min(Math.round(percent), 100)
    }

    onMounted(() => {
      fetchAccounts()
      fetchGoals()
    })

    return {
      accounts,
      goals,
      showAccountModal,
      showGoalModal,
      editingAccount,
      editingGoal,
      accountFormLoading,
      goalFormLoading,
      accountError,
      goalError,
      accountForm,
      goalForm,
      fundAmount,
      btnFunding,
      openAccountModal,
      closeAccountModal,
      startEditAccount,
      saveAccount,
      openGoalModal,
      closeGoalModal,
      startEditGoal,
      saveGoal,
      deleteAccount,
      deleteGoal,
      addFunds,
      translateType,
      formatCurrency,
      formatDate,
      getPercentage,
      // Nuevos retornos de Préstamo por Pagar
      showLoanIAModal,
      selectedLoanForAI,
      loanAILoading,
      loanAIError,
      formattedLoanAIResult,
      consultLoanIA,
      closeLoanIAModal
    }
  }
}
</script>

<style scoped>
.accounts-container {
  display: flex;
  flex-direction: column;
  gap: 30px;
  animation: fadeIn 0.4s ease-out;
}

.action-buttons {
  display: flex;
  gap: 12px;
}

.section-container {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.section-title {
  font-size: 20px;
  font-weight: 700;
  border-left: 4px solid var(--color-primary);
  padding-left: 10px;
}

/* Grid de Cuentas */
.accounts-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 20px;
}

@media (min-width: 768px) {
  .accounts-grid {
    grid-template-columns: 1fr 1fr;
  }
}

@media (min-width: 1200px) {
  .accounts-grid {
    grid-template-columns: 1fr 1fr 1fr;
  }
}

.account-card {
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-height: 190px;
  overflow: hidden;
}

.account-card.tarjeta_credito {
  background: linear-gradient(135deg, rgba(28, 28, 30, 0.9), rgba(94, 92, 230, 0.2));
  border-color: rgba(94, 92, 230, 0.3);
}

.card-chip {
  position: absolute;
  top: 45px;
  left: 20px;
  width: 40px;
  height: 28px;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border-radius: 6px;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.2);
}

.account-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  z-index: 2;
}

.header-right-badges {
  display: flex;
  align-items: center;
  gap: 8px;
}

.account-type-badge {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  color: var(--text-secondary);
  background: rgba(255, 255, 255, 0.05);
  padding: 4px 8px;
  border-radius: 999px;
  letter-spacing: 0.5px;
}

.tax-exempt-badge {
  font-size: 10px;
  font-weight: 700;
  color: var(--color-secondary);
  background: rgba(100, 210, 255, 0.12);
  border: 1px solid rgba(100, 210, 255, 0.25);
  padding: 3px 8px;
  border-radius: 999px;
  display: flex;
  align-items: center;
  gap: 4px;
}

.btn-delete-acc {
  background: transparent;
  border: none;
  color: var(--text-muted);
  cursor: pointer;
  transition: var(--transition-smooth);
  padding: 4px;
  border-radius: 4px;
}

.btn-delete-acc:hover {
  color: var(--color-danger);
  background: rgba(239, 68, 68, 0.15);
}

.account-card-body {
  display: flex;
  flex-direction: column;
  gap: 8px;
  z-index: 2;
}

.account-name {
  font-size: 18px;
  font-weight: 700;
}

.account-bank-info {
  font-size: 12px;
  color: var(--text-secondary);
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: -4px;
}

.balance-section {
  display: flex;
  flex-direction: column;
  margin-top: 4px;
}

.balance-label {
  font-size: 12px;
  color: var(--text-secondary);
}

.account-balance {
  font-size: 26px;
  font-weight: 800;
}

/* Detalles específicos TC */
.credit-card-details {
  border-top: 1px dashed rgba(255, 255, 255, 0.1);
  padding-top: 8px;
  margin-top: 4px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 12px;
  color: var(--text-secondary);
}

.detail-row {
  display: flex;
  justify-content: space-between;
}

.detail-val {
  font-weight: 600;
  color: var(--text-primary);
}

.info-dates {
  margin-top: 4px;
  color: var(--color-secondary);
  font-weight: 500;
}

/* Grid de Metas */
.goals-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 20px;
}

@media (min-width: 768px) {
  .goals-grid {
    grid-template-columns: 1fr 1fr;
  }
}

.goal-card {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.goal-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

.goal-title {
  font-size: 16px;
  font-weight: 700;
}

.goal-linked-account {
  font-size: 12px;
  color: var(--color-secondary);
  margin-top: 2px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.goal-progress-section {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.goal-amounts {
  display: flex;
  justify-content: space-between;
  font-size: 14px;
  font-weight: 600;
}

.progress-bar-bg {
  width: 100%;
  height: 8px;
  background: var(--bg-tertiary);
  border-radius: 4px;
  overflow: hidden;
}

.progress-bar-fill {
  height: 100%;
  border-radius: 4px;
  transition: width 0.5s ease-out;
}

.goal-footer {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
  color: var(--text-muted);
}

/* Input inline de abono */
.save-action-form {
  display: flex;
  gap: 8px;
  border-top: 1px solid var(--card-border);
  padding-top: 14px;
  margin-top: 8px;
}

.input-inline {
  flex: 1;
  padding: 8px 12px;
  font-size: 14px;
}

.btn-inline {
  padding: 8px 16px;
  font-size: 14px;
}

.credit-card-fields-wrapper {
  background: rgba(99, 102, 241, 0.04);
  padding: 12px;
  border-radius: var(--radius-sm);
  border: 1px dashed rgba(99, 102, 241, 0.2);
  margin-bottom: 8px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.checkbox-group {
  margin: 10px 0;
  display: flex;
}

.checkbox-label {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  font-size: 14px;
  user-select: none;
  color: var(--text-secondary);
}

.checkbox-label input[type="checkbox"] {
  width: 18px;
  height: 18px;
  cursor: pointer;
}

/* Modales */
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
  max-width: 500px;
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

.form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}

@keyframes scaleIn {
  from {
    transform: scale(0.9);
    opacity: 0;
  }
  to {
    transform: scale(1);
    opacity: 1;
  }
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.empty-state {
  display: flex;
  justify-content: center;
  align-items: center;
  height: 120px;
  color: var(--text-muted);
  font-size: 14px;
  border: 1px dashed var(--card-border);
  border-radius: var(--radius-md);
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

.account-card.prestamo_pagar {
  border-left: 4px solid var(--color-danger);
}

.btn-ai-loan {
  margin-top: 14px;
  background: rgba(94, 92, 230, 0.08);
  border: 1px solid rgba(94, 92, 230, 0.2);
  color: var(--color-accent);
  border-radius: var(--radius-sm);
  padding: 8px 12px;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  width: 100%;
  transition: var(--transition-smooth);
}

.btn-ai-loan:hover {
  background: rgba(94, 92, 230, 0.15);
  border-color: var(--color-accent);
}

.notes-val {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 180px;
}
</style>
