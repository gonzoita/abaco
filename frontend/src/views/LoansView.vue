<template>
  <div class="loans-container fade-in">
    <!-- Encabezado de Vista -->
    <div class="view-header">
      <h1 class="view-title">Control de Préstamos</h1>
      <p class="view-subtitle">Gestiona préstamos otorgados a entidades, amigos o clientes y registra recaudos de cuotas</p>
    </div>

    <!-- Navegación Interna (Sub-pestañas segmentadas estilo Apple) -->
    <div class="segmented-control">
      <button 
        v-for="tab in ['loans', 'clients', 'history']" 
        :key="tab"
        class="segment-btn"
        :class="{ active: activeSubTab === tab }"
        @click="activeSubTab = tab"
      >
        <i v-if="tab === 'loans'" class="fa-solid fa-hand-holding-dollar"></i>
        <i v-else-if="tab === 'clients'" class="fa-solid fa-users"></i>
        <i v-else class="fa-solid fa-clock-rotate-left"></i>
        <span>{{ tab === 'loans' ? 'Préstamos' : tab === 'clients' ? 'Clientes' : 'Historial Cobros' }}</span>
      </button>
    </div>

    <!-- PESTAÑA 1: GESTIÓN DE PRÉSTAMOS -->
    <div v-if="activeSubTab === 'loans'" class="sub-tab-content">
      <div class="actions-row">
        <!-- Filtros de Estado -->
        <div class="filters-segment">
          <button 
            v-for="f in ['todos', 'activo', 'vencido', 'finalizado']" 
            :key="f"
            class="filter-chip"
            :class="{ active: loanFilter === f }"
            @click="loanFilter = f"
          >
            {{ f === 'todos' ? 'Todos' : f === 'activo' ? 'Activos' : f === 'vencido' ? 'En Mora' : 'Pagados' }}
          </button>
        </div>

        <button class="btn-primary" @click="openNewLoanSheet(null)">
          <i class="fa-solid fa-plus"></i> Nuevo Préstamo
        </button>
      </div>

      <!-- Buscador -->
      <div class="search-box">
        <i class="fa-solid fa-magnifying-glass search-icon"></i>
        <input 
          type="text" 
          v-model="loanSearch" 
          placeholder="Buscar préstamos por nombre de cliente..."
        />
      </div>

      <!-- Listado de Préstamos -->
      <div v-if="loading" class="loading-state">
        <i class="fa-solid fa-spinner fa-spin"></i> Cargando préstamos...
      </div>
      <div v-else-if="filteredLoans.length === 0" class="empty-card glass-card">
        <i class="fa-solid fa-circle-info info-icon"></i>
        <p>No se encontraron préstamos registrados.</p>
      </div>
      <div v-else class="loans-list">
        <div 
          v-for="l in filteredLoans" 
          :key="l.id" 
          class="glass-card loan-card"
          @click="openLoanDetails(l.id)"
        >
          <div class="loan-card-header">
            <div>
              <h3 class="client-name">{{ l.client_name }}</h3>
              <span class="loan-method">{{ l.method.toUpperCase() }} • {{ l.frequency }}</span>
            </div>
            <span :class="'badge-loan status-' + l.status">
              {{ l.status === 'activo' ? 'Activo' : l.status === 'vencido' ? 'Mora' : 'Pagado' }}
            </span>
          </div>

          <div class="loan-card-details">
            <div class="detail-col">
              <span class="label">Capital</span>
              <span class="val">{{ formatCOP(l.principal) }}</span>
            </div>
            <div class="detail-col">
              <span class="label">Tasa</span>
              <span class="val">{{ l.interest_rate }}% ({{ l.rate_type }})</span>
            </div>
            <div class="detail-col">
              <span class="label">Saldo Pendiente</span>
              <span class="val balance" :class="{ 'mora-text': l.status === 'vencido' }">
                {{ formatCOP(l.remaining_balance) }}
              </span>
            </div>
          </div>
          
          <div class="loan-card-footer">
            <span>Cuotas: {{ l.installments_count }} • Inicio: {{ formatDate(l.start_date) }}</span>
            <span class="view-link">Ver plan <i class="fa-solid fa-chevron-right"></i></span>
          </div>
        </div>
      </div>
    </div>

    <!-- PESTAÑA 2: GESTIÓN DE CLIENTES -->
    <div v-if="activeSubTab === 'clients'" class="sub-tab-content">
      <div class="actions-row">
        <div class="search-box" style="flex: 1; margin-bottom: 0;">
          <i class="fa-solid fa-magnifying-glass search-icon"></i>
          <input 
            type="text" 
            v-model="clientSearch" 
            placeholder="Buscar por nombre, documento o teléfono..."
          />
        </div>
        <button class="btn-primary" @click="openClientSheet(null)">
          <i class="fa-solid fa-user-plus"></i> Nuevo Cliente
        </button>
      </div>

      <!-- Listado de Clientes -->
      <div v-if="loading" class="loading-state">
        <i class="fa-solid fa-spinner fa-spin"></i> Cargando directorio...
      </div>
      <div v-else-if="filteredClients.length === 0" class="empty-card glass-card">
        <i class="fa-solid fa-user-slash info-icon"></i>
        <p>No se encontraron clientes registrados.</p>
      </div>
      <div v-else class="clients-accordion">
        <div 
          v-for="c in filteredClients" 
          :key="c.id" 
          class="glass-card client-accordion-card"
        >
          <!-- Fila principal -->
          <div class="accordion-header" @click="toggleClientExpand(c.id)">
            <div class="client-meta-info">
              <div class="client-avatar">
                <i class="fa-solid fa-user"></i>
              </div>
              <div>
                <h3 class="client-accordion-name">{{ c.name }}</h3>
                <span class="client-accordion-sub">C.C. {{ c.document }} • Tel: {{ c.phone }}</span>
              </div>
            </div>
            <i 
              class="fa-solid fa-chevron-down arrow-icon" 
              :class="{ rotated: expandedClientId === c.id }"
            ></i>
          </div>

          <!-- Contenido del colapso -->
          <div v-if="expandedClientId === c.id" class="accordion-body fade-in">
            <div class="client-additional-info">
              <p><strong>Correo:</strong> {{ c.email || 'No registrado' }}</p>
              <p><strong>Dirección:</strong> {{ c.address || 'No registrada' }}</p>
            </div>

            <!-- Préstamos del cliente -->
            <div class="client-loans-list">
              <h4 class="section-label">Historial de Financiamiento</h4>
              
              <div v-if="getClientLoans(c.id).length === 0" class="no-loans-text">
                Este cliente no posee préstamos registrados.
              </div>
              <div v-else class="client-loans-grid">
                <div 
                  v-for="l in getClientLoans(c.id)" 
                  :key="l.id" 
                  class="client-loan-mini-card"
                  @click="openLoanDetails(l.id)"
                >
                  <div class="mini-card-header">
                    <span>{{ l.method.toUpperCase() }} • {{ formatCOP(l.principal) }}</span>
                    <span :class="'mini-badge status-' + l.status">
                      {{ l.status === 'activo' ? 'Activo' : l.status === 'vencido' ? 'Mora' : 'Pagado' }}
                    </span>
                  </div>
                  <span class="mini-card-sub">
                    Interés: {{ l.interest_rate }}% • Cuotas: {{ l.installments_count }}
                  </span>
                </div>
              </div>
            </div>

            <!-- Acciones -->
            <div class="client-actions-footer">
              <button class="btn-secondary btn-small" @click.stop="openClientSheet(c)">
                <i class="fa-solid fa-user-pen"></i> Editar
              </button>
              <button class="btn-primary btn-small" @click.stop="openNewLoanSheet(c.id)">
                <i class="fa-solid fa-plus"></i> Nuevo Préstamo
              </button>
              <button 
                v-if="canDeleteClient(c.id)" 
                class="btn-danger-outline btn-small" 
                @click.stop="handleDeleteClient(c.id)"
              >
                <i class="fa-solid fa-trash-can"></i> Eliminar
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- PESTAÑA 3: HISTORIAL GENERAL DE COBROS -->
    <div v-if="activeSubTab === 'history'" class="sub-tab-content">
      <div class="search-box">
        <i class="fa-solid fa-magnifying-glass search-icon"></i>
        <input 
          type="text" 
          v-model="historySearch" 
          placeholder="Buscar cobros por cliente o notas..."
        />
      </div>

      <!-- Listado Recaudos -->
      <div v-if="loading" class="loading-state">
        <i class="fa-solid fa-spinner fa-spin"></i> Cargando historial...
      </div>
      <div v-else-if="filteredTransactions.length === 0" class="empty-card glass-card">
        <i class="fa-solid fa-clock-rotate-left info-icon"></i>
        <p>No se encontraron registros de cobros.</p>
      </div>
      <div v-else class="glass-card transactions-history-card" style="padding: 10px 0;">
        <div class="tx-history-header">
          <span>Cliente / Cuota</span>
          <span style="text-align: right; padding-right: 15px;">Monto Recibido</span>
        </div>
        <div class="tx-history-list">
          <div 
            v-for="t in filteredTransactions" 
            :key="t.id" 
            class="tx-history-item"
            @click="openReceipt(t)"
          >
            <div class="tx-item-left">
              <strong class="tx-client">{{ t.client_name }}</strong>
              <span class="tx-sub">Cuota #{{ t.installment_number }} • {{ formatDate(t.date) }}</span>
              <span class="tx-note" v-if="t.note">{{ t.note }}</span>
            </div>
            <div class="tx-item-right">
              <span class="tx-amount">+ {{ formatCOP(t.amount) }}</span>
              <i class="fa-solid fa-chevron-right chevron"></i>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- HOJA DESLIZANTE / BOTTOM SHEET: DETALLES DE PRÉSTAMO Y CRONOGRAMA -->
    <div class="modal-overlay" :class="{ active: detailsModalOpen }" @click.self="detailsModalOpen = false">
      <div class="bottom-sheet" :class="{ active: detailsModalOpen }">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
          <span class="sheet-title">Plan de Amortización</span>
          <button class="sheet-close" @click="detailsModalOpen = false">Cerrar</button>
        </div>

        <div v-if="selectedLoan" class="sheet-body">
          <div class="loan-profile-header">
            <div>
              <h2 class="profile-title">{{ selectedLoan.client_name }}</h2>
              <p class="profile-sub">{{ selectedLoan.method.toUpperCase() }} • Frecuencia {{ selectedLoan.frequency }}</p>
            </div>
            <span :class="'badge-loan status-' + selectedLoan.status">
              {{ selectedLoan.status === 'activo' ? 'Activo' : selectedLoan.status === 'vencido' ? 'Mora' : 'Pagado' }}
            </span>
          </div>

          <!-- Resumen Numérico -->
          <div class="loan-profile-summary-grid">
            <div class="summary-box">
              <span class="sum-label">Capital Inicial</span>
              <span class="sum-val">{{ formatCOP(selectedLoan.principal) }}</span>
            </div>
            <div class="summary-box">
              <span class="sum-label">Total Cobrado</span>
              <span class="sum-val success">{{ formatCOP(selectedLoan.total_paid) }}</span>
            </div>
            <div class="summary-box">
              <span class="sum-label">Saldo Restante</span>
              <span class="sum-val" :class="{ danger: selectedLoan.status === 'vencido' }">
                {{ formatCOP(selectedLoan.remaining_balance) }}
              </span>
            </div>
          </div>

          <!-- Ficha de Datos Adicionales -->
          <div class="loan-details-metadata glass-card">
            <div class="meta-row">
              <span>Tasa de Interés:</span>
              <strong>{{ selectedLoan.interest_rate }}% {{ selectedLoan.rate_type }}</strong>
            </div>
            <div class="meta-row">
              <span>Fecha Desembolso:</span>
              <strong>{{ formatDate(selectedLoan.start_date) }}</strong>
            </div>
            <div class="meta-row">
              <span>Plazo de Cuotas:</span>
              <strong>{{ selectedLoan.installments_count }} cuotas</strong>
            </div>
          </div>

          <!-- Listado de Cuotas / Cronograma -->
          <h3 class="sheet-section-title">Cronograma de Pagos</h3>
          
          <div class="table-container">
            <table class="schedule-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Vence</th>
                  <th style="text-align: right;">Cuota</th>
                  <th style="text-align: right;">Cobrado</th>
                  <th>Estado</th>
                  <th>Acción</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="inst in selectedLoan.schedule" :key="inst.id">
                  <td>#{{ inst.number }}</td>
                  <td>{{ formatDate(inst.date) }}</td>
                  <td style="text-align: right; font-weight: 400;">{{ formatCOP(inst.installment) }}</td>
                  <td style="text-align: right; color: var(--color-success);">{{ formatCOP(inst.paid_amount) }}</td>
                  <td>
                    <span :class="'mini-badge status-' + inst.status">
                      {{ inst.status === 'pagado' ? 'Paga' : inst.status === 'parcial' ? 'Parcial' : (inst.date < todayStr) ? 'Atrasada' : 'Pend' }}
                    </span>
                  </td>
                  <td>
                    <button 
                      v-if="inst.status !== 'pagado'" 
                      class="btn-collect-action" 
                      @click="triggerPaymentCollect(inst)"
                    >
                      Cobrar
                    </button>
                    <span v-else class="text-completed"><i class="fa-solid fa-circle-check"></i></span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Botones de administración -->
          <div class="details-admin-row">
            <button class="btn-danger-outline btn-small" @click="handleDeleteLoan(selectedLoan.id)">
              <i class="fa-solid fa-trash-can"></i> Eliminar Préstamo
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- HOJA DESLIZANTE / BOTTOM SHEET: CREAR NUEVO PRÉSTAMO CON SIMULADOR -->
    <div class="modal-overlay" :class="{ active: newLoanModalOpen }" @click.self="newLoanModalOpen = false">
      <div class="bottom-sheet" :class="{ active: newLoanModalOpen }" style="max-width: 600px;">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
          <span class="sheet-title">Nuevo Préstamo</span>
          <button class="sheet-close" @click="newLoanModalOpen = false">Cerrar</button>
        </div>

        <div class="sheet-body">
          <form @submit.prevent="submitNewLoan" class="new-loan-form">
            <!-- Selección de Cliente -->
            <div class="form-group">
              <label>Cliente Beneficiario</label>
              <select v-model="loanForm.client_id" required>
                <option value="">Seleccione un cliente...</option>
                <option v-for="c in clients" :key="c.id" :value="c.id">
                  {{ c.name }} ({{ c.document }})
                </option>
              </select>
            </div>

            <!-- Capital Principal y Tasa -->
            <div class="form-row-2">
              <div class="form-group">
                <label>Capital Solicitado ($)</label>
                <input 
                  type="number" 
                  v-model.number="loanForm.principal" 
                  placeholder="Ej. 1000000" 
                  min="5000"
                  required
                />
              </div>
              <div class="form-group">
                <label>Tasa de Interés (%)</label>
                <input 
                  type="number" 
                  v-model.number="loanForm.interest_rate" 
                  placeholder="Ej. 8" 
                  step="0.1" 
                  min="0"
                  required
                />
              </div>
            </div>

            <!-- Tipo de Tasa y Frecuencia -->
            <div class="form-row-2">
              <div class="form-group">
                <label>Tipo de Tasa</label>
                <select v-model="loanForm.rate_type">
                  <option value="mensual">Mensual Nominal</option>
                  <option value="periodo">Fija por Cuota</option>
                  <option value="anual">Anual Nominal</option>
                </select>
              </div>
              <div class="form-group">
                <label>Frecuencia de Pago</label>
                <select v-model="loanForm.frequency">
                  <option value="diario">Diario</option>
                  <option value="semanal">Semanal</option>
                  <option value="quincenal">Quincenal</option>
                  <option value="mensual">Mensual</option>
                </select>
              </div>
            </div>

            <!-- Cuotas e Inicio -->
            <div class="form-row-2">
              <div class="form-group">
                <label>Número de Cuotas</label>
                <input 
                  type="number" 
                  v-model.number="loanForm.installments_count" 
                  placeholder="Ej. 8" 
                  min="1"
                  required
                />
              </div>
              <div class="form-group">
                <label>Fecha de Inicio (Desembolso)</label>
                <input 
                  type="date" 
                  v-model="loanForm.start_date" 
                  required
                />
              </div>
            </div>

            <!-- Método de Amortización (Selector Apple Style) -->
            <div class="form-group">
              <label>Método de Amortización</label>
              <div class="methods-tab-selector">
                <button 
                  v-for="m in ['frances', 'aleman', 'americano', 'simple']" 
                  :key="m"
                  type="button"
                  class="method-btn"
                  :class="{ active: loanForm.method === m }"
                  @click="loanForm.method = m"
                >
                  {{ m === 'frances' ? 'Francés' : m === 'aleman' ? 'Alemán' : m === 'americano' ? 'Americano' : 'Simple' }}
                </button>
              </div>
            </div>

            <!-- Cuadro Informativo / Didáctico del Método Seleccionado -->
            <div class="info-box-method glass-card">
              <h4 class="info-title">{{ selectedMethodInfo.title }}</h4>
              <p class="info-desc">{{ selectedMethodInfo.description }}</p>
              <code class="info-formula">Fórmula: {{ selectedMethodInfo.formula }}</code>
            </div>

            <!-- Simulador de Cuotas Integrado en Tiempo Real (Requisito de Diseño) -->
            <div class="real-time-simulator" v-if="canSimulate">
              <h4 class="sim-title">Resumen de Simulación</h4>
              <div class="sim-metrics">
                <div class="sim-box">
                  <span class="lbl">Cuota Promedio</span>
                  <span class="val">{{ formatCOP(simSummary.avgInstallment) }}</span>
                </div>
                <div class="sim-box">
                  <span class="lbl">Interés Total</span>
                  <span class="val accent">{{ formatCOP(simSummary.totalInterest) }}</span>
                </div>
                <div class="sim-box">
                  <span class="lbl">Total a Recibir</span>
                  <span class="val">{{ formatCOP(simSummary.totalToPay) }}</span>
                </div>
              </div>

              <!-- Cronograma preliminar -->
              <span class="preliminar-label">Primeras 5 cuotas proyectadas</span>
              <div class="sim-schedule-table">
                <table>
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Fecha</th>
                      <th style="text-align: right;">Cuota</th>
                      <th style="text-align: right;">Capital</th>
                      <th style="text-align: right;">Interés</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="s in simSummary.schedule.slice(0, 5)" :key="s.number">
                      <td>{{ s.number }}</td>
                      <td>{{ formatDate(s.date) }}</td>
                      <td style="text-align: right; font-weight: 400;">{{ formatCOP(s.installment) }}</td>
                      <td style="text-align: right;">{{ formatCOP(s.principalPaid) }}</td>
                      <td style="text-align: right; color: var(--text-secondary);">{{ formatCOP(s.interest) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <button type="submit" class="btn-primary" style="margin-top: 10px;" :disabled="btnLoading">
              <i class="fa-solid fa-paper-plane"></i> Crear y Desembolsar Préstamo
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- HOJA DESLIZANTE / BOTTOM SHEET: REGISTRAR RECAUDO -->
    <div class="modal-overlay" :class="{ active: collectModalOpen }" @click.self="collectModalOpen = false">
      <div class="bottom-sheet" :class="{ active: collectModalOpen }" style="max-width: 450px;">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
          <span class="sheet-title">Registrar Cobro</span>
          <button class="sheet-close" @click="collectModalOpen = false">Atrás</button>
        </div>

        <div class="sheet-body" v-if="collectParams">
          <!-- Información corta -->
          <div class="glass-card collect-target-card">
            <h4>{{ selectedLoan?.client_name }}</h4>
            <p>Cuota #{{ collectParams.number }} • Fecha original: {{ formatDate(collectParams.date) }}</p>
            <p>Monto de la Cuota: <strong>{{ formatCOP(collectParams.installment) }}</strong></p>
          </div>

          <form @submit.prevent="submitPayment" class="collect-form">
            <div class="form-group">
              <label>Monto Recibido ($)</label>
              <input 
                type="number" 
                v-model.number="paymentForm.amount" 
                placeholder="Ej. 100000" 
                min="1"
                required
              />
              <span class="input-help">Saldo restante de la cuota: {{ formatCOP(collectParams.installment - collectParams.paid_amount) }}</span>
            </div>

            <div class="form-group">
              <label>Fecha de Recaudo</label>
              <input type="date" v-model="paymentForm.date" required />
            </div>

            <div class="form-group">
              <label>Observaciones / Método</label>
              <input type="text" v-model="paymentForm.note" placeholder="Ej. Pago en efectivo, transferencia..." />
            </div>

            <button type="submit" class="btn-primary" :disabled="btnLoading">
              <i class="fa-solid fa-circle-check"></i> Confirmar Recaudo
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- MODAL / POPUP: RECIBO DIGITAL -->
    <div class="modal-overlay" :class="{ active: receiptModalOpen }" @click.self="receiptModalOpen = false">
      <div class="bottom-sheet" :class="{ active: receiptModalOpen }" style="max-width: 400px;">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
          <span class="sheet-title">Comprobante de Recaudo</span>
          <button class="sheet-close" @click="receiptModalOpen = false">Cerrar</button>
        </div>

        <div class="sheet-body" v-if="selectedReceipt">
          <div class="digital-receipt">
            <div class="receipt-header">
              <h2>ÁBACO</h2>
              <p class="receipt-sub">CONTROL DE PRÉSTAMOS</p>
              <span class="receipt-id">ID: {{ selectedReceipt.id }}</span>
            </div>

            <div class="receipt-body">
              <div class="receipt-row">
                <span>Fecha Pago:</span>
                <strong>{{ formatDate(selectedReceipt.date) }}</strong>
              </div>
              <div class="receipt-row">
                <span>Cliente:</span>
                <strong>{{ selectedReceipt.client_name }}</strong>
              </div>
              <div class="receipt-row">
                <span>Cuota:</span>
                <span>Cuota #{{ selectedReceipt.installment_number }}</span>
              </div>
              <div class="receipt-row" v-if="selectedReceipt.note">
                <span>Detalle:</span>
                <span>{{ selectedReceipt.note }}</span>
              </div>

              <div class="receipt-divider"></div>

              <div class="receipt-row total">
                <span>VALOR PAGADO:</span>
                <span class="success-text">{{ formatCOP(selectedReceipt.amount) }}</span>
              </div>

              <div class="receipt-divider"></div>
            </div>

            <div class="receipt-footer">
              <i class="fa-solid fa-circle-check stamp-icon"></i>
              <p>Recibo Digital de Recaudo Autorizado</p>
            </div>
          </div>

          <button class="btn-primary" style="margin-top: 15px;" @click="printWindow">
            <i class="fa-solid fa-print"></i> Imprimir Comprobante
          </button>
        </div>
      </div>
    </div>

    <!-- HOJA DESLIZANTE / BOTTOM SHEET: CREAR / EDITAR CLIENTE -->
    <div class="modal-overlay" :class="{ active: clientModalOpen }" @click.self="clientModalOpen = false">
      <div class="bottom-sheet" :class="{ active: clientModalOpen }" style="max-width: 450px;">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
          <span class="sheet-title">{{ editingClient ? 'Editar Cliente' : 'Nuevo Cliente' }}</span>
          <button class="sheet-close" @click="clientModalOpen = false">Cerrar</button>
        </div>

        <div class="sheet-body">
          <form @submit.prevent="submitClient" class="client-form">
            <div class="form-group">
              <label>Nombre Completo</label>
              <input type="text" v-model="clientForm.name" placeholder="Ej. Juan Carlos Ramos" required />
            </div>

            <div class="form-group">
              <label>Cédula / NIT / Documento</label>
              <input type="text" v-model="clientForm.document" placeholder="Ej. 1.020.333.444" required />
            </div>

            <div class="form-group">
              <label>Teléfono Móvil</label>
              <input type="tel" v-model="clientForm.phone" placeholder="Ej. 312 456 7890" required />
            </div>

            <div class="form-group">
              <label>Correo Electrónico</label>
              <input type="email" v-model="clientForm.email" placeholder="Ej. juan@example.com" />
            </div>

            <div class="form-group">
              <label>Dirección de Residencia</label>
              <input type="text" v-model="clientForm.address" placeholder="Ej. Calle 45 # 10-20, Bogotá" />
            </div>

            <button type="submit" class="btn-primary" style="margin-top: 10px;" :disabled="btnLoading">
              <i class="fa-solid fa-circle-user"></i> {{ editingClient ? 'Guardar Cambios' : 'Registrar Cliente' }}
            </button>
          </form>
        </div>
      </div>
    </div>

  </div>
</template>

<script>
import { ref, computed, onMounted, watch } from 'vue'
import { API_BASE } from '../config.js'

export default {
  name: 'LoansView',
  setup() {
    // Pestaña interna activa
    const activeSubTab = ref('loans')
    
    // Datos cargados desde API
    const loans = ref([])
    const clients = ref([])
    const transactions = ref([])
    
    // UI Estados
    const loading = ref(false)
    const btnLoading = ref(false)
    const loanFilter = ref('todos')
    const loanSearch = ref('')
    const clientSearch = ref('')
    const historySearch = ref('')
    const expandedClientId = ref(null)

    // Estados de modales
    const detailsModalOpen = ref(false)
    const newLoanModalOpen = ref(false)
    const collectModalOpen = ref(false)
    const receiptModalOpen = ref(false)
    const clientModalOpen = ref(false)

    // Entidades seleccionadas
    const selectedLoan = ref(null)
    const collectParams = ref(null)
    const selectedReceipt = ref(null)
    const editingClient = ref(null)

    const todayStr = computed(() => new Date().toISOString().split('T')[0])

    // Formularios reactivos
    const loanForm = ref({
      client_id: '',
      principal: '',
      interest_rate: '',
      rate_type: 'mensual',
      installments_count: '',
      frequency: 'semanal',
      method: 'frances',
      start_date: new Date().toISOString().split('T')[0]
    })

    const paymentForm = ref({
      amount: '',
      date: new Date().toISOString().split('T')[0],
      note: ''
    })

    const clientForm = ref({
      name: '',
      document: '',
      phone: '',
      email: '',
      address: ''
    })

    // --- Consultas a la API ---

    const fetchAllData = async () => {
      loading.value = true
      const token = localStorage.getItem('token')
      try {
        // Clientes
        const resClients = await fetch(`${API_BASE}/loans.php?action=get_clients`, {
          headers: { 'Authorization': `Bearer ${token}` }
        })
        clients.value = await resClients.json()

        // Préstamos
        const resLoans = await fetch(`${API_BASE}/loans.php?action=get_loans`, {
          headers: { 'Authorization': `Bearer ${token}` }
        })
        loans.value = await resLoans.json()

        // Historial recaudos
        const resTxs = await fetch(`${API_BASE}/loans.php?action=get_transactions`, {
          headers: { 'Authorization': `Bearer ${token}` }
        })
        transactions.value = await resTxs.json()
      } catch (e) {
        console.error('Error al sincronizar datos:', e)
      } finally {
        loading.value = false
      }
    }

    const openLoanDetails = async (loanId) => {
      const token = localStorage.getItem('token')
      try {
        const res = await fetch(`${API_BASE}/loans.php?action=get_loan&loan_id=${loanId}`, {
          headers: { 'Authorization': `Bearer ${token}` }
        })
        if (res.ok) {
          selectedLoan.value = await res.json()
          detailsModalOpen.value = true
        }
      } catch (err) {
        console.error(err)
      }
    }

    // --- Lógica del Simulador Proyectado en Vue (Frontend) ---
    
    const canSimulate = computed(() => {
      return loanForm.value.principal > 0 && 
             loanForm.value.interest_rate >= 0 && 
             loanForm.value.installments_count > 0 && 
             loanForm.value.start_date
    })

    const simSummary = computed(() => {
      if (!canSimulate.value) return null
      
      const principal = parseFloat(loanForm.value.principal)
      const rate = parseFloat(loanForm.value.interest_rate)
      const rateType = loanForm.value.rate_type
      const frequency = loanForm.value.frequency
      const method = loanForm.value.method
      const startDate = loanForm.value.start_date

      // Calcular plan
      const schedule = calculateAmortizationFE(principal, rate, rateType, loanForm.value.installments_count, frequency, method, startDate)
      
      const totalToPay = schedule.reduce((sum, inst) => sum + inst.installment, 0)
      const totalInterest = totalToPay - principal
      const avgInstallment = totalToPay / schedule.length

      return {
        schedule,
        totalToPay,
        totalInterest,
        avgInstallment
      }
    })

    // Lógica financiera espejo del backend para el simulador frontend
    const calculateAmortizationFE = (principal, rate, rateType, term, frequency, method, startDate) => {
      const rateFraction = rate / 100
      let r = 0
      
      if (rateType === 'periodo') {
        r = rateFraction
      } else if (rateType === 'mensual') {
        switch (frequency) {
          case 'diario': r = rateFraction / 30; break;
          case 'semanal': r = rateFraction / 4; break;
          case 'quincenal': r = rateFraction / 2; break;
          case 'mensual': r = rateFraction; break;
        }
      } else {
        switch (frequency) {
          case 'diario': r = rateFraction / 360; break;
          case 'semanal': r = rateFraction / 52; break;
          case 'quincenal': r = rateFraction / 24; break;
          case 'mensual': r = rateFraction / 12; break;
        }
      }
      
      const schedule = []
      let balance = principal
      const n = term
      
      if (method === 'frances') {
        const installmentAmount = (r === 0) ? (principal / n) : (principal * (r * Math.pow(1 + r, n)) / (Math.pow(1 + r, n) - 1))
        for (let i = 1; i <= n; i++) {
          const interest = balance * r
          const principalPaid = installmentAmount - interest
          balance = Math.max(0, balance - principalPaid)
          schedule.push({
            number: i,
            date: getNextDateFE(startDate, i, frequency),
            installment: Math.round(installmentAmount),
            principalPaid: Math.round(principalPaid),
            interest: Math.round(interest)
          })
        }
      } 
      else if (method === 'aleman') {
        const principalPaid = principal / n
        for (let i = 1; i <= n; i++) {
          const interest = balance * r
          const installmentAmount = principalPaid + interest
          balance = Math.max(0, balance - principalPaid)
          schedule.push({
            number: i,
            date: getNextDateFE(startDate, i, frequency),
            installment: Math.round(installmentAmount),
            principalPaid: Math.round(principalPaid),
            interest: Math.round(interest)
          })
        }
      }
      else if (method === 'americano') {
        const interest = principal * r
        for (let i = 1; i <= n; i++) {
          const isLast = (i === n)
          const principalPaid = isLast ? principal : 0
          const installmentAmount = interest + principalPaid
          balance = isLast ? 0 : principal
          schedule.push({
            number: i,
            date: getNextDateFE(startDate, i, frequency),
            installment: Math.round(installmentAmount),
            principalPaid: Math.round(principalPaid),
            interest: Math.round(interest)
          })
        }
      }
      else if (method === 'simple') {
        const principalPaid = principal / n
        const interest = principal * r
        const installmentAmount = principalPaid + interest
        for (let i = 1; i <= n; i++) {
          balance = Math.max(0, balance - principalPaid)
          schedule.push({
            number: i,
            date: getNextDateFE(startDate, i, frequency),
            installment: Math.round(installmentAmount),
            principalPaid: Math.round(principalPaid),
            interest: Math.round(interest)
          })
        }
      }
      return schedule
    }

    const getNextDateFE = (startDateStr, index, frequency) => {
      const date = new Date(startDateStr)
      switch (frequency) {
        case 'diario':
          date.setDate(date.getDate() + index)
          break;
        case 'semanal':
          date.setDate(date.getDate() + (index * 7))
          break;
        case 'quincenal':
          date.setDate(date.getDate() + (index * 15))
          break;
        case 'mensual':
          date.setMonth(date.getMonth() + index)
          break;
      }
      return date.toISOString().split('T')[0]
    }

    // Información explicativa didáctica sobre fórmulas
    const selectedMethodInfo = computed(() => {
      const method = loanForm.value.method
      switch (method) {
        case 'frances':
          return {
            title: 'Método Francés (Cuota Fija)',
            description: 'Todas las cuotas tienen exactamente el mismo valor. Inicialmente amortizas pocos intereses e inviertes más en capital hacia el final. El método estándar bancario.',
            formula: 'C = P * [r(1+r)^n] / [(1+r)^n - 1]'
          }
        case 'aleman':
          return {
            title: 'Método Alemán (Amortización Constante)',
            description: 'El abono a capital es constante en cada cuota. La cuota total decrece en cada periodo porque los intereses se calculan sobre saldos insolutos menores.',
            formula: 'Abono Capital = P / n | Cuota = Abono + Interés'
          }
        case 'americano':
          return {
            title: 'Método Americano (Al Vencimiento)',
            description: 'Solo pagas intereses periódicamente. Todo el monto prestado inicialmente (el capital principal) se liquida de un solo golpe en la última cuota programada.',
            formula: 'Cuotas 1 a n-1 = P * r | Cuota n = P + (P * r)'
          }
        case 'simple':
          return {
            title: 'Interés Simple / Directo (Flat)',
            description: 'Los intereses se calculan estáticamente sobre el capital prestado. Las cuotas son fijas y se distribuyen equitativamente en capital e intereses fijos.',
            formula: 'Interés Cuota = P * r | Capital Cuota = P / n'
          }
      }
      return { title: '', description: '', formula: '' }
    })

    // --- Filtros Computados ---

    const filteredLoans = computed(() => {
      return loans.value.filter(l => {
        const matchesSearch = l.client_name.toLowerCase().includes(loanSearch.value.toLowerCase().trim())
        const matchesFilter = loanFilter.value === 'todos' ? true : l.status === loanFilter.value
        return matchesSearch && matchesFilter
      })
    })

    const filteredClients = computed(() => {
      const q = clientSearch.value.toLowerCase().trim()
      return clients.value.filter(c => {
        if (!q) return true
        return c.name.toLowerCase().includes(q) || 
               c.document.includes(q) || 
               c.phone.includes(q)
      })
    })

    const filteredTransactions = computed(() => {
      const q = historySearch.value.toLowerCase().trim()
      return transactions.value.filter(t => {
        if (!q) return true
        return t.client_name.toLowerCase().includes(q) || 
               (t.note && t.note.toLowerCase().includes(q))
      })
    })

    // --- Eventos de Apertura de Formularios ---

    const openNewLoanSheet = (prefilledClientId = null) => {
      loanForm.value = {
        client_id: prefilledClientId || '',
        principal: '',
        interest_rate: '',
        rate_type: 'mensual',
        installments_count: '',
        frequency: 'semanal',
        method: 'frances',
        start_date: new Date().toISOString().split('T')[0]
      }
      newLoanModalOpen.value = true
    }

    const openClientSheet = (client = null) => {
      if (client) {
        editingClient.value = client
        clientForm.value = { ...client }
      } else {
        editingClient.value = null
        clientForm.value = {
          name: '',
          document: '',
          phone: '',
          email: '',
          address: ''
        }
      }
      clientModalOpen.value = true
    }

    const toggleClientExpand = (id) => {
      expandedClientId.value = expandedClientId.value === id ? null : id
    }

    const getClientLoans = (clientId) => {
      return loans.value.filter(l => l.client_id === clientId)
    }

    const canDeleteClient = (clientId) => {
      return getClientLoans(clientId).every(l => l.status === 'finalizado')
    }

    const triggerPaymentCollect = (inst) => {
      collectParams.value = inst
      paymentForm.value = {
        amount: inst.installment - inst.paid_amount,
        date: new Date().toISOString().split('T')[0],
        note: `Pago Cuota #${inst.number}`
      }
      collectModalOpen.value = true
    }

    const openReceipt = (tx) => {
      selectedReceipt.value = tx
      receiptModalOpen.value = true
    }

    // --- Procesamiento de Formularios (Escrituras API) ---

    const submitNewLoan = async () => {
      btnLoading.value = true
      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/loans.php?action=create_loan`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`
          },
          body: JSON.stringify({
            client_id: loanForm.value.client_id,
            principal: loanForm.value.principal,
            interest_rate: loanForm.value.interest_rate,
            rate_type: loanForm.value.rate_type,
            installments_count: loanForm.value.installments_count,
            frequency: loanForm.value.frequency,
            method: loanForm.value.method,
            start_date: loanForm.value.start_date
          })
        })
        
        const data = await response.json()
        if (response.ok) {
          newLoanModalOpen.value = false
          await fetchAllData()
        } else {
          alert(data.error || 'Error al guardar préstamo')
        }
      } catch (err) {
        console.error(err)
      } finally {
        btnLoading.value = false
      }
    }

    const submitPayment = async () => {
      btnLoading.value = true
      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/loans.php?action=record_payment`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`
          },
          body: JSON.stringify({
            loan_id: selectedLoan.value.id,
            installment_number: collectParams.value.number,
            amount: paymentForm.value.amount,
            date: paymentForm.value.date,
            note: paymentForm.value.note
          })
        })

        const data = await response.json()
        if (response.ok) {
          collectModalOpen.value = false
          
          // Reabrir préstamo para ver saldo actualizado
          await openLoanDetails(selectedLoan.value.id)
          
          // Sincronizar todos los datos en fondo
          await fetchAllData()
          
          // Buscar transacción recién creada para abrir recibo
          const newTx = transactions.value.find(t => t.id == data.transaction_id)
          if (newTx) {
            openReceipt(newTx)
          } else {
            // Fallback: buscar la primera de la lista
            if (transactions.value.length > 0) {
              openReceipt(transactions.value[0])
            }
          }
        } else {
          alert(data.error || 'Error al registrar abono')
        }
      } catch (e) {
        console.error(e)
      } finally {
        btnLoading.value = false
      }
    }

    const submitClient = async () => {
      btnLoading.value = true
      const token = localStorage.getItem('token')
      try {
        let response
        if (editingClient.value) {
          response = await fetch(`${API_BASE}/loans.php?action=update_client&client_id=${editingClient.value.id}`, {
            method: 'PUT',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify(clientForm.value)
          })
        } else {
          response = await fetch(`${API_BASE}/loans.php?action=create_client`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify(clientForm.value)
          })
        }

        const data = await response.json()
        if (response.ok) {
          clientModalOpen.value = false
          await fetchAllData()
        } else {
          alert(data.error || 'Error al procesar cliente')
        }
      } catch (err) {
        console.error(err)
      } finally {
        btnLoading.value = false
      }
    }

    const handleDeleteClient = async (clientId) => {
      if (!confirm('¿Está totalmente seguro de borrar este cliente de la base de datos?')) return
      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/loans.php?action=delete_client&client_id=${clientId}`, {
          method: 'DELETE',
          headers: { 'Authorization': `Bearer ${token}` }
        })
        if (response.ok) {
          expandedClientId.value = null
          await fetchAllData()
        } else {
          const data = await response.json()
          alert(data.error || 'No se pudo eliminar')
        }
      } catch (e) {
        console.error(e)
      }
    }

    const handleDeleteLoan = async (loanId) => {
      if (!confirm('¿Está seguro de eliminar este préstamo? Se perderán las cuotas y todo el historial de recaudos asociados de manera permanente.')) return
      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/loans.php?action=delete_loan&loan_id=${loanId}`, {
          method: 'DELETE',
          headers: { 'Authorization': `Bearer ${token}` }
        })
        if (response.ok) {
          detailsModalOpen.value = false
          selectedLoan.value = null
          await fetchAllData()
        }
      } catch (e) {
        console.error(e)
      }
    }

    // --- Utilitarios de Formateo ---

    const formatCOP = (value) => {
      if (value === null || value === undefined || isNaN(value)) return '$ 0'
      return new Intl.NumberFormat('es-CO', {
        style: 'currency',
        currency: 'COP',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
      }).format(value).replace('COP', '$').trim()
    }

    const formatDate = (dateStr) => {
      if (!dateStr) return ''
      const date = new Date(dateStr)
      if (isNaN(date.getTime())) return dateStr
      const day = String(date.getUTCDate()).padStart(2, '0')
      const month = String(date.getUTCMonth() + 1).padStart(2, '0')
      const year = date.getUTCFullYear()
      return `${day}/${month}/${year}`
    }

    const printWindow = () => {
      window.print()
    }

    // Inicialización
    onMounted(() => {
      fetchAllData()
    })

    return {
      activeSubTab,
      loans,
      clients,
      transactions,
      loading,
      btnLoading,
      loanFilter,
      loanSearch,
      clientSearch,
      historySearch,
      expandedClientId,
      detailsModalOpen,
      newLoanModalOpen,
      collectModalOpen,
      receiptModalOpen,
      clientModalOpen,
      selectedLoan,
      collectParams,
      selectedReceipt,
      editingClient,
      todayStr,
      loanForm,
      paymentForm,
      clientForm,
      simSummary,
      canSimulate,
      selectedMethodInfo,
      filteredLoans,
      filteredClients,
      filteredTransactions,
      openNewLoanSheet,
      openClientSheet,
      toggleClientExpand,
      getClientLoans,
      canDeleteClient,
      triggerPaymentCollect,
      openReceipt,
      submitNewLoan,
      submitPayment,
      submitClient,
      handleDeleteClient,
      handleDeleteLoan,
      formatCOP,
      formatDate,
      printWindow,
      openLoanDetails
    }
  }
}
</script>

