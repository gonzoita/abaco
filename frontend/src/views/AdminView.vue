<template>
  <div class="admin-container">
    <!-- Encabezado de Administración -->
    <div class="admin-header">
      <div>
        <h1 class="page-title text-gradient-purple">Panel de Administración</h1>
        <p class="page-subtitle">Supervisa las métricas del sistema y administra cuentas y suscripciones.</p>
      </div>
    </div>

    <!-- Sección de Tarjetas KPI -->
    <div class="metrics-grid" v-if="metrics">
      <!-- KPIs Generales -->
      <div class="kpi-card glass-card">
        <div class="kpi-icon-wrapper purple">
          <i class="fa-solid fa-users"></i>
        </div>
        <div class="kpi-details">
          <span class="kpi-label">Usuarios Totales</span>
          <h2 class="kpi-value">{{ metrics.total_users }}</h2>
        </div>
      </div>

      <div class="kpi-card glass-card">
        <div class="kpi-icon-wrapper green">
          <i class="fa-solid fa-user-check"></i>
        </div>
        <div class="kpi-details">
          <span class="kpi-label">Activos / Inactivos</span>
          <h2 class="kpi-value">
            {{ metrics.active_users }} <span class="kpi-subvalue">/ {{ metrics.inactive_users }}</span>
          </h2>
        </div>
      </div>

      <div class="kpi-card glass-card">
        <div class="kpi-icon-wrapper blue">
          <i class="fa-solid fa-star"></i>
        </div>
        <div class="kpi-details">
          <span class="kpi-label">Suscripciones (Premium/Trial/Exp)</span>
          <h2 class="kpi-value">
            {{ metrics.subscriptions.active }} <span class="kpi-subvalue">/ {{ metrics.subscriptions.trial }} / {{ metrics.subscriptions.expired }}</span>
          </h2>
        </div>
      </div>

      <div class="kpi-card glass-card">
        <div class="kpi-icon-wrapper orange">
          <i class="fa-solid fa-database"></i>
        </div>
        <div class="kpi-details">
          <span class="kpi-label">Volumen Transacciones</span>
          <h2 class="kpi-value">{{ metrics.total_transactions }}</h2>
        </div>
      </div>
    </div>

    <!-- Cargando Métricas -->
    <div class="loading-state glass-card" v-else>
      <i class="fa-solid fa-circle-notch fa-spin"></i>
      <span>Cargando indicadores de salud del sistema...</span>
    </div>

    <!-- Panel de Gestión de Usuarios -->
    <div class="users-panel glass-card">
      <div class="panel-header">
        <h2 class="panel-title">Gestión de Cuentas de Usuario</h2>
        <div class="search-box">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input 
            type="text" 
            v-model="searchQuery" 
            placeholder="Buscar por nombre o correo..."
            @input="debounceSearch"
          />
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

      <!-- Listado de Usuarios -->
      <div class="table-container" v-if="!loadingUsers">
        <table class="users-table">
          <thead>
            <tr>
              <th>Usuario</th>
              <th>Fecha Registro</th>
              <th>Rol</th>
              <th>Suscripción</th>
              <th>Vencimiento</th>
              <th>Estado</th>
              <th style="text-align: center;">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in filteredUsers" :key="user.id">
              <!-- Info Usuario -->
              <td>
                <div class="user-info-cell">
                  <div class="user-avatar">
                    {{ getInitials(user.name) }}
                  </div>
                  <div>
                    <strong class="user-name">{{ user.name }}</strong>
                    <span class="user-email">{{ user.email }}</span>
                  </div>
                </div>
              </td>

              <!-- Fecha Registro -->
              <td>
                <span class="date-cell">{{ formatDate(user.created_at) }}</span>
              </td>

              <!-- Rol -->
              <td>
                <select v-model="user.role" class="admin-select select-role">
                  <option value="user">Usuario</option>
                  <option value="admin">Admin</option>
                </select>
              </td>

              <!-- Suscripción -->
              <td>
                <select v-model="user.subscription_status" class="admin-select" :class="user.subscription_status">
                  <option value="trial">Trial (Prueba)</option>
                  <option value="active">Active (Premium)</option>
                  <option value="expired">Expired (Vencido)</option>
                </select>
              </td>

              <!-- Expiración -->
              <td>
                <input 
                  type="date" 
                  v-model="user.subscription_expires_date" 
                  class="admin-date-input"
                />
              </td>

              <!-- Estado Activo / Suspendido (Toggle) -->
              <td>
                <div class="toggle-switch-wrapper">
                  <label class="switch">
                    <input 
                      type="checkbox" 
                      :checked="user.is_active === 1"
                      @change="toggleUserActive(user)"
                    />
                    <span class="slider round"></span>
                  </label>
                  <span class="status-label" :class="user.is_active === 1 ? 'active' : 'suspended'">
                    {{ user.is_active === 1 ? 'Activo' : 'Suspendido' }}
                  </span>
                </div>
              </td>

              <!-- Acciones -->
              <td>
                <div class="actions-cell">
                  <button 
                    class="btn-action save" 
                    @click="saveUserChanges(user)"
                    title="Guardar cambios"
                  >
                    <i class="fa-solid fa-floppy-disk"></i>
                  </button>
                  <button 
                    class="btn-action delete" 
                    @click="confirmDeleteUser(user)"
                    title="Eliminar usuario definitivamente"
                  >
                    <i class="fa-solid fa-trash-can"></i>
                  </button>
                </div>
              </td>
            </tr>

            <!-- Sin resultados -->
            <tr v-if="filteredUsers.length === 0">
              <td colspan="7" class="empty-table-cell">
                <i class="fa-solid fa-user-slash"></i>
                <p>No se encontraron usuarios registrados.</p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Cargando Tabla -->
      <div class="loading-state-table" v-else>
        <i class="fa-solid fa-circle-notch fa-spin"></i>
        <span>Consultando catálogo de usuarios...</span>
      </div>
    </div>

    <!-- Modal de Confirmación de Borrado Definitivo -->
    <div class="modal-overlay" v-if="userToDelete" @click.self="userToDelete = null">
      <div class="modal-card danger-alert glass-card">
        <div class="modal-header-danger">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <h3>Confirmar Eliminación Definitiva</h3>
        </div>
        <div class="modal-body">
          <p>¿Estás completamente seguro de que deseas eliminar la cuenta de <strong>{{ userToDelete.name }}</strong> ({{ userToDelete.email }})?</p>
          <p class="warning-text">⚠️ <strong>¡Esta acción es destructiva!</strong> Se borrarán en cascada de manera definitiva todas sus transacciones, cuentas bancarias, deudas, préstamos y presupuestos. Esta acción no se puede deshacer.</p>
        </div>
        <div class="modal-footer">
          <button class="btn-cancel" @click="userToDelete = null">Cancelar</button>
          <button class="btn-confirm-danger" @click="deleteUser" :disabled="deletingLoader">
            <span v-if="deletingLoader"><i class="fa-solid fa-circle-notch fa-spin"></i> Eliminando...</span>
            <span v-else>Eliminar Todo</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, onMounted, computed } from 'vue'

