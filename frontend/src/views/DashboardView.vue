<template>
  <div class="dashboard-container">
    <!-- Encabezado de bienvenida -->
    <div class="view-header dashboard-header">
      <div>
        <div v-if="activeWorkspace === 'business'" style="display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:20px; background:rgba(56,189,248,0.15); border:1px solid rgba(56,189,248,0.3); color:#38bdf8; font-size:12px; font-weight:700; margin-bottom:6px;">
          <i class="fa-solid fa-store"></i> Panel de {{ businessName || 'Mi Negocio' }}
        </div>
        <h1 class="view-title">{{ activeWorkspace === 'business' ? (businessName || 'Mi Negocio') : 'Hola, ' + user.name }}</h1>
        <p class="view-subtitle">
          {{ activeWorkspace === 'business' ? 'Caja chica, ventas del día y estado financiero del negocio' : 'Resumen de tus finanzas para este mes' }}
        </p>
      </div>
      <div v-if="user.subscription_status === 'trial'" class="trial-badge glass-card">
        <span>Prueba Gratuita (SaaS)</span>
      </div>
    </div>

    <!-- Grid de Balances Principales -->
    <div class="balance-grid" :class="{ 'business-grid': activeWorkspace === 'business' }">
      <div class="glass-card balance-card total" :style="activeWorkspace === 'business' ? { borderTop: '3px solid #38bdf8' } : {}">
        <span class="card-label">{{ activeWorkspace === 'business' ? 'Caja & Liquidez del Negocio' : 'Balance Total' }}</span>
        <h2 class="amount">
          {{ formatCurrency(totalAccountsBalance) }}
        </h2>
        <p class="card-detail">{{ activeWorkspace === 'business' ? 'Disponible en caja chica y cuentas empresa' : 'Suma de todas tus cuentas' }}</p>
      </div>

      <div class="glass-card balance-card income">
        <div class="card-header-icon">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="12" y1="19" x2="12" y2="5"></line>
            <polyline points="5 12 12 5 19 12"></polyline>
          </svg>
        </div>
        <span class="card-label">{{ activeWorkspace === 'business' ? 'Ventas del Mes' : 'Ingresos del Mes' }}</span>
        <h3 class="amount amount-positive">{{ formatCurrency(totals.ingresos) }}</h3>
      </div>

      <div class="glass-card balance-card expense">
        <div class="card-header-icon">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="12" y1="5" x2="12" y2="19"></line>
            <polyline points="19 12 12 19 5 12"></polyline>
          </svg>
        </div>
        <span class="card-label">{{ activeWorkspace === 'business' ? 'Costos & Gastos Negocio' : 'Gastos del Mes' }}</span>
        <h3 class="amount amount-negative">{{ formatCurrency(totals.egresos) }}</h3>
      </div>

      <!-- Tarjeta de Utilidad Neta (Exclusiva de Modo Negocio) -->
      <div v-if="activeWorkspace === 'business'" class="glass-card balance-card" style="border-top: 3px solid #10b981; background: rgba(16, 185, 129, 0.08);">
        <div class="card-header-icon" style="color: #10b981;">
          <i class="fa-solid fa-chart-line" style="font-size:18px;"></i>
        </div>
        <span class="card-label" style="color:#10b981; font-weight:700;">Ganancia Neta (Utilidad)</span>
        <h3 class="amount" :style="{ color: (totals.ingresos - totals.egresos) >= 0 ? '#10b981' : '#ef4444' }">
          {{ formatCurrency(totals.ingresos - totals.egresos) }}
        </h3>
        <p class="card-detail" style="font-size:11px; margin-top:2px;">
          Margen: {{ totals.ingresos > 0 ? Math.round(((totals.ingresos - totals.egresos) / totals.ingresos) * 100) : 0 }}% sobre ventas
        </p>
      </div>
    </div>

    <!-- Botones de Acción Rápida (Orden: 1º Dictar por Voz, 2º Escanear Recibo, 3º Ingreso, 4º Gasto) -->
    <div class="actions-container">
      <!-- 1º: Dictar por Voz con IA (Botón Principal Destacado) -->
      <button class="btn-primary" @click="startVoiceDictation" style="background:linear-gradient(135deg, #a855f7, #7c3aed); border:none; display:flex; align-items:center; justify-content:center; gap:8px; box-shadow: 0 4px 16px rgba(168,85,247,0.4); font-weight:700;">
        <i class="fa-solid fa-microphone" style="font-size:16px;"></i>
        <span>Dictar por Voz (IA)</span>
      </button>

      <!-- 2º: Escanear Recibo con IA -->
      <label class="btn-ai-scan glass-card">
        <input ref="receiptInput" type="file" accept="image/*" capture="environment" class="hidden-input" @change="handleReceiptScan" :disabled="aiLoading" />
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="btn-icon" v-if="!aiLoading">
          <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
          <circle cx="12" cy="13" r="4"></circle>
        </svg>
        <div class="spinner" v-else></div>
        <span>{{ aiLoading ? 'Procesando Recibo IA...' : 'Escanear Recibo (IA)' }}</span>
      </label>

      <!-- 3º: Registrar Ingreso -->
      <button class="btn-success" @click="openTransactionModal('ingreso')">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="btn-icon">
          <line x1="12" y1="5" x2="12" y2="19"></line>
          <line x1="5" y1="12" x2="19" y2="12"></line>
        </svg>
        Registrar Ingreso
      </button>

      <!-- 4º: Registrar Gasto -->
      <button class="btn-primary" @click="openTransactionModal('egreso')">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="btn-icon">
          <line x1="12" y1="5" x2="12" y2="19"></line>
          <line x1="5" y1="12" x2="19" y2="12"></line>
        </svg>
        Registrar Gasto
      </button>
    </div>

    <!-- Filtros de Período (Barra compacta desplegable abajo de las acciones) -->
    <div class="glass-card filter-card-compact" style="padding: 10px 16px; border-radius: 12px; margin-bottom: 4px;">
      <div class="filter-header-toggle" @click="showFilterDrawer = !showFilterDrawer" style="display:flex; justify-content:space-between; align-items:center; cursor:pointer;">
        <h4 style="margin:0; font-size:13px; font-weight:600; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
          <i class="fa-solid fa-calendar-days" style="color:var(--color-primary);"></i> Período de vista: <strong style="color:var(--color-primary);">{{ getActiveFilterLabel }}</strong>
        </h4>
        <span style="font-size:12px; color:var(--text-secondary); display:flex; align-items:center; gap:4px; font-weight:600;">
          {{ showFilterDrawer ? 'Ocultar' : 'Cambiar Fecha' }}
          <i class="fa-solid" :class="showFilterDrawer ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
        </span>
      </div>

      <div v-if="showFilterDrawer" class="filter-row" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin-top:12px; padding-top:12px; border-top:1px solid rgba(255,255,255,0.08);">
        <div class="filter-group" style="flex:1 1 140px; width:100%;">
          <label style="display:block; font-size:11px; color:var(--text-secondary); margin-bottom:4px; font-weight:600;">Modo Rango</label>
          <select v-model="filterRangeMode" style="width:100%; height:38px; border-radius:8px; border:1px solid var(--card-border); background:rgba(255,255,255,0.05); color:var(--text-primary); padding:0 8px; font-size:13px; outline:none;">
            <option value="month">Por Mes</option>
            <option value="week">Esta Semana (Últimos 7 días)</option>
            <option value="custom">Rango Personalizado</option>
          </select>
        </div>

        <div v-if="filterRangeMode === 'month'" class="filter-group" style="flex:1 1 180px; width:100%; display:flex; gap:8px;">
          <div style="flex:1.5;">
            <label style="display:block; font-size:11px; color:var(--text-secondary); margin-bottom:4px; font-weight:600;">Mes</label>
            <select v-model.number="filterMonth" style="width:100%; height:38px; border-radius:8px; border:1px solid var(--card-border); background:rgba(255,255,255,0.05); color:var(--text-primary); padding:0 8px; font-size:13px; outline:none;">
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
          <div style="flex:1;">
            <label style="display:block; font-size:11px; color:var(--text-secondary); margin-bottom:4px; font-weight:600;">Año</label>
            <select v-model.number="filterYear" style="width:100%; height:38px; border-radius:8px; border:1px solid var(--card-border); background:rgba(255,255,255,0.05); color:var(--text-primary); padding:0 8px; font-size:13px; outline:none;">
              <option value="2026">2026</option>
              <option value="2025">2025</option>
              <option value="2024">2024</option>
            </select>
          </div>
        </div>

        <div v-if="filterRangeMode === 'custom'" class="filter-group" style="flex:1 1 200px; width:100%; display:flex; gap:8px;">
          <div style="flex:1;">
            <label style="display:block; font-size:11px; color:var(--text-secondary); margin-bottom:4px; font-weight:600;">Desde</label>
            <input type="date" v-model="filterStartDate" style="width:100%; height:38px; border-radius:8px; border:1px solid var(--card-border); background:rgba(255,255,255,0.05); color:var(--text-primary); padding:0 8px; font-size:13px; outline:none;" />
          </div>
          <div style="flex:1;">
            <label style="display:block; font-size:11px; color:var(--text-secondary); margin-bottom:4px; font-weight:600;">Hasta</label>
            <input type="date" v-model="filterEndDate" style="width:100%; height:38px; border-radius:8px; border:1px solid var(--card-border); background:rgba(255,255,255,0.05); color:var(--text-primary); padding:0 8px; font-size:13px; outline:none;" />
          </div>
        </div>

        <button @click="applyDateFilters" class="btn-primary" style="height:38px; margin-top:19px; padding:0 16px; font-size:13px; border-radius:8px; display:flex; align-items:center; gap:6px; flex:1 1 auto; justify-content:center;">
          <i class="fa-solid fa-rotate"></i> Aplicar
        </button>
      </div>
    </div>

    <!-- SECCIÓN DE INTELIGENCIA FINANCIERA (Health Score + Autonomía + Exportación) -->
    <div v-if="insightsData" class="insights-container" style="margin-bottom: 20px;">
      <div class="insights-grid-row" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
        <!-- Tarjeta 1: Score de Salud Financiera -->
        <div class="glass-card insight-card" style="padding:18px; display:flex; flex-direction:column; justify-content:space-between; position:relative; overflow:hidden;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <div>
              <h4 style="margin:0; font-size:13px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); font-weight:700;">
                <i class="fa-solid fa-heart-pulse" style="color:var(--color-danger); margin-right:6px;"></i> Salud Financiera
              </h4>
              <span style="font-size:11px; color:var(--text-muted);">Índice de bienestar económico</span>
            </div>
            <div class="health-badge" :style="{ backgroundColor: insightsData.health_color + '20', color: insightsData.health_color, border: '1px solid ' + insightsData.health_color }" style="padding:4px 12px; border-radius:20px; font-weight:700; font-size:12px;">
              {{ insightsData.health_status }}
            </div>
          </div>

          <div style="display:flex; align-items:baseline; gap:8px; margin-bottom:10px;">
            <div style="font-size:38px; font-weight:800; line-height:1;" :style="{ color: insightsData.health_color }">
              {{ insightsData.health_score }}
            </div>
            <div style="font-size:14px; color:var(--text-muted); font-weight:600;">/ 100 pts</div>
          </div>

          <div style="background:rgba(255,255,255,0.04); padding:10px 12px; border-radius:10px; border:1px solid var(--card-border); font-size:12px; line-height:1.4; color:var(--text-primary);">
            <i class="fa-solid fa-lightbulb" style="color:var(--color-warning); margin-right:6px;"></i>
            {{ insightsData.recommendation }}
          </div>
        </div>

        <!-- Tarjeta 2: Autonomía Financiera & Fondo de Emergencia -->
        <div class="glass-card insight-card" style="padding:18px; display:flex; flex-direction:column; justify-content:space-between;">
          <div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
              <h4 style="margin:0; font-size:13px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); font-weight:700;">
                <i class="fa-solid fa-shield-halved" style="color:var(--color-primary); margin-right:6px;"></i> Autonomía Financiera
              </h4>
              <span style="font-size:11px; font-weight:600; background:rgba(10,132,255,0.15); color:var(--color-primary); padding:3px 8px; border-radius:6px;">
                Reserva
              </span>
            </div>

            <div style="font-size:24px; font-weight:800; color:var(--text-primary); margin-bottom:4px;">
              {{ insightsData.runway.months }} Meses
              <span style="font-size:13px; font-weight:500; color:var(--text-secondary);">({{ insightsData.runway.days }} días cubiertos)</span>
            </div>

            <p style="font-size:11.5px; color:var(--text-muted); margin-bottom:10px;">
              Tus saldos líquidos ({{ formatCurrency(insightsData.runway.liquid_balance) }}) sostienen tus gastos promedio ({{ formatCurrency(insightsData.runway.avg_monthly_expense) }}/mes).
            </p>
          </div>

          <div style="display:flex; justify-content:space-between; align-items:center; background:rgba(255,255,255,0.04); padding:8px 12px; border-radius:8px; border:1px solid var(--card-border); font-size:12px;">
            <span>Predicción Fin de Mes:</span>
            <strong :style="{ color: insightsData.forecast.projected_savings >= 0 ? 'var(--color-success)' : 'var(--color-danger)' }">
              {{ insightsData.forecast.projected_savings >= 0 ? '+' : '' }}{{ formatCurrency(insightsData.forecast.projected_savings) }}
            </strong>
          </div>
        </div>

        <!-- Tarjeta 3: Suscripciones & Exportación de Reportes -->
        <div class="glass-card insight-card" style="padding:18px; display:flex; flex-direction:column; justify-content:space-between;">
          <div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
              <h4 style="margin:0; font-size:13px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); font-weight:700;">
                <i class="fa-solid fa-file-invoice-dollar" style="color:var(--color-accent); margin-right:6px;"></i> Reporte & Suscripciones
              </h4>
            </div>

            <div style="font-size:13px; color:var(--text-primary); margin-bottom:12px;">
              Suscripciones activas: <strong>{{ insightsData.subscriptions.items.length }}</strong> ({{ formatCurrency(insightsData.subscriptions.monthly_total) }}/mes)
            </div>
          </div>

          <div style="display:flex; gap:8px;">
            <button @click="downloadReport('html')" class="btn-primary" style="flex:1; height:36px; font-size:12px; border-radius:8px; display:flex; align-items:center; justify-content:center; gap:6px;">
              <i class="fa-solid fa-file-pdf"></i> Reporte PDF
            </button>
            <button @click="downloadReport('csv')" class="btn-secondary" style="flex:1; height:36px; font-size:12px; border-radius:8px; display:flex; align-items:center; justify-content:center; gap:6px;">
              <i class="fa-solid fa-file-excel"></i> Excel / CSV
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Recordatorios de Pago -->
    <div class="glass-card reminders-panel" v-if="reminders.length > 0">
      <h3 class="reminders-title">
        <span class="bell-icon">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
          </svg>
        </span>
        Próximos Vencimientos
      </h3>
      <div class="reminders-horizontal-list">
        <div v-for="rem in reminders" :key="rem.id" class="reminder-item-bubble" :class="rem.type">
          <div class="rem-info">
            <span class="rem-badge" :class="'badge-' + rem.type">{{ rem.type === 'tarjeta' ? 'Tarjeta' : (rem.type === 'servicio' ? 'Servicio' : 'Recordatorio') }}</span>
            <strong class="rem-title-text">{{ rem.title }}</strong>
            <p class="rem-desc">{{ rem.description }}</p>
          </div>
          <div class="rem-action">
            <span class="due-date">Vence: <strong>{{ formatDate(rem.due_date) }}</strong></span>
            <button class="btn-check" @click="completeReminder(rem.id)" title="Marcar como pagado/completado">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"></polyline>
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Sección de Gráficos e Historial -->
    <div class="dashboard-grid">
      <!-- Gastos por Categoría -->
      <div class="glass-card chart-section">
        <h3 class="section-title">Distribución de Gastos</h3>
        
        <div v-if="categoriesReport.length === 0" class="empty-state">
          <p>No tienes gastos registrados este mes.</p>
        </div>

        <div v-else class="chart-content-layout">
          <!-- Gráfico de Dona SVG Nativo -->
          <div class="donut-chart-wrapper">
            <svg viewBox="0 0 120 120" class="donut-chart-svg">
              <circle cx="60" cy="60" r="45" class="donut-ring" stroke-width="12" fill="transparent" />
              <circle v-for="(sector, idx) in donutSectors" 
                      :key="idx"
                      cx="60" 
                      cy="60" 
                      r="45" 
                      class="donut-segment"
                      :stroke="sector.color"
                      :stroke-dasharray="sector.strokeDashArray"
                      :stroke-dashoffset="sector.strokeDashOffset"
                      @mouseenter="activeSector = sector"
                      @mouseleave="activeSector = null"
                      @click="toggleCategoryFilter(sector.name)"
                      style="cursor:pointer;"
                      stroke-width="12"
                      fill="transparent" />
              
              <g class="donut-text" v-if="activeSector">
                <text x="60" y="52" class="donut-center-label" text-anchor="middle">{{ truncateText(activeSector.name, 10) }}</text>
                <text x="60" y="67" class="donut-center-val" text-anchor="middle">{{ formatCurrency(activeSector.total) }}</text>
                <text x="60" y="79" class="donut-center-sub" text-anchor="middle">{{ activeSector.percentage }}%</text>
              </g>
              <g class="donut-text" v-else>
                <text x="60" y="55" class="donut-center-label" text-anchor="middle">Gastos Totales</text>
                <text x="60" y="72" class="donut-center-val" text-anchor="middle">{{ formatCurrency(totals.egresos) }}</text>
              </g>
            </svg>
          </div>

          <!-- Listado de progreso -->
          <div class="categories-list">
            <div v-for="cat in categoriesReport" 
                 :key="cat.name" 
                 class="category-progress-item" 
                 @click="toggleCategoryFilter(cat.name)" 
                 :style="{ cursor: 'pointer', padding: '6px', borderRadius: '8px', transition: 'all 0.2s', background: selectedCategoryFilter === cat.name ? 'rgba(255,255,255,0.05)' : 'transparent', opacity: selectedCategoryFilter && selectedCategoryFilter !== cat.name ? 0.4 : 1 }">
              <div class="category-meta">
                <span class="category-name-badge">
                  <span class="color-dot" :style="{ backgroundColor: cat.color }"></span>
                  {{ cat.name }}
                </span>
                <span class="category-amount">{{ formatCurrency(cat.total) }}</span>
              </div>
              <div class="progress-bar-bg">
                <div class="progress-bar-fill" :style="{ width: getPercentage(cat.total) + '%', backgroundColor: cat.color }"></div>
              </div>
              <span class="category-percent">{{ getPercentage(cat.total) }}% del total</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Historial de Transacciones -->
      <div class="glass-card transactions-section">
        <div class="section-header">
          <h3 class="section-title">Últimos Movimientos</h3>
          <router-link to="/accounts" class="header-link">Ver Cuentas</router-link>
        </div>

        <!-- Banner de Filtro de Categoría Activo -->
        <div v-if="selectedCategoryFilter" class="category-filter-banner" style="display:flex; justify-content:space-between; align-items:center; background:rgba(10,132,255,0.15); border:1px solid rgba(10,132,255,0.3); padding:10px 14px; border-radius:8px; margin-bottom:16px; font-size:13.5px; color:var(--text-primary);">
          <span><i class="fa-solid fa-filter" style="color:#0a84ff; margin-right:6px;"></i> Filtrando categoría: <strong style="color:#0a84ff;">{{ selectedCategoryFilter }}</strong></span>
          <button @click="selectedCategoryFilter = null" style="background:none; border:none; color:#0a84ff; font-weight:700; cursor:pointer; font-size:12px; outline:none;">Quitar Filtro</button>
        </div>

        <div v-if="filteredTransactions.length === 0" class="empty-state">
          <p>No hay transacciones registradas en esta categoría.</p>
        </div>

        <div v-else class="transactions-list">
          <div v-for="tx in filteredTransactions" :key="tx.id" class="transaction-item">
            <div class="tx-info">
              <div class="tx-icon" :style="{ backgroundColor: (tx.category_color || '#64748b') + '15', color: tx.category_color || '#64748b' }">
                <!-- Icono de categoría real de FontAwesome -->
                <i :class="['fa-solid', tx.category_icon || 'fa-tag']" style="font-size:14px;"></i>
              </div>
              <div>
                <h4 class="tx-title">
                  {{ tx.description || tx.category_name }}
                  <span v-if="tx.tags" style="font-size:10px; background:rgba(139,92,246,0.15); color:var(--color-accent); padding:2px 6px; border-radius:4px; margin-left:6px; font-weight:600;">
                    {{ tx.tags }}
                  </span>
                </h4>
                <p class="tx-meta">{{ tx.account_name }} | {{ formatDate(tx.date) }}</p>
              </div>
            </div>
            <div class="tx-actions">
              <span class="tx-amount" :class="tx.type === 'ingreso' ? 'amount-positive' : 'amount-negative'">
                {{ tx.type === 'ingreso' ? '+' : '-' }} {{ formatCurrency(tx.amount) }}
              </span>
              <!-- Botón Editar Transacción -->
              <button class="btn-edit-action-small" @click="startEditTransaction(tx)" title="Editar transacción" v-if="!tx.description.startsWith('GMF 4x1000')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M12 20h9"></path>
                  <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"></path>
                </svg>
              </button>
              <button class="btn-delete" @click="deleteTransaction(tx.id)" title="Eliminar transacción">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="3 6 5 6 21 6"></polyline>
                  <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                </svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- MODAL DE TRANSACCIÓN -->
    <div v-if="showModal" class="modal-overlay" @click.self="closeModal">
      <div class="glass-card modal-content">
        <div class="modal-header">
          <h3>{{ editingTransaction ? 'Editar Transacción' : (modalType === 'ingreso' ? 'Registrar Ingreso' : 'Registrar Gasto') }}</h3>
          <button class="btn-close" @click="closeModal">&times;</button>
        </div>

        <form @submit.prevent="saveTransaction" class="modal-form" novalidate>
          <div class="form-row">
            <div class="form-group">
              <label for="amount">Monto (COP)</label>
              <input type="number" id="amount" v-model.number="form.amount" placeholder="0" min="1" step="any" inputmode="decimal" />
            </div>

            <div class="form-group">
              <label for="date">Fecha</label>
              <input type="date" id="date" v-model="form.date" />
            </div>
          </div>

          <div class="form-group">
            <label for="description">Descripción</label>
            <input type="text" id="description" v-model="form.description" placeholder="Ej: Supermercado, Almuerzo, Salario" required />
          </div>

          <div class="form-group">
            <label for="tags">Etiquetas (#Tags por evento o proyecto)</label>
            <input type="text" id="tags" v-model="form.tags" placeholder="Ej: #ViajeCancún, #Vacaciones, #Negocio" />
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="account">Cuenta / Método de Pago</label>
              <select id="account" v-model="form.account_id" required>
                <option value="" disabled>Selecciona cuenta</option>
                <option v-for="acc in accounts" :key="acc.id" :value="acc.id">
                  {{ acc.name }} ({{ formatCurrency(acc.balance) }})
                </option>
              </select>
            </div>

            <div class="form-group" style="grid-column: 1 / -1;">
              <label style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <span>Categoría</span>
                <span v-if="form.category_id" style="font-size:11px; font-weight:700; color:var(--color-primary);">
                  Seleccionada: {{ getCategoryNameById(form.category_id) }}
                </span>
              </label>
              
              <!-- Buscador en tiempo real de Categorías -->
              <div class="category-search-wrapper" style="position:relative; margin-bottom:8px;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:13px;"></i>
                <input 
                  type="text" 
                  v-model="categorySearchQuery" 
                  placeholder="Buscar o escribir nueva categoría..." 
                  style="width:100%; height:36px; padding-left:34px; padding-right:30px; border-radius:8px; border:1px solid var(--card-border); background:rgba(255,255,255,0.05); color:var(--text-primary); font-size:13px; outline:none;" 
                />
                <button 
                  v-if="categorySearchQuery" 
                  type="button" 
                  @click="categorySearchQuery = ''" 
                  style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); font-size:16px; cursor:pointer;"
                >
                  &times;
                </button>
              </div>

              <!-- Rejilla Visual de Categorías con Iconos -->
              <div class="category-chips-grid" style="display:flex; flex-wrap:wrap; gap:6px; max-height:140px; overflow-y:auto; padding:6px; border:1px solid var(--card-border); border-radius:8px; background:rgba(0,0,0,0.15);">
                <button 
                  type="button" 
                  class="cat-chip-btn" 
                  :class="{ selected: form.category_id === null }"
                  @click="form.category_id = null"
                  style="display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:6px; border:1px solid var(--card-border); background:rgba(255,255,255,0.04); color:var(--text-secondary); font-size:12px; font-weight:500; cursor:pointer; transition:all 0.2s ease;"
                >
                  <i class="fa-solid fa-tag"></i>
                  <span>Sin Categoría</span>
                </button>

                <button 
                  v-for="cat in searchedCategories" 
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
                  v-if="categorySearchQuery.trim() && !searchedCategories.some(c => c.name.toLowerCase() === categorySearchQuery.trim().toLowerCase())" 
                  type="button" 
                  class="cat-chip-btn create-new-chip" 
                  @click="createCategoryOnTheFly(categorySearchQuery)"
                  style="display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:6px; border:1px dashed var(--color-primary); background:rgba(10,132,255,0.15); color:var(--color-primary); font-size:12px; font-weight:600; cursor:pointer;"
                >
                  <i class="fa-solid fa-plus"></i>
                  <span>Crear "{{ categorySearchQuery.trim() }}"</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Campos extras para Tarjeta de Crédito (Cuotas) -->
          <div v-if="isCreditCardSelected && modalType === 'egreso'" class="form-group card-installments">
            <label for="installments">Diferir a cuotas (meses)</label>
            <select id="installments" v-model.number="form.installments_total">
              <option value="1">1 cuota (sin diferir)</option>
              <option value="3">3 cuotas</option>
              <option value="6">6 cuotas</option>
              <option value="12">12 cuotas</option>
              <option value="24">24 cuotas</option>
              <option value="36">36 cuotas</option>
            </select>
          </div>

          <div v-if="budgetExceededWarning" class="warning-msg">
            <i class="fa-solid fa-triangle-exclamation"></i> {{ budgetExceededWarning }}
          </div>

          <div v-if="modalError" class="error-msg">{{ modalError }}</div>

          <button type="submit" class="btn-primary" :disabled="formLoading">
            {{ formLoading ? 'Guardando...' : 'Guardar Transacción' }}
          </button>
        </form>
      </div>
    </div>

    <!-- MODAL DE DICTADO POR VOZ CON IA -->
    <div v-if="showVoiceModal" class="modal-overlay" @click.self="closeVoiceModal">
      <div class="glass-card modal-content" style="max-width:440px; text-align:center; padding:28px;">
        <div style="margin-bottom:16px;">
          <div :style="{ animation: isRecording ? 'pulseGlow 1.2s infinite' : 'none' }" style="width:70px; height:70px; margin:0 auto; background:rgba(168,85,247,0.15); border:2px solid #a855f7; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#a855f7; font-size:28px;">
            <i class="fa-solid fa-microphone"></i>
          </div>
        </div>

        <h3 style="margin:0 0 6px 0; font-size:20px; font-weight:700;">Dictar Movimiento con IA</h3>
        <p style="font-size:13px; color:var(--text-secondary); margin-bottom:14px;">
          {{ isRecording ? 'Te escuchamos... Habla de forma natural:' : 'Presiona el botón e inicia el dictado por micrófono:' }}
        </p>

        <div style="background:rgba(255,255,255,0.04); border:1px solid var(--card-border); padding:10px; border-radius:8px; font-size:12px; color:var(--text-muted); margin-bottom:16px; font-style:italic;">
          "Me gasté 45.000 en el cine con Tarjeta Crédito" <br/>
          "Pagué 120.000 de gasolina con Bancolombia #Viaje"
        </div>

        <!-- Transcripción en tiempo real -->
        <div v-if="voiceTranscript || isRecording" style="min-height:54px; background:rgba(0,0,0,0.25); border:1px solid var(--card-border); border-radius:8px; padding:12px; font-size:14px; font-weight:600; color:var(--text-primary); margin-bottom:18px; word-break:break-word;">
          {{ voiceTranscript || 'Escuchando tu voz...' }}
        </div>

        <div v-if="voiceProcessing" style="display:flex; flex-direction:column; align-items:center; gap:8px; margin-bottom:16px; color:#a855f7;">
          <div class="spinner"></div>
          <span style="font-size:13px; font-weight:600;">La IA está estructurando tu gasto y seleccionando la categoría...</span>
        </div>

        <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
          <button v-if="!isRecording && !voiceProcessing" class="btn-primary" @click="startListening" style="background:#a855f7; border:none; padding:10px 20px;">
            <i class="fa-solid fa-microphone"></i> Iniciar Dictado
          </button>

          <button v-if="isRecording" class="btn-success" @click="stopListeningAndProcess" style="padding:10px 20px;">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Procesar con IA
          </button>

          <button class="btn-secondary" @click="closeVoiceModal" style="padding:10px 20px;">Cancelar</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { API_BASE } from '../config.js'