<style scoped>
.loans-container {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

/* Control Segmentado estilo Apple */
.segmented-control {
  display: flex;
  background-color: var(--bg-secondary);
  padding: 2px;
  border-radius: var(--radius-md);
  border: 1px solid var(--card-border);
}

.segment-btn {
  flex: 1;
  border: none;
  background: none;
  padding: 10px;
  font-size: 14px;
  font-weight: 400;
  border-radius: var(--radius-sm);
  color: var(--text-secondary);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: var(--transition-smooth);
}

.segment-btn.active {
  background-color: var(--bg-tertiary);
  color: var(--text-primary);
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
}

/* Acciones en Filtros */
.actions-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.filters-segment {
  display: flex;
  gap: 8px;
  overflow-x: auto;
}

.filter-chip {
  padding: 6px 12px;
  border-radius: 9999px;
  background: var(--bg-secondary);
  border: 1px solid var(--card-border);
  color: var(--text-secondary);
  font-size: 12.5px;
  cursor: pointer;
  transition: var(--transition-smooth);
}

.filter-chip.active {
  background: var(--color-primary);
  color: #fff;
  border-color: var(--color-primary);
}

/* Buscador */
.search-box {
  position: relative;
  width: 100%;
}

.search-box input {
  padding-left: 40px !important;
}

.search-icon {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-secondary);
  font-size: 15px;
}