export default {
  name: 'AdminView',
  setup() {
    const API_BASE = window.location.hostname === 'localhost' 
      ? 'http://localhost/control-finanzas/backend/api' 
      : '/backend/api'

    const metrics = ref(null)
    const usersList = ref([])
    const searchQuery = ref('')
    const loadingUsers = ref(false)
    const successMsg = ref('')
    const errorMsg = ref('')
    
    // Control de Borrado
    const userToDelete = ref(null)
    const deletingLoader = ref(false)

    // Debounce de búsqueda
    let searchTimeout = null

    const getHeaders = () => {
      const token = localStorage.getItem('token')
      return {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      }
    }

    const loadMetrics = async () => {
      try {
        const response = await fetch(`${API_BASE}/admin.php?action=metrics`, {
          headers: getHeaders()
        })
        const data = await response.json()
        if (response.ok) {
          metrics.value = data
        } else {
          errorMsg.value = data.error || 'Error al cargar métricas'
        }
      } catch (err) {
        errorMsg.value = 'Error de red al conectar con el servidor.'
      }
    }

    const loadUsers = async (searchVal = '') => {
      loadingUsers.value = true
      try {
        const url = searchVal 
          ? `${API_BASE}/admin.php?action=users&search=${encodeURIComponent(searchVal)}`
          : `${API_BASE}/admin.php?action=users`
          
        const response = await fetch(url, {
          headers: getHeaders()
        })
        const data = await response.json()
        if (response.ok) {
          // Mapear fecha de vencimiento a formato input date
          usersList.value = data.map(u => {
            let dateStr = ''
            if (u.subscription_expires_at) {
              dateStr = u.subscription_expires_at.split(' ')[0]
            }
            return {
              ...u,
              subscription_expires_date: dateStr
            }
          })
        } else {
          errorMsg.value = data.error || 'Error al cargar usuarios'
        }
      } catch (err) {
        errorMsg.value = 'Error de conexión con el backend.'
      } finally {
        loadingUsers.value = false
      }
    }

    const debounceSearch = () => {
      clearTimeout(searchTimeout)
      searchTimeout = setTimeout(() => {
        loadUsers(searchQuery.value)
      }, 400)
    }

    const toggleUserActive = (user) => {
      user.is_active = user.is_active === 1 ? 0 : 1
    }

    const saveUserChanges = async (user) => {
      successMsg.value = ''
      errorMsg.value = ''
      
      const payload = {
        user_id: user.id,
        is_active: user.is_active,
        subscription_status: user.subscription_status,
        subscription_expires_at: user.subscription_expires_date ? `${user.subscription_expires_date} 23:59:59` : '',
        role: user.role
      }

      try {
        const response = await fetch(`${API_BASE}/admin.php?action=update_user`, {
          method: 'POST',
          headers: getHeaders(),
          body: JSON.stringify(payload)
        })
        const data = await response.json()
        if (response.ok) {
          successMsg.value = `Usuario ${user.name} actualizado con éxito.`
          loadMetrics()
          setTimeout(() => successMsg.value = '', 3000)
        } else {
          errorMsg.value = data.error || 'Error al actualizar usuario'
          // Revertir cambio de toggle activo localmente si falló
          loadUsers(searchQuery.value)
        }
      } catch (err) {
        errorMsg.value = 'Fallo de red al intentar guardar cambios.'
      }
    }

    const confirmDeleteUser = (user) => {
      userToDelete.value = user
    }

    const deleteUser = async () => {
      if (!userToDelete.value) return
      
      deletingLoader.value = true
      successMsg.value = ''
      errorMsg.value = ''

      try {
        const response = await fetch(`${API_BASE}/admin.php?action=delete_user`, {
          method: 'POST',
          headers: getHeaders(),
          body: JSON.stringify({ user_id: userToDelete.value.id })
        })
        const data = await response.json()
        if (response.ok) {
          successMsg.value = `Cuenta de ${userToDelete.value.name} eliminada en cascada.`
          userToDelete.value = null
          loadMetrics()
          loadUsers(searchQuery.value)
          setTimeout(() => successMsg.value = '', 4000)
        } else {
          errorMsg.value = data.error || 'Error al eliminar usuario'
        }
      } catch (err) {
        errorMsg.value = 'Error al procesar eliminación en cascada.'
      } finally {
        deletingLoader.value = false
      }
    }

    // Helper functions
    const getInitials = (name) => {
      if (!name) return 'U'
      const parts = name.trim().split(' ')
      if (parts.length >= 2) {
        return (parts[0][0] + parts[1][0]).toUpperCase()
      }
      return name[0].toUpperCase()
    }

    const formatDate = (dateStr) => {
      if (!dateStr) return '-'
      const date = new Date(dateStr)
      return date.toLocaleDateString('es-ES', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
      })
    }

    const filteredUsers = computed(() => {
      return usersList.value
    })

    onMounted(() => {
      loadMetrics()
      loadUsers()
    })

    return {
      metrics,
      searchQuery,
      filteredUsers,
      loadingUsers,
      successMsg,
      errorMsg,
      userToDelete,
      deletingLoader,
      debounceSearch,
      toggleUserActive,
      saveUserChanges,
      confirmDeleteUser,
      deleteUser,
      getInitials,
      formatDate
    }
  }
}
</script>