export default {
  name: 'DashboardView',
  setup() {
    const route = useRoute()
    const receiptInput = ref(null)
    const user = ref({})
    const activeWorkspace = ref(localStorage.getItem('active_workspace') || 'personal')
    const businessName = ref('')

    const updateWorkspaceInfo = () => {
      activeWorkspace.value = localStorage.getItem('active_workspace') || 'personal'
      try {
        const u = JSON.parse(localStorage.getItem('user') || '{}')
        businessName.value = u.business_name || 'Mi Negocio'
      } catch (e) {
        businessName.value = 'Mi Negocio'
      }
    }
    const accounts = ref([])
    const categories = ref([])
    const transactions = ref([])
    const categoriesReport = ref([])
    const reminders = ref([])
    const categoryBudgets = ref([])
    const activeSector = ref(null)
    const editingTransaction = ref(null)
    
    // Totales de reportes
    const totals = ref({ ingresos: 0, egresos: 0, neto: 0 })

    // Estados de UI
    const loading = ref(false)
    const showModal = ref(false)
    const modalType = ref('egreso')
    const formLoading = ref(false)
    const modalError = ref('')
    const aiLoading = ref(false)

    // Estados para Dictado por Voz con IA
    const showVoiceModal = ref(false)
    const isRecording = ref(false)
    const voiceTranscript = ref('')
    const voiceProcessing = ref(false)
    let recognition = null

    // Estados para Buscador Visual de Categorías
    const categorySearchQuery = ref('')

    const searchedCategories = computed(() => {
      const list = filteredCategories.value
      if (!categorySearchQuery.value.trim()) return list
      const q = categorySearchQuery.value.toLowerCase().trim()
      return list.filter(c => c.name.toLowerCase().includes(q))
    })

    const getCategoryNameById = (id) => {
      if (id === null) return 'Sin Categoría'
      const matched = categories.value.find(c => c.id === id)
      return matched ? matched.name : 'Sin Categoría'
    }

    const createCategoryOnTheFly = async (catName) => {
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
        type: modalType.value
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
          categorySearchQuery.value = ''
        }
      } catch (err) {
        console.error(err)
      }
    }

    const startVoiceDictation = () => {
      voiceTranscript.value = ''
      showVoiceModal.value = true
      startListening()
    }

    const startListening = () => {
      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition
      if (!SpeechRecognition) {
        alert('Tu navegador o dispositivo no soporta entrada por voz en vivo. Te recomendamos usar Chrome en Android o Escritorio.')
        return
      }

      recognition = new SpeechRecognition()
      recognition.lang = 'es-ES'
      recognition.continuous = true
      recognition.interimResults = true

      recognition.onstart = () => {
        isRecording.value = true
      }

      recognition.onresult = (event) => {
        let current = ''
        for (let i = event.resultIndex; i < event.results.length; i++) {
          current += event.results[i][0].transcript
        }
        voiceTranscript.value = current
      }

      recognition.onerror = (event) => {
        console.error('Speech recognition error:', event.error)
        isRecording.value = false
      }

      recognition.onend = () => {
        isRecording.value = false
      }

      recognition.start()
    }

    const stopListeningAndProcess = async () => {
      if (recognition) {
        try { recognition.stop() } catch (e) {}
      }
      isRecording.value = false

      if (!voiceTranscript.value.trim()) {
        alert('No escuchamos ninguna palabra. Por favor vuelve a pulsar Iniciar Dictado e intenta hablar de nuevo.')
        return
      }

      voiceProcessing.value = true
      const token = localStorage.getItem('token')
      const customApiKey = localStorage.getItem('gemini_api_key') || ''
      const headers = {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      }
      if (customApiKey) {
        headers['X-Gemini-API-Key'] = customApiKey
      }

      try {
        const res = await fetch(`${API_BASE}/ai.php?action=voice_transaction`, {
          method: 'POST',
          headers,
          body: JSON.stringify({ transcript: voiceTranscript.value })
        })

        const data = await res.json()
        if (!res.ok || data.error) {
          throw new Error(data.error || 'Error al procesar voz con IA')
        }

        // Llenar formulario automáticamente con lo interpretado por la IA
        modalType.value = data.type || 'egreso'
        form.value.amount = data.amount || 0
        form.value.description = data.description || 'Gasto registrado por voz'
        form.value.tags = data.tags || ''
        form.value.date = formatDateForInput(new Date())

        if (data.category_id) {
          form.value.category_id = data.category_id
        } else if (data.category_name) {
          const matched = categories.value.find(c => c.name.toLowerCase() === data.category_name.toLowerCase())
          if (matched) {
            form.value.category_id = matched.id
          } else {
            await createCategoryOnTheFly(data.category_name)
          }
        }

        // Garantizar que la cuenta nunca quede vacía al procesar por voz
        const defaultAccountId = accounts.value[0]?.id || ''
        if (data.account_id && accounts.value.some(a => a.id === parseInt(data.account_id))) {
          form.value.account_id = parseInt(data.account_id)
        } else {
          form.value.account_id = defaultAccountId
        }

        editingTransaction.value = null
        showVoiceModal.value = false
        showModal.value = true
      } catch (err) {
        alert('Error IA: ' + err.message)
      } finally {
        voiceProcessing.value = false
      }
    }

    const closeVoiceModal = () => {
      if (recognition) {
        try { recognition.stop() } catch (e) {}
      }
      isRecording.value = false
      showVoiceModal.value = false
    }

    // Nuevos Estados para Filtrado
    const showFilterDrawer = ref(false)
    const filterRangeMode = ref('month') // 'month', 'week', 'custom'
    const filterMonth = ref(new Date().getMonth() + 1)
    const filterYear = ref(new Date().getFullYear())
    
    // Rango personalizado (por defecto últimos 30 días)
    const filterStartDate = ref(new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0])
    const filterEndDate = ref(new Date().toISOString().split('T')[0])
    
    const selectedCategoryFilter = ref(null)
    const insightsData = ref(null)

    // Formulario de Transacción
    const form = ref({
      amount: '',
      date: new Date().toISOString().split('T')[0],
      description: '',
      tags: '',
      account_id: '',
      category_id: null,
      installments_total: 1
    })

    const downloadReport = (format) => {
      const token = localStorage.getItem('token')
      const url = `${API_BASE}/export_report.php?format=${format}&month=${filterMonth.value}&year=${filterYear.value}&token=${token}`
      window.open(url, '_blank')
    }

    const checkUser = () => {
      const stored = localStorage.getItem('user')
      if (stored) {
        user.value = JSON.parse(stored)
      }
    }

    const fetchData = async () => {
      loading.value = true
      const token = localStorage.getItem('token')
      const headers = { 'Authorization': `Bearer ${token}` }

      try {
        // 1. Cargar Cuentas
        const resAcc = await fetch(`${API_BASE}/accounts.php`, { headers })
        accounts.value = await resAcc.json()

        // 2. Cargar Categorías
        const resCat = await fetch(`${API_BASE}/categories.php`, { headers })
        categories.value = await resCat.json()

        // Calcular fechas según el rango de filtrado seleccionado
        let start = ''
        let end = ''
        let repUrl = ''
        let txUrl = `${API_BASE}/transactions.php?limit=100`

        if (filterRangeMode.value === 'month') {
          const formattedMonth = String(filterMonth.value).padStart(2, '0')
          start = `${filterYear.value}-${formattedMonth}-01`
          const lastDay = new Date(filterYear.value, filterMonth.value, 0).getDate()
          end = `${filterYear.value}-${formattedMonth}-${String(lastDay).padStart(2, '0')}`
          
          repUrl = `${API_BASE}/reports.php?month=${filterMonth.value}&year=${filterYear.value}`
          txUrl += `&start_date=${start}&end_date=${end}`
        } else if (filterRangeMode.value === 'week') {
          const today = new Date()
          const sevenDaysAgo = new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000)
          start = sevenDaysAgo.toISOString().split('T')[0]
          end = today.toISOString().split('T')[0]
          
          repUrl = `${API_BASE}/reports.php?start_date=${start}&end_date=${end}`
          txUrl += `&start_date=${start}&end_date=${end}`
        } else if (filterRangeMode.value === 'custom') {
          start = filterStartDate.value
          end = filterEndDate.value
          
          repUrl = `${API_BASE}/reports.php?start_date=${start}&end_date=${end}`
          txUrl += `&start_date=${start}&end_date=${end}`
        }

        // 3. Cargar Transacciones
        const resTx = await fetch(txUrl, { headers })
        transactions.value = await resTx.json()

        // 4. Cargar Reportes
        const resRep = await fetch(repUrl, { headers })
        const repData = await resRep.json()
        if (repData.totals) totals.value = repData.totals
        if (repData.categories) categoriesReport.value = repData.categories
        categoryBudgets.value = repData.category_budgets || []

        // 5. Cargar Recordatorios
        const resRem = await fetch(`${API_BASE}/reminders.php`, { headers })
        reminders.value = await resRem.json()

        // 6. Cargar Analítica Financiera (Insights)
        const resIns = await fetch(`${API_BASE}/insights.php`, { headers })
        if (resIns.ok) {
          insightsData.value = await resIns.json()
        }

      } catch (err) {
        console.error('Error al cargar datos del dashboard:', err)
      } finally {
        loading.value = false
      }
    }

    const completeReminder = async (id) => {
      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/reminders.php?id=${id}`, {
          method: 'PUT',
          headers: { 'Authorization': `Bearer ${token}` }
        })

        if (!response.ok) {
          const data = await response.json()
          throw new Error(data.error || 'Error al completar el recordatorio.')
        }

        await fetchData()
      } catch (err) {
        alert(err.message)
      }
    }

    const deleteTransaction = async (id) => {
      if (!confirm('¿Estás seguro de que deseas eliminar esta transacción? Los saldos de las cuentas se actualizarán.')) return
      
      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/transactions.php?id=${id}`, {
          method: 'DELETE',
          headers: { 'Authorization': `Bearer ${token}` }
        })

        if (!response.ok) {
          const data = await response.json()
          throw new Error(data.error || 'Error al eliminar.')
        }

        // Recargar datos
        await fetchData()
      } catch (err) {
        alert(err.message)
      }
    }

    const formatDateForInput = (d) => {
      if (!d) return new Date().toISOString().split('T')[0]
      if (typeof d === 'string') {
        const clean = d.trim().split('T')[0].split(' ')[0]
        if (/^\d{4}-\d{2}-\d{2}$/.test(clean)) {
          return clean
        }
      }
      try {
        const dt = new Date(d)
        if (!isNaN(dt.getTime())) {
          const year = dt.getFullYear()
          const month = String(dt.getMonth() + 1).padStart(2, '0')
          const day = String(dt.getDate()).padStart(2, '0')
          return `${year}-${month}-${day}`
        }
      } catch (e) {}
      return new Date().toISOString().split('T')[0]
    }

    // Modal helpers
    const openTransactionModal = (type) => {
      editingTransaction.value = null
      modalType.value = type
      form.value = {
        amount: '',
        date: formatDateForInput(new Date()),
        description: '',
        tags: '',
        account_id: accounts.value[0]?.id || '',
        category_id: null,
        installments_total: 1
      }
      modalError.value = ''
      showModal.value = true
    }

    const closeModal = () => {
      showModal.value = false
      editingTransaction.value = null
    }

    const startEditTransaction = (tx) => {
      editingTransaction.value = tx
      modalType.value = tx.type
      form.value = {
        amount: Math.abs(tx.amount), // en positivo para el input
        date: formatDateForInput(tx.date),
        description: tx.description,
        tags: tx.tags || '',
        account_id: tx.account_id,
        category_id: tx.category_id,
        installments_total: tx.installments_total || 1
      }
      modalError.value = ''
      showModal.value = true
    }

    const saveTransaction = async () => {
      formLoading.value = true
      modalError.value = ''

      // Validar cuenta de pago seleccionada
      if (!form.value.account_id || parseInt(form.value.account_id) <= 0) {
        modalError.value = 'Por favor selecciona una Cuenta / Método de Pago.'
        formLoading.value = false
        return
      }

      // Validar monto mayor a cero
      if (!form.value.amount || parseFloat(form.value.amount) <= 0) {
        modalError.value = 'Por favor ingresa un monto mayor a cero.'
        formLoading.value = false
        return
      }

      // Sanitizar la fecha al formato estricto YYYY-MM-DD exigido por HTML5
      form.value.date = formatDateForInput(form.value.date)

      const token = localStorage.getItem('token')
      const isEdit = !!editingTransaction.value
      const url = isEdit 
        ? `${API_BASE}/transactions.php?id=${editingTransaction.value.id}`
        : `${API_BASE}/transactions.php`
      
      try {
        const response = await fetch(url, {
          method: isEdit ? 'PUT' : 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`
          },
          body: JSON.stringify({
            ...form.value,
            type: modalType.value
          })
        })

        const responseText = await response.text()
        let data
        try {
          data = JSON.parse(responseText)
        } catch (e) {
          const cleanMsg = responseText.replace(/<[^>]*>?/gm, '').trim()
          throw new Error(cleanMsg.length > 0 ? cleanMsg.substring(0, 150) : 'Error inesperado en el servidor.')
        }

        if (!response.ok) {
          throw new Error(data.error || 'Error al guardar la transacción.')
        }

        closeModal()
        await fetchData() // Recargar datos de saldos y transacciones
      } catch (err) {
        modalError.value = err.message
      } finally {
        formLoading.value = false
      }
    }

    // Función para comprimir imágenes antes de subir
    const compressImage = (file, maxWidth = 1200, maxHeight = 1200) => {
      return new Promise((resolve) => {
        const reader = new FileReader()
        reader.readAsDataURL(file)
        reader.onload = (event) => {
          const img = new Image()
          img.src = event.target.result
          img.onload = () => {
            const canvas = document.createElement('canvas')
            let width = img.width
            let height = img.height

            if (width > height) {
              if (width > maxWidth) {
                height = Math.round((height * maxWidth) / width)
                width = maxWidth
              }
            } else {
              if (height > maxHeight) {
                width = Math.round((width * maxHeight) / height)
                height = maxHeight
              }
            }

            canvas.width = width
            canvas.height = height
            const ctx = canvas.getContext('2d')
            ctx.drawImage(img, 0, 0, width, height)

            canvas.toBlob((blob) => {
              const compressedFile = new File([blob], file.name, {
                type: 'image/jpeg',
                lastModified: Date.now()
              })
              resolve(compressedFile)
            }, 'image/jpeg', 0.75) // Calidad del 75%
          }
        }
      })
    }

    // Escáner de recibos con IA
    const handleReceiptScan = async (event) => {
      const file = event.target.files[0]
      if (!file) return

      aiLoading.value = true
      
      let finalFile = file
      try {
        finalFile = await compressImage(file)
      } catch (err) {
        console.error('Error al comprimir imagen:', err)
      }

      const token = localStorage.getItem('token')

      const formData = new FormData()
      formData.append('receipt', finalFile)

      try {
        const customApiKey = localStorage.getItem('gemini_api_key') || ''
        const headers = {
          'Authorization': `Bearer ${token}`
        }
        if (customApiKey) {
          headers['X-Gemini-API-Key'] = customApiKey
        }

        const response = await fetch(`${API_BASE}/ai.php?action=scan_receipt`, {
          method: 'POST',
          headers,
          body: formData
        })

        if (!response.ok) {
          const errData = await response.json()
          throw new Error(errData.error || 'Error al procesar el recibo.')
        }

        const result = await response.json()

        // Mapear categoría sugerida al ID de la categoría correspondiente
        let matchedCatId = null
        if (result.categoria_sugerida) {
          const matched = categories.value.find(c => 
            c.name.toLowerCase().includes(result.categoria_sugerida.toLowerCase())
          )
          if (matched) matchedCatId = matched.id
        }

        // Abrir el modal y precargar los datos extraídos por la IA
        modalType.value = 'egreso'
        form.value = {
          amount: result.monto || '',
          date: result.fecha || new Date().toISOString().split('T')[0],
          description: `IA: ${result.comercio || ''} - ${result.descripcion || ''}`,
          account_id: accounts.value[0]?.id || '',
          category_id: matchedCatId,
          installments_total: 1
        }
        modalError.value = ''
        showModal.value = true

      } catch (err) {
        alert('Error IA: ' + err.message)
      } finally {
        aiLoading.value = false
        // Limpiar el input file
        event.target.value = ''
      }
    }

    // Computeds
    const filteredCategories = computed(() => {
      return categories.value.filter(c => c.type === modalType.value)
    })

    const isCreditCardSelected = computed(() => {
      const selectedAcc = accounts.value.find(a => a.id === form.value.account_id)
      return selectedAcc && selectedAcc.type === 'tarjeta_credito'
    })

    const donutSectors = computed(() => {
      const totalExpenses = totals.value.egresos
      if (totalExpenses <= 0) return []

      let accumulatedOffset = 0
      const circumference = 2 * Math.PI * 45 // 282.74

      const sectors = categoriesReport.value.map(cat => {
        const percent = cat.total / totalExpenses
        const length = percent * circumference
        const offset = -accumulatedOffset
        accumulatedOffset += length

        return {
          name: cat.name,
          total: cat.total,
          color: cat.color,
          percentage: Math.round(percent * 100),
          strokeDashArray: `${length} ${circumference}`,
          strokeDashOffset: offset
        }
      })
      console.log('DIAGNOSTICO DONA:', JSON.stringify(sectors), 'TOTAL:', totalExpenses)
      return sectors
    })

    const totalAccountsBalance = computed(() => {
      return accounts.value.reduce((sum, acc) => {
        const val = parseFloat(acc.balance) || 0
        if (acc.type === 'tarjeta_credito' || acc.type === 'prestamo_pagar') {
          return sum - val
        }
        return sum + val
      }, 0)
    })

    const budgetExceededWarning = computed(() => {
      if (modalType.value !== 'egreso' || !form.value.category_id || !form.value.amount) return null
      const matchedBudget = categoryBudgets.value.find(b => b.category_id === parseInt(form.value.category_id))
      if (!matchedBudget) return null

      const limit = parseFloat(matchedBudget.limit)
      const spent = parseFloat(matchedBudget.spent)
      const typingAmount = parseFloat(form.value.amount)
      const newTotal = spent + typingAmount
      const percentage = Math.round((newTotal / limit) * 100)

      if (newTotal > limit) {
        return `🚨 ¡Alerta de Presupuesto! Con este gasto de ${formatCurrency(typingAmount)} superarás el 100% de tu límite asignado (${formatCurrency(newTotal)} de ${formatCurrency(limit)}).`
      } else if (percentage >= 80) {
        return `⚠️ ¡Alerta! Con este gasto alcanzarás el ${percentage}% de tu presupuesto mensual para esta categoría (${formatCurrency(newTotal)} de ${formatCurrency(limit)}).`
      }
      return null
    })

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

    const formatDate = (dateStr) => {
      const options = { day: 'numeric', month: 'short' }
      return new Date(dateStr + 'T00:00:00').toLocaleDateString('es-ES', options)
    }

    const getPercentage = (amount) => {
      if (totals.value.egresos === 0) return 0
      return Math.round((amount / totals.value.egresos) * 100)
    }

    const truncateText = (text, maxLen) => {
      if (!text) return ''
      return text.length > maxLen ? text.substring(0, maxLen) + '...' : text
    }

    const handleUrlAction = () => {
      const action = route.query.action
      if (action === 'voice') {
        startVoiceDictation()
      } else if (action === 'scan') {
        if (receiptInput.value) {
          receiptInput.value.click()
        }
      } else if (action === 'income') {
        openTransactionModal('ingreso')
      } else if (action === 'expense') {
        openTransactionModal('egreso')
      }
    }

    watch(() => route.query.action, () => {
      handleUrlAction()
    })

    const handleWorkspaceChanged = () => {
      updateWorkspaceInfo()
      fetchData()
    }

    onMounted(() => {
      checkUser()
      updateWorkspaceInfo()
      fetchData()
      window.addEventListener('workspace-changed', handleWorkspaceChanged)
      window.addEventListener('user-updated', updateWorkspaceInfo)
      // Ejecutar acción de URL si existe después de cargar
      setTimeout(() => {
        handleUrlAction()
      }, 300)
    })

    onUnmounted(() => {
      window.removeEventListener('workspace-changed', handleWorkspaceChanged)
      window.removeEventListener('user-updated', updateWorkspaceInfo)
    })

    const toggleCategoryFilter = (catName) => {
      if (selectedCategoryFilter.value === catName) {
        selectedCategoryFilter.value = null
      } else {
        selectedCategoryFilter.value = catName
      }
    }

    const applyDateFilters = () => {
      selectedCategoryFilter.value = null
      fetchData()
    }

    const getActiveFilterLabel = computed(() => {
      if (filterRangeMode.value === 'month') {
        const months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']
        return `${months[filterMonth.value - 1]} ${filterYear.value}`
      } else if (filterRangeMode.value === 'week') {
        return 'Últimos 7 días'
      } else if (filterRangeMode.value === 'custom') {
        if (filterStartDate.value && filterEndDate.value) {
          return `${filterStartDate.value} al ${filterEndDate.value}`
        }
        return 'Rango Personalizado'
      }
      return ''
    })

    const filteredTransactions = computed(() => {
      if (!selectedCategoryFilter.value) {
        return transactions.value
      }
      return transactions.value.filter(tx => {
        const catName = tx.category_name || 'Sin Categoría'
        return catName.toLowerCase() === selectedCategoryFilter.value.toLowerCase()
      })
    })

    return {
      activeWorkspace,
      businessName,
      showVoiceModal,
      isRecording,
      voiceTranscript,
      voiceProcessing,
      startVoiceDictation,
      startListening,
      stopListeningAndProcess,
      closeVoiceModal,
      categorySearchQuery,
      searchedCategories,
      createCategoryOnTheFly,
      getCategoryNameById,
      insightsData,
      downloadReport,
      receiptInput,
      user,
      accounts,
      categories,
      transactions,
      categoriesReport,
      reminders,
      categoryBudgets,
      activeSector,
      editingTransaction,
      totals,
      showModal,
      modalType,
      form,
      loading,
      formLoading,
      modalError,
      aiLoading,
      filteredCategories,
      isCreditCardSelected,
      donutSectors,
      totalAccountsBalance,
      budgetExceededWarning,
      openTransactionModal,
      closeModal,
      startEditTransaction,
      saveTransaction,
      deleteTransaction,
      completeReminder,
      handleReceiptScan,
      formatCurrency,
      formatDate,
      getPercentage,
      truncateText,
      showFilterDrawer,
      filterRangeMode,
      filterMonth,
      filterYear,
      filterStartDate,
      filterEndDate,
      selectedCategoryFilter,
      toggleCategoryFilter,
      applyDateFilters,
      getActiveFilterLabel,
      filteredTransactions
    }
  }
}
</script>

<style scoped>
.dashboard-container {
  display: flex;
  flex-direction: column;
  gap: 24px;
  animation: fadeIn 0.4s ease-out;
}

/* Panel de Recordatorios */
.reminders-panel {
  border-color: rgba(239, 68, 68, 0.25);
  background: linear-gradient(135deg, rgba(30, 41, 59, 0.45), rgba(239, 68, 68, 0.05));
  padding: 16px 20px;
}

.reminders-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 16px;
  font-weight: 700;
  color: var(--color-danger);
  margin-bottom: 12px;
}

.bell-icon {
  display: inline-flex;
  animation: shake 2.5s infinite;
}

.bell-icon svg {
  width: 18px;
  height: 18px;
}

.reminders-horizontal-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.reminder-item-bubble {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 14px;
  background: rgba(11, 15, 25, 0.35);
  border-radius: var(--radius-sm);
  border: 1px solid rgba(255,255,255,0.03);
  gap: 10px;
  width: 100%;
  box-sizing: border-box;
}

.rem-info {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  flex: 1;
  overflow: hidden;
}

.rem-title-text {
  font-size: 14px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.rem-badge {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  padding: 2px 6px;
  border-radius: 4px;
  align-self: flex-start;
  flex-shrink: 0;
}

.badge-tarjeta {
  background: rgba(239, 68, 68, 0.15);
  color: var(--color-danger);
}

.badge-servicio {
  background: rgba(6, 182, 212, 0.15);
  color: var(--color-secondary);
}

.badge-personalizado {
  background: rgba(245, 158, 11, 0.15);
  color: var(--color-warning);
}

.rem-title-text {
  font-size: 14px;
  font-weight: 600;
}

.rem-desc {
  font-size: 12px;
  color: var(--text-secondary);
}

.rem-action {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}

.due-date {
  font-size: 12px;
  color: var(--text-secondary);
}

.btn-check {
  background: rgba(16, 185, 129, 0.15);
  border: 1px solid rgba(16, 185, 129, 0.3);
  color: var(--color-success);
  width: 32px;
  height: 32px;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: var(--transition-smooth);
}

.btn-check:hover {
  background: var(--color-success);
  color: #fff;
  transform: scale(1.1);
}

.btn-check svg {
  width: 14px;
  height: 14px;
}

@keyframes shake {
  0% { transform: rotate(0); }
  5% { transform: rotate(10deg); }
  10% { transform: rotate(-10deg); }
  15% { transform: rotate(10deg); }
  20% { transform: rotate(-10deg); }
  25% { transform: rotate(0); }
  100% { transform: rotate(0); }
}

.dashboard-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.trial-badge {
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--color-primary);
  background: rgba(10, 132, 255, 0.08);
  border: 1px solid rgba(10, 132, 255, 0.15);
}
body.light-theme .trial-badge {
  background: rgba(0, 122, 255, 0.06);
  border-color: rgba(0, 122, 255, 0.12);
  color: #007aff;
}

.balance-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}

@media (min-width: 768px) {
  .balance-grid {
    grid-template-columns: 2fr 1fr 1fr;
  }
}

.balance-card {
  display: flex;
  flex-direction: column;
  justify-content: center;
  position: relative;
  overflow: hidden;
}

.balance-card.total {
  background: var(--card-bg);
}

.balance-card.income {
  border-color: rgba(48, 209, 88, 0.15);
}

.balance-card.expense {
  border-color: rgba(255, 69, 58, 0.15);
}

.card-label {
  font-size: 14px;
  color: var(--text-secondary);
  font-weight: 500;
  margin-bottom: 6px;
}

.balance-card .amount {
  font-size: 32px;
  font-weight: 800;
  letter-spacing: -0.5px;
}

.balance-card.income .amount, .balance-card.expense .amount {
  font-size: 24px;
}

.card-detail {
  font-size: 12px;
  color: var(--text-muted);
  margin-top: 4px;
}

.card-header-icon {
  position: absolute;
  top: 15px;
  right: 15px;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.income .card-header-icon {
  background: rgba(16, 185, 129, 0.1);
  color: var(--color-success);
}

.expense .card-header-icon {
  background: rgba(239, 68, 68, 0.1);
  color: var(--color-danger);
}

.card-header-icon svg {
  width: 18px;
  height: 18px;
}

/* Acciones rápidas */
.actions-container {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
}

@media (min-width: 576px) {
  .actions-container {
    grid-template-columns: 1fr 1fr 1.2fr;
  }
}

.btn-icon {
  width: 20px;
  height: 20px;
}

.btn-ai-scan {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  cursor: pointer;
  padding: 12px 24px;
  font-weight: 500;
  border-radius: var(--radius-sm);
  border: 1px solid rgba(94, 92, 230, 0.2);
  background: rgba(94, 92, 230, 0.08);
  color: var(--color-accent);
  text-align: center;
  transition: var(--transition-smooth);
}

.btn-ai-scan:hover {
  background: rgba(94, 92, 230, 0.15);
  border-color: var(--color-accent);
  box-shadow: 0 0 12px rgba(94, 92, 230, 0.15);
}

.hidden-input {
  display: none;
}

.spinner {
  width: 20px;
  height: 20px;
  border: 3px solid rgba(255,255,255,0.1);
  border-radius: 50%;
  border-top-color: var(--color-primary);
  animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* Gráficos e Historial Grid */
.dashboard-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 24px;
}

@media (min-width: 992px) {
  .dashboard-grid {
    grid-template-columns: 1fr 1.2fr;
  }
}

.section-title {
  font-size: 18px;
  font-weight: 700;
  margin-bottom: 20px;
}

.empty-state {
  display: flex;
  justify-content: center;
  align-items: center;
  height: 150px;
  color: var(--text-muted);
  font-size: 14px;
}

/* Progreso de categorías */
.categories-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.category-progress-item {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.category-meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  width: 100%;
}

.category-name-badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-weight: 500;
  font-size: 14.5px;
  min-width: 0;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.color-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}

.category-amount {
  font-weight: 600;
  font-size: 14.5px;
  flex-shrink: 0;
  white-space: nowrap;
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
  transition: width 1s ease-in-out;
}

.category-percent {
  font-size: 12px;
  color: var(--text-muted);
  align-self: flex-end;
}

/* Historial de transacciones */
.transactions-section {
  display: flex;
  flex-direction: column;
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.section-header .section-title {
  margin-bottom: 0;
}

.header-link {
  color: var(--color-primary);
  text-decoration: none;
  font-size: 14px;
  font-weight: 600;
}

.header-link:hover {
  text-decoration: underline;
}

.transactions-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
  width: 100%;
}

.transaction-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 14px;
  background: var(--bg-primary);
  border: 1px solid var(--card-border);
  border-radius: var(--radius-sm);
  transition: var(--transition-smooth);
  width: 100%;
  box-sizing: border-box;
  gap: 8px;
}

.transaction-item:hover {
  background: var(--bg-tertiary);
}

.tx-info {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  flex: 1;
  overflow: hidden;
}

.tx-icon {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.icon-svg {
  width: 18px;
  height: 18px;
}

.tx-title {
  font-size: 14px;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.tx-meta {
  font-size: 11.5px;
  color: var(--text-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.tx-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.tx-amount {
  font-weight: 700;
  font-size: 14px;
  white-space: nowrap;
  flex-shrink: 0;
}

.btn-delete, .btn-edit-action-small {
  background: transparent;
  border: none;
  color: var(--text-muted);
  cursor: pointer;
  transition: var(--transition-smooth);
  padding: 4px;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.btn-delete:hover {
  color: var(--color-danger);
  background: rgba(239, 68, 68, 0.15);
}

.btn-edit-action-small:hover {
  color: var(--color-primary);
  background: rgba(10, 132, 255, 0.15);
}

.btn-delete svg, .btn-edit-action-small svg {
  width: 15px;
  height: 15px;
}

/* Estilos de modal */
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

.card-installments {
  animation: fadeIn 0.3s ease;
  background: rgba(99, 102, 241, 0.05);
  padding: 12px;
  border-radius: var(--radius-sm);
  border: 1px dashed rgba(99, 102, 241, 0.3);
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

/* Nuevo layout del reporte de gastos y gráfico de dona SVG */
.chart-content-layout {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 24px;
}

@media (min-width: 768px) {
  .chart-content-layout {
    flex-direction: row;
    justify-content: space-around;
    align-items: center;
    gap: 30px;
  }
}

.donut-chart-wrapper {
  position: relative;
  width: 100%;
  max-width: 200px;
  display: flex;
  justify-content: center;
  align-items: center;
}

.donut-chart-svg {
  width: 100%;
  height: 100%;
  transform: rotate(-90deg); /* Empezar en la parte superior */
}

.donut-ring {
  stroke: rgba(255, 255, 255, 0.04);
}

.donut-segment {
  transition: stroke-width 0.2s, filter 0.2s;
}

.donut-segment:hover {
  stroke-width: 15;
  filter: drop-shadow(0 0 4px rgba(255, 255, 255, 0.15));
}

.donut-text {
  transform: rotate(90deg); /* Revertir la rotación para leer el texto horizontalmente */
  transform-origin: 60px 60px;
  fill: var(--text-primary);
  font-family: inherit;
}

.donut-center-label {
  font-size: 7px;
  fill: var(--text-secondary);
  font-weight: 600;
}

.donut-center-val {
  font-size: 9px;
  font-weight: 800;
  fill: var(--text-primary);
}

.donut-center-sub {
  font-size: 7px;
  font-weight: 700;
  fill: var(--color-secondary);
}

.warning-msg {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  color: var(--color-warning);
  background: rgba(245, 158, 11, 0.08);
  padding: 10px 14px;
  border-radius: var(--radius-sm);
  border: 1px solid rgba(245, 158, 11, 0.2);
  font-size: 13px;
  line-height: 1.5;
}

/* Modificar ancho de la lista en layout */
.chart-content-layout .categories-list {
  flex: 1;
  width: 100%;
}

/* ==========================================================================
   ESTILOS MINIMALISTAS APPLE HIG (Sin colores chillones)
   ========================================================================== */

/* Hacer los balances de las tarjetas completamente monocromáticos */
.balance-card .amount {
  color: var(--text-primary) !important;
}

/* Modificar botones de acción para que sean transparentes y vidriosos estilo iOS */
.actions-container .btn-primary {
  background: rgba(255, 69, 58, 0.08) !important;
  border: 1px solid rgba(255, 69, 58, 0.15) !important;
  color: var(--color-danger) !important;
  box-shadow: none !important;
}
body.light-theme .actions-container .btn-primary {
  background: rgba(255, 59, 48, 0.08) !important;
  color: #ff3b30 !important;
  border-color: rgba(255, 59, 48, 0.15) !important;
}
.actions-container .btn-primary:hover {
  background: rgba(255, 69, 58, 0.15) !important;
  border-color: var(--color-danger) !important;
  transform: scale(1.01) !important;
}

.actions-container .btn-success {
  background: rgba(48, 209, 88, 0.08) !important;
  border: 1px solid rgba(48, 209, 88, 0.15) !important;
  color: var(--color-success) !important;
  box-shadow: none !important;
}
body.light-theme .actions-container .btn-success {
  background: rgba(52, 199, 89, 0.08) !important;
  color: #34c759 !important;
  border-color: rgba(52, 199, 89, 0.15) !important;
}
.actions-container .btn-success:hover {
  background: rgba(48, 209, 88, 0.15) !important;
  border-color: var(--color-success) !important;
  transform: scale(1.01) !important;
}

.actions-container .btn-ai-scan {
  background: rgba(94, 92, 230, 0.08) !important;
  border: 1px solid rgba(94, 92, 230, 0.15) !important;
  color: var(--color-accent) !important;
  box-shadow: none !important;
}
body.light-theme .actions-container .btn-ai-scan {
  background: rgba(88, 86, 214, 0.08) !important;
  color: #5856d6 !important;
  border-color: rgba(88, 86, 214, 0.15) !important;
}
.actions-container .btn-ai-scan:hover {
  background: rgba(94, 92, 230, 0.15) !important;
  border-color: var(--color-accent) !important;
}
</style>