/* Loading y Vacío */
.loading-state {
  text-align: center;
  padding: 40px;
  color: var(--text-secondary);
  font-size: 15px;
}

.empty-card {
  text-align: center;
  padding: 40px 20px;
  color: var(--text-secondary);
}

.info-icon {
  font-size: 28px;
  margin-bottom: 12px;
  color: var(--text-muted);
}

/* Tarjetas de Préstamos */
.loans-list {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}

@media (min-width: 768px) {
  .loans-list {
    grid-template-columns: repeat(2, 1fr);
  }
}

.loan-card {
  cursor: pointer;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.loan-card-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

.client-name {
  font-size: 17px;
  font-weight: 500;
  color: var(--text-primary);
}

.loan-method {
  font-size: 11px;
  color: var(--text-secondary);
  text-transform: uppercase;
}

.badge-loan {
  font-size: 10.5px;
  font-weight: 500;
  padding: 3px 8px;
  border-radius: 12px;
  text-transform: uppercase;
}

.status-activo {
  background: rgba(10, 132, 255, 0.1);
  color: var(--color-primary);
}

.status-vencido, .mora-text {
  background: var(--danger-bg);
  color: var(--color-danger) !important;
}

.status-finalizado, .status-pagado {
  background: var(--success-bg);
  color: var(--color-success);
}

.loan-card-details {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  padding: 8px 0;
  border-top: 1px solid var(--card-border);
  border-bottom: 1px solid var(--card-border);
}

.detail-col {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.detail-col .label {
  font-size: 10px;
  color: var(--text-secondary);
  text-transform: uppercase;
}

.detail-col .val {
  font-size: 14.5px;
  font-weight: 400;
}

.loan-card-footer {
  display: flex;
  justify-content: space-between;
  font-size: 11.5px;
  color: var(--text-secondary);
}

.view-link {
  color: var(--color-primary);
  display: flex;
  align-items: center;
  gap: 4px;
}

/* Acordeón de Clientes */
.clients-accordion {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.client-accordion-card {
  padding: 0;
  overflow: hidden;
}

.accordion-header {
  padding: 14px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  cursor: pointer;
}

.client-meta-info {
  display: flex;
  align-items: center;
  gap: 12px;
}

.client-avatar {
  width: 36px;
  height: 36px;
  background: var(--bg-tertiary);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--text-secondary);
}

.client-accordion-name {
  font-size: 15.5px;
  font-weight: 400;
}

.client-accordion-sub {
  font-size: 12.5px;
  color: var(--text-secondary);
}

.arrow-icon {
  color: var(--text-muted);
  font-size: 14px;
  transition: transform 0.25s;
}

.arrow-icon.rotated {
  transform: rotate(180deg);
}

.accordion-body {
  padding: 16px;
  background: rgba(0, 0, 0, 0.01);
  border-top: 1px solid var(--card-border);
}

.client-additional-info {
  font-size: 13.5px;
  display: grid;
  grid-template-columns: 1fr;
  gap: 6px;
  margin-bottom: 16px;
}

.section-label {
  font-size: 12px;
  text-transform: uppercase;
  color: var(--text-secondary);
  margin-bottom: 8px;
  font-weight: 400;
  letter-spacing: 0.5px;
}

.no-loans-text {
  font-size: 12.5px;
  color: var(--text-muted);
  font-style: italic;
}

.client-loans-grid {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.client-loan-mini-card {
  background: var(--bg-primary);
  border: 1px solid var(--card-border);
  padding: 8px 12px;
  border-radius: 8px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: 13px;
  transition: var(--transition-smooth);
}

.client-loan-mini-card:hover {
  border-color: var(--color-primary);
}

.mini-card-header {
  display: flex;
  justify-content: space-between;
  font-weight: 400;
}

.mini-card-sub {
  font-size: 11px;
  color: var(--text-secondary);
}

.mini-badge {
  font-size: 9px;
  padding: 1px 4px;
  border-radius: 4px;
}

.client-actions-footer {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  margin-top: 16px;
  padding-top: 12px;
  border-top: 1px solid var(--card-border);
}

.btn-small {
  padding: 6px 12px;
  font-size: 12.5px;
  width: auto;
}

/* Historial Recaudos */
.tx-history-header {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
  text-transform: uppercase;
  color: var(--text-secondary);
  padding: 0 16px 10px 16px;
  border-bottom: 1px solid var(--card-border);
}

.tx-history-list {
  display: flex;
  flex-direction: column;
}

.tx-history-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  border-bottom: 1px solid var(--card-border);
  cursor: pointer;
  transition: var(--transition-smooth);
}

.tx-history-item:last-child {
  border-bottom: none;
}

.tx-history-item:hover {
  background: rgba(255, 255, 255, 0.02);
}

.tx-item-left {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.tx-client {
  font-size: 14.5px;
  font-weight: 400;
}

.tx-sub {
  font-size: 11.5px;
  color: var(--text-secondary);
}

.tx-note {
  font-size: 11px;
  color: var(--text-muted);
  margin-top: 2px;
}

.tx-item-right {
  display: flex;
  align-items: center;
  gap: 12px;
}

.tx-amount {
  color: var(--color-success);
  font-weight: 400;
  font-size: 14.5px;
}

.chevron {
  font-size: 11px;
  color: var(--text-muted);
}

/* Modales y Hojas Deslizantes (Bottom Sheets) */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.4);
  z-index: 1000;
  opacity: 0;
  visibility: hidden;
  transition: opacity 0.3s ease, visibility 0.3s ease;
}

.modal-overlay.active {
  opacity: 1;
  visibility: visible;
}

.bottom-sheet {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  background: var(--bg-secondary);
  border-radius: 20px 20px 0 0;
  max-height: 90vh;
  overflow-y: auto;
  z-index: 1001;
  transform: translateY(100%);
  transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  box-shadow: 0 -8px 30px rgba(0,0,0,0.15);
  padding: 16px;
}

.bottom-sheet.active {
  transform: translateY(0);
}

.sheet-handle {
  width: 40px;
  height: 5px;
  background: var(--card-border);
  border-radius: 3px;
  margin: -6px auto 12px auto;
}

.sheet-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
  border-bottom: 1px solid var(--card-border);
  padding-bottom: 10px;
}