<style scoped>
.admin-container {
  padding: 30px 24px;
  max-width: 1200px;
  margin: 0 auto;
}

.admin-header {
  margin-bottom: 30px;
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

/* KPIs Grid */
.metrics-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 20px;
  margin-bottom: 30px;
}

.kpi-card {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 20px;
  border-radius: var(--radius-md);
  transition: var(--transition-smooth);
}

.kpi-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
}

.kpi-icon-wrapper {
  width: 46px;
  height: 46px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
}

.kpi-icon-wrapper.purple {
  background: rgba(142, 68, 173, 0.15);
  color: #9b59b6;
}

.kpi-icon-wrapper.green {
  background: rgba(46, 204, 113, 0.15);
  color: #2ecc71;
}

.kpi-icon-wrapper.blue {
  background: rgba(52, 152, 219, 0.15);
  color: #3498db;
}

.kpi-icon-wrapper.orange {
  background: rgba(230, 126, 34, 0.15);
  color: #e67e22;
}

.kpi-label {
  font-size: 12px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  display: block;
}

.kpi-value {
  font-size: 22px;
  font-weight: 700;
  margin: 4px 0 0 0;
  color: var(--text-primary);
}

.kpi-subvalue {
  font-size: 13.5px;
  color: var(--text-muted);
  font-weight: 400;
}

/* Panel de Gestión */
.users-panel {
  padding: 24px;
  border-radius: var(--radius-md);
}

.panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
  gap: 20px;
  flex-wrap: wrap;
}

.panel-title {
  font-size: 18px;
  font-weight: 700;
  margin: 0;
  color: var(--text-primary);
}

.search-box {
  position: relative;
  width: 100%;
  max-width: 320px;
}

.search-box i {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-muted);
  font-size: 14px;
}

.search-box input {
  width: 100%;
  padding: 10px 12px 10px 36px;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid var(--card-border);
  border-radius: 10px;
  color: var(--text-primary);
  font-size: 13.5px;
  transition: var(--transition-smooth);
}

.search-box input:focus {
  background: rgba(255, 255, 255, 0.08);
  border-color: var(--color-primary);
}

body.light-theme .search-box input {
  background: #f2f2f7;
  color: #000000;
  border-color: rgba(0, 0, 0, 0.08);
}

body.light-theme .search-box input:focus {
  background: #ffffff;
}

/* Tabla */
.table-container {
  overflow-x: auto;
}

.users-table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
}

.users-table th {
  padding: 12px 16px;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-muted);
  text-transform: uppercase;
  border-bottom: 1px solid var(--card-border);
}

.users-table td {
  padding: 16px;
  border-bottom: 1px solid var(--card-border);
  font-size: 14px;
}

.user-info-cell {
  display: flex;
  align-items: center;
  gap: 12px;
}

.user-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--color-primary);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 13.5px;
}

.user-name {
  display: block;
  font-weight: 600;
  color: var(--text-primary);
}

.user-email {
  display: block;
  font-size: 12px;
  color: var(--text-muted);
  margin-top: 2px;
}

.date-cell {
  color: var(--text-secondary);
}

/* Selects */
.admin-select {
  padding: 6px 12px;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid var(--card-border);
  border-radius: 8px;
  color: var(--text-primary);
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: var(--transition-smooth);
}

.admin-select.active {
  background: rgba(46, 204, 113, 0.15);
  color: #2ecc71;
  border-color: rgba(46, 204, 113, 0.3);
}

.admin-select.trial {
  background: rgba(52, 152, 219, 0.15);
  color: #3498db;
  border-color: rgba(52, 152, 219, 0.3);
}

.admin-select.expired {
  background: rgba(231, 76, 60, 0.15);
  color: #e74c3c;
  border-color: rgba(231, 76, 60, 0.3);
}

body.light-theme .admin-select {
  background: #f2f2f7;
  color: #000000;
  border-color: rgba(0, 0, 0, 0.08);
}

/* Expiration inputs */
.admin-date-input {
  padding: 6px 10px;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid var(--card-border);
  border-radius: 8px;
  color: var(--text-primary);
  font-size: 13px;
}

body.light-theme .admin-date-input {
  background: #f2f2f7;
  color: #000000;
  border-color: rgba(0, 0, 0, 0.08);
}