.sheet-title {
  font-size: 18px;
  font-weight: 500;
}

.sheet-close {
  background: none;
  border: none;
  font-size: 14px;
  color: var(--color-primary);
  cursor: pointer;
}

.sheet-body {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

@media (min-width: 769px) {
  .bottom-sheet {
    max-width: 500px;
    margin: 0 auto;
    border-radius: 16px;
    bottom: 50%;
    transform: translate(0, 50%) scale(0.9);
    top: auto;
    left: 0;
    right: 0;
    opacity: 0;
    visibility: hidden;
    transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.2s;
  }
  .bottom-sheet.active {
    transform: translate(0, 50%) scale(1);
    opacity: 1;
    visibility: visible;
  }
  .sheet-handle {
    display: none;
  }
}

/* Ficha de Detalles de Préstamo */
.loan-profile-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.profile-title {
  font-size: 20px;
  font-weight: 500;
}

.profile-sub {
  font-size: 12px;
  color: var(--text-secondary);
}

.loan-profile-summary-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}

.summary-box {
  background: var(--bg-primary);
  border: 1px solid var(--card-border);
  padding: 8px;
  border-radius: 8px;
  text-align: center;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.sum-label {
  font-size: 9.5px;
  text-transform: uppercase;
  color: var(--text-secondary);
}

.sum-val {
  font-size: 13.5px;
  font-weight: 500;
}

.sum-val.success {
  color: var(--color-success);
}

.sum-val.danger {
  color: var(--color-danger);
}

.loan-details-metadata {
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 13px;
}

.meta-row {
  display: flex;
  justify-content: space-between;
}

.meta-row span {
  color: var(--text-secondary);
}

.sheet-section-title {
  font-size: 13px;
  text-transform: uppercase;
  color: var(--text-secondary);
  font-weight: 400;
  letter-spacing: 0.5px;
}

.table-container {
  overflow-x: auto;
  border-radius: var(--radius-sm);
  border: 1px solid var(--card-border);
}

.schedule-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.schedule-table th, .schedule-table td {
  padding: 8px 10px;
  border-bottom: 1px solid var(--card-border);
  text-align: left;
}

.schedule-table th {
  background: var(--bg-secondary);
  font-weight: 500;
  color: var(--text-secondary);
  font-size: 10.5px;
  text-transform: uppercase;
}

.schedule-table tr:last-child td {
  border-bottom: none;
}

.btn-collect-action {
  background: rgba(10, 132, 255, 0.1);
  color: var(--color-primary);
  border: none;
  font-size: 11px;
  padding: 3px 8px;
  border-radius: 4px;
  cursor: pointer;
  font-weight: 500;
}

.text-completed {
  color: var(--color-success);
  font-size: 13px;
}

.details-admin-row {
  display: flex;
  justify-content: flex-end;
  margin-top: 10px;
}

/* Formularios de Préstamos */
.new-loan-form, .collect-form, .client-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.form-row-2 {
  display: flex;
  gap: 12px;
}

.form-row-2 .form-group {
  flex: 1;
}

/* Selector de métodos */
.methods-tab-selector {
  display: flex;
  background: var(--bg-primary);
  border: 1px solid var(--card-border);
  padding: 2px;
  border-radius: 8px;
}

.method-btn {
  flex: 1;
  border: none;
  background: none;
  padding: 6px;
  font-size: 11.5px;
  border-radius: 6px;
  color: var(--text-secondary);
  cursor: pointer;
}

.method-btn.active {
  background: var(--bg-tertiary);
  color: var(--color-primary);
}

.info-box-method {
  border-left: 3px solid var(--color-primary);
  padding: 12px;
  border-radius: 0 8px 8px 0;
  font-size: 13px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.info-title {
  font-weight: 500;
}

.info-formula {
  font-family: monospace;
  background: rgba(0, 0, 0, 0.05);
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 11px;
  align-self: flex-start;
  margin-top: 4px;
}

/* Simulador Proyectado */
.real-time-simulator {
  background: rgba(0, 0, 0, 0.02);
  border: 1px dashed var(--card-border);
  border-radius: 12px;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.sim-title {
  font-size: 12px;
  text-transform: uppercase;
  color: var(--text-secondary);
  font-weight: 400;
}

.sim-metrics {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}

.sim-box {
  background: var(--bg-primary);
  border: 1px solid var(--card-border);
  padding: 6px;
  border-radius: 6px;
  text-align: center;
  display: flex;
  flex-direction: column;
}

.sim-box .lbl {
  font-size: 9px;
  color: var(--text-secondary);
}

.sim-box .val {
  font-size: 12.5px;
  font-weight: 500;
}

.sim-box .val.accent {
  color: var(--color-primary);
}

.preliminar-label {
  font-size: 10px;
  color: var(--text-secondary);
}

.sim-schedule-table {
  max-height: 120px;
  overflow-y: auto;
  border-radius: 6px;
  border: 1px solid var(--card-border);
}

.sim-schedule-table table {
  width: 100%;
  border-collapse: collapse;
  font-size: 11.5px;
}

.sim-schedule-table th, .sim-schedule-table td {
  padding: 4px 8px;
  border-bottom: 1px solid var(--card-border);
}

.sim-schedule-table th {
  background: var(--bg-secondary);
  color: var(--text-secondary);
}

/* Recibo digital */
.digital-receipt {
  background: var(--bg-primary);
  border: 1px dashed var(--card-border);
  border-radius: 8px;
  padding: 20px;
  font-size: 13px;
}

.receipt-header {
  text-align: center;
  border-bottom: 1px dashed var(--card-border);
  padding-bottom: 12px;
  margin-bottom: 12px;
}

.receipt-header h2 {
  font-weight: 500;
  letter-spacing: 0.5px;
}

.receipt-sub {
  font-size: 10px;
  color: var(--text-secondary);
  text-transform: uppercase;
}

.receipt-id {
  font-size: 9.5px;
  color: var(--text-muted);
}

.receipt-body {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.receipt-row {
  display: flex;
  justify-content: space-between;
}

.receipt-row span {
  color: var(--text-secondary);
}

.receipt-divider {
  border-top: 1px dashed var(--card-border);
  margin: 10px 0;
}

.receipt-row.total {
  font-size: 14.5px;
  font-weight: 500;
}

.success-text {
  color: var(--color-success);
}

.receipt-footer {
  text-align: center;
  margin-top: 16px;
  color: var(--text-secondary);
  font-size: 11px;
}

.stamp-icon {
  font-size: 24px;
  color: var(--color-success);
  margin-bottom: 4px;
  opacity: 0.85;
}

/* Botones y campos */
.btn-small {
  padding: 6px 12px;
  font-size: 12.5px;
}

.btn-danger-outline {
  background: none;
  border: 1px solid var(--color-danger);
  color: var(--color-danger);
  border-radius: var(--radius-sm);
  padding: 10px 20px;
  font-size: 15px;
  cursor: pointer;
  transition: var(--transition-smooth);
}

.btn-danger-outline:hover {
  background: var(--danger-bg);
}

.input-help {
  font-size: 11px;
  color: var(--text-secondary);
  margin-top: 2px;
}
</style>