/* Toggle switch styling */
.toggle-switch-wrapper {
  display: flex;
  align-items: center;
  gap: 10px;
}

.switch {
  position: relative;
  display: inline-block;
  width: 44px;
  height: 24px;
}

.switch input {
  opacity: 0;
  width: 0;
  height: 0;
}

.slider {
  position: absolute;
  cursor: pointer;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(255, 255, 255, 0.15);
  transition: .3s;
}

.slider:before {
  position: absolute;
  content: "";
  height: 18px;
  width: 18px;
  left: 3px;
  bottom: 3px;
  background-color: white;
  transition: .3s;
}

input:checked + .slider {
  background-color: #30d158;
}

input:checked + .slider:before {
  transform: translateX(20px);
}

.slider.round {
  border-radius: 34px;
}

.slider.round:before {
  border-radius: 50%;
}

.status-label {
  font-size: 12.5px;
  font-weight: 500;
}

.status-label.active {
  color: #30d158;
}

.status-label.suspended {
  color: var(--color-danger);
}

/* Actions cell */
.actions-cell {
  display: flex;
  justify-content: center;
  gap: 8px;
}

.btn-action {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  border: none;
  font-size: 13px;
  transition: var(--transition-smooth);
}

.btn-action.save {
  background: rgba(48, 209, 88, 0.15);
  color: #30d158;
}

.btn-action.save:hover {
  background: #30d158;
  color: white;
}

.btn-action.delete {
  background: rgba(255, 69, 58, 0.15);
  color: #ff453a;
}

.btn-action.delete:hover {
  background: #ff453a;
  color: white;
}

/* Alert Boxes */
.alert-box {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  border-radius: 8px;
  font-size: 14px;
  margin-bottom: 20px;
}

.alert-box.success {
  background: rgba(48, 209, 88, 0.1);
  border: 1px solid rgba(48, 209, 88, 0.2);
  color: #30d158;
}

.alert-box.danger {
  background: rgba(255, 69, 58, 0.1);
  border: 1px solid rgba(255, 69, 58, 0.2);
  color: #ff453a;
}

/* Modal confirm block */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 20000;
  padding: 20px;
}

.modal-card {
  width: 100%;
  max-width: 440px;
  border-radius: var(--radius-lg);
  padding: 24px;
  background: rgba(30, 30, 32, 0.85);
  border: 1px solid var(--card-border);
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
}

body.light-theme .modal-card {
  background: #ffffff;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
}

.modal-header-danger {
  display: flex;
  align-items: center;
  gap: 12px;
  color: #ff453a;
  margin-bottom: 16px;
}

.modal-header-danger h3 {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
}

.modal-header-danger i {
  font-size: 22px;
}

.modal-body p {
  font-size: 14.5px;
  line-height: 1.5;
  color: var(--text-secondary);
  margin-top: 0;
}

.warning-text {
  background: rgba(255, 69, 58, 0.08);
  border: 1px solid rgba(255, 69, 58, 0.15);
  border-radius: 8px;
  padding: 12px;
  font-size: 13px !important;
  color: #ff453a !important;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 24px;
}

.btn-cancel {
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid var(--card-border);
  color: var(--text-primary);
  padding: 10px 20px;
  border-radius: 8px;
  font-size: 13.5px;
  cursor: pointer;
}

.btn-confirm-danger {
  background: #ff453a;
  color: white;
  border: none;
  padding: 10px 20px;
  border-radius: 8px;
  font-size: 13.5px;
  font-weight: 600;
  cursor: pointer;
}

.btn-confirm-danger:hover {
  background: #e03e35;
}

.loading-state, .loading-state-table {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 40px;
  color: var(--text-muted);
}

.loading-state-table {
  padding: 60px 0;
}

.loading-state i, .loading-state-table i {
  font-size: 26px;
  color: var(--color-primary);
}

.empty-table-cell {
  text-align: center;
  padding: 60px 0;
  color: var(--text-muted);
}

.empty-table-cell i {
  font-size: 32px;
  margin-bottom: 12px;
}

.empty-table-cell p {
  margin: 0;
  font-size: 14px;
}
</style>
