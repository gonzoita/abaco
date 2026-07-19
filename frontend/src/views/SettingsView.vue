<template>
  <div class="settings-container">
    <div class="view-header">
      <h1 class="view-title">Configuración y Categorías</h1>
      <p class="view-subtitle">Gestiona tu perfil, recordatorios, descarga tus respaldos y edita tus categorías</p>
    </div>

    <!-- Primera fila: Ajustes y Respaldo / Restablecimiento -->
    <div class="settings-grid">
      <!-- Ajustes de Perfil -->
      <div class="glass-card settings-card">
        <h3 class="card-title text-gradient-purple">
          <i class="fa-solid fa-user-gear"></i> Ajustes de Perfil
        </h3>
        
        <form @submit.prevent="saveSettings" class="settings-form">
          <div class="form-group">
            <label for="set-name">Nombre</label>
            <input type="text" id="set-name" v-model="form.name" required />
          </div>

          <div class="form-group">
            <label for="set-email">Correo Electrónico (No modificable)</label>
            <input type="email" id="set-email" :value="form.email" disabled class="input-disabled" />
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="set-currency">Moneda Predeterminada</label>
              <select id="set-currency" v-model="form.currency">
                <option value="COP">Peso Colombiano (COP)</option>
                <option value="USD">Dólar Estadounidense (USD)</option>
                <option value="MXN">Peso Mexicano (MXN)</option>
                <option value="EUR">Euro (EUR)</option>
              </select>
            </div>

            <div class="form-group">
              <label for="set-reminders">Anticipación Recordatorios</label>
              <select id="set-reminders" v-model.number="form.reminder_days_before">
                <option value="1">1 día antes</option>
                <option value="3">3 días antes</option>
                <option value="5">5 días antes</option>
                <option value="7">7 días antes</option>
                <option value="10">10 días antes</option>
              </select>
            </div>
          </div>

          <div class="form-group" style="margin-top: 10px; margin-bottom: 20px;">
            <label>Tema de Pantalla</label>
            <div style="display: flex; gap: 10px; margin-top: 4px;">
              <button type="button" class="btn-secondary" :class="{ active: currentTheme === 'dark' }" @click="toggleTheme('dark')" style="flex: 1; padding: 10px; font-size: 14px; font-weight: 500;">
                <i class="fa-solid fa-moon"></i> Oscuro
              </button>
              <button type="button" class="btn-secondary" :class="{ active: currentTheme === 'light' }" @click="toggleTheme('light')" style="flex: 1; padding: 10px; font-size: 14px; font-weight: 500;">
                <i class="fa-solid fa-sun"></i> Claro
              </button>
            </div>
          </div>

          <div v-if="successMsg" class="success-msg">{{ successMsg }}</div>
          <div v-if="errorMsg" class="error-msg">{{ errorMsg }}</div>

          <button type="submit" class="btn-primary" :disabled="btnLoading">
            Guardar Cambios
          </button>
        </form>
      </div>

      <!-- Columna Derecha: IA Personal y Datos -->
      <div class="settings-sidebar-cards" style="display: flex; flex-direction: column; gap: 24px;">
        <!-- Ajustes de IA Personal -->
        <div class="glass-card ai-settings-card">
          <h3 class="card-title text-gradient-purple">
            <i class="fa-solid fa-key"></i> IA Personal (Google Gemini)
          </h3>
          <p class="card-subtitle" style="font-size: 13px; color: var(--text-secondary); margin-top: 4px;">
            Vincula tu propia cuenta de Google para mayor privacidad y velocidad.
          </p>

          <div class="settings-form" style="margin-top: 15px;">
            <div class="form-group">
              <label for="gemini-key">Tu Gemini API Key</label>
              <div style="display: flex; gap: 8px;">
                <input type="password" id="gemini-key" v-model="geminiApiKey" placeholder="AIzaSy..." style="flex: 1;" />
                <button type="button" class="btn-primary" @click="saveGeminiKey" style="padding: 10px 16px;">Vincular</button>
              </div>
              <p class="input-help-text" style="font-size: 11.5px; color: var(--text-muted); margin-top: 8px; line-height: 1.45;">
                Obtén tu clave gratis en <a href="https://aistudio.google.com/" target="_blank" class="auth-link" style="text-decoration: underline;">Google AI Studio</a>. Se almacena localmente en tu navegador.
              </p>
            </div>
            <div v-if="aiKeySuccessMsg" class="success-msg" style="margin-top: 10px; background: rgba(48, 209, 88, 0.1); color: var(--color-success); border: 1px solid rgba(48, 209, 88, 0.2); padding: 8px 12px; border-radius: var(--radius-sm); font-size: 13.5px; text-align: center;">
              {{ aiKeySuccessMsg }}
            </div>
          </div>
        </div>

        <!-- Respaldo y Herramientas -->
        <div class="glass-card tools-card">
          <h3 class="card-title text-gradient-green">
            <i class="fa-solid fa-screwdriver-wrench"></i> Herramientas y Datos
          </h3>
          <p class="card-subtitle">Administra tus bases de datos, exporta respaldos para análisis de IA o restablece el sistema</p>

          <div class="tools-buttons" style="margin-top: 15px; display: flex; flex-direction: column; gap: 16px;">
            <div class="tool-action-group">
              <h4 style="font-size: 14.5px; font-weight: 500; margin-bottom: 4px;">Copia de Seguridad (SaaS Backup)</h4>
              <p style="font-size: 12px; color: var(--text-secondary); margin-bottom: 8px;">Descarga toda tu información financiera (transacciones, presupuestos, cuentas) en formato JSON estructurado.</p>
              <button class="btn-primary btn-tool" @click="exportData" style="width: 100%;">
                <i class="fa-solid fa-download"></i> Exportar Respaldo JSON
              </button>
            </div>

            <div class="tool-action-group danger-zone" style="border-top: 1px solid var(--card-border); padding-top: 12px;">
              <h4 style="font-size: 14.5px; font-weight: 500; margin-bottom: 4px; color: var(--color-danger);">Zona de Peligro</h4>
              <p style="font-size: 12px; color: var(--text-secondary); margin-bottom: 8px;">Borra de forma permanente todas tus transacciones, presupuestos y cuentas personalizadas. Esto reiniciará tus saldos a cero.</p>
              <button class="btn-danger btn-tool" @click="resetDatabase" style="width: 100%;">
                <i class="fa-solid fa-trash-can"></i> Restablecer Aplicación
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Segunda fila: Editor de Categorías -->
    <div class="glass-card category-manager-panel">
      <h3 class="card-title text-gradient-purple">
        <i class="fa-solid fa-tags"></i> Gestión de Categorías
      </h3>
      <p class="card-subtitle">Define tus categorías de ingresos y egresos (gastos bancarios, ocio, facturas, etc.). Selecciona iconos de FontAwesome.</p>

      <div class="category-manager-grid">
        <!-- Formulario Crear/Editar -->
        <div class="category-editor-form">
          <h4 class="form-section-title">{{ editingCategory ? 'Editar Categoría' : 'Añadir Nueva Categoría' }}</h4>
          
          <form @submit.prevent="submitCategoryForm" class="cat-editor">
            <div class="form-group">
              <label for="cat-name">Nombre de la Categoría</label>
              <input type="text" id="cat-name" placeholder="Ej: Gastos Bancarios, Ocio" v-model="catForm.name" required />
            </div>

            <div class="form-row">
              <div class="form-group">
                <label for="cat-type">Tipo de Categoría</label>
                <select id="cat-type" v-model="catForm.type" required>
                  <option value="egreso">Egreso (Gasto)</option>
                  <option value="ingreso">Ingreso</option>
                </select>
              </div>

              <div class="form-group">
                <label for="cat-color">Color para Reportes</label>
                <input type="color" id="cat-color" v-model="catForm.color" class="color-picker-input" />
              </div>
            </div>

            <!-- Catálogo de Iconos FontAwesome -->
            <div class="form-group">
              <label>Seleccionar Icono FontAwesome</label>
              <div class="icon-selector-grid">
                <button type="button" 
                        v-for="iconClass in fontAwesomeIcons" 
                        :key="iconClass"
                        class="btn-icon-option"
                        :class="{ active: catForm.icon === iconClass }"
                        @click="catForm.icon = iconClass"
                        :title="iconClass">
                  <i :class="'fa-solid ' + iconClass"></i>
                </button>
              </div>
            </div>

            <div class="form-group">
              <label for="cat-custom-icon">O escribe una clase FontAwesome personalizada</label>
              <input type="text" id="cat-custom-icon" placeholder="Ej: fa-motorcycle, fa-dumbbell" v-model="catForm.icon" />
              <span class="input-help-text">
                Icono actual: <i :class="'fa-solid ' + (catForm.icon || 'fa-tag')"></i> ({{ catForm.icon || 'Ninguno' }})
              </span>
            </div>

            <div v-if="catError" class="error-msg">{{ catError }}</div>

            <div class="form-actions">
              <button type="submit" class="btn-primary">
                {{ editingCategory ? 'Actualizar Categoría' : 'Añadir Categoría' }}
              </button>
              <button type="button" class="btn-secondary" v-if="editingCategory" @click="cancelEdit">
                Cancelar
              </button>
            </div>
          </form>
        </div>

        <!-- Listado de Categorías -->
        <div class="category-display-list">
          <h4 class="form-section-title">Listado de Categorías</h4>
          
          <div class="categories-container-list">
            <div v-for="cat in categories" :key="cat.id" class="category-item-card" :class="{ 'system-cat': !cat.user_id }">
              <div class="cat-left">
                <div class="cat-icon-badge" :style="{ backgroundColor: cat.color + '20', color: cat.color }">
                  <i :class="'fa-solid ' + (cat.icon || 'fa-tag')"></i>
                </div>
                <div>
                  <strong class="cat-title">{{ cat.name }}</strong>
                  <span class="cat-type-label">{{ cat.type === 'ingreso' ? 'Ingreso' : 'Egreso' }}</span>
                </div>
              </div>

              <div class="cat-right-actions">
                <button class="btn-edit-action" @click="startEdit(cat)" title="Editar categoría">
                  <i class="fa-solid fa-pen"></i>
                </button>
                <button class="btn-delete-action" @click="deleteCategory(cat.id)" title="Eliminar categoría">
                  <i class="fa-solid fa-trash-can"></i>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue'
import { API_BASE } from '../config.js'

export default {
  name: 'SettingsView',
  setup() {
    const currentTheme = ref(localStorage.getItem('theme') || 'dark')
    const geminiApiKey = ref(localStorage.getItem('gemini_api_key') || '')
    const aiKeySuccessMsg = ref('')

    const toggleTheme = (theme) => {
      currentTheme.value = theme
      localStorage.setItem('theme', theme)
      document.body.classList.toggle('light-theme', theme === 'light')
    }

    const form = ref({
      name: '',
      email: '',
      currency: 'COP',
      reminder_days_before: 5
    })

    const categories = ref([])
    const editingCategory = ref(null)

    // Formulario de categoría
    const catForm = ref({
      name: '',
      type: 'egreso',
      color: '#6366f1',
      icon: 'fa-tag'
    })

    // UI Estados
    const btnLoading = ref(false)
    const successMsg = ref('')
    const errorMsg = ref('')
    const catError = ref('')

    // Listado Extenso de Iconos de FontAwesome (Más de 80 iconos temáticos)
    const fontAwesomeIcons = [
      // Alimentos y Bebidas
      'fa-utensils', 'fa-burger', 'fa-pizza-slice', 'fa-mug-hot', 'fa-beer-mug-empty', 'fa-wine-glass', 'fa-ice-cream',
      // Hogar y Servicios
      'fa-house', 'fa-couch', 'fa-faucet', 'fa-bolt', 'fa-fire', 'fa-wifi', 'fa-trash', 'fa-soap',
      // Transporte y Viajes
      'fa-car', 'fa-bus', 'fa-train', 'fa-plane', 'fa-motorcycle', 'fa-bicycle', 'fa-gas-pump', 'fa-taxi',
      // Compras y Ropa
      'fa-cart-shopping', 'fa-bag-shopping', 'fa-store', 'fa-tag', 'fa-gift', 'fa-shirt', 'fa-glasses', 'fa-shoe-prints',
      // Ocio y Entretenimiento
      'fa-gamepad', 'fa-tv', 'fa-film', 'fa-ticket', 'fa-music', 'fa-book', 'fa-camera', 'fa-spa', 'fa-dumbbell', 'fa-umbrella-beach', 'fa-tree',
      // Salud y Mascotas
      'fa-heart-pulse', 'fa-stethoscope', 'fa-notes-medical', 'fa-capsules', 'fa-tooth', 'fa-baby', 'fa-paw',
      // Finanzas y Negocios
      'fa-wallet', 'fa-piggy-bank', 'fa-coins', 'fa-credit-card', 'fa-money-bill-trend-up', 'fa-building-columns', 'fa-briefcase', 'fa-chart-pie', 'fa-receipt', 'fa-file-invoice-dollar',
      // Educación y Niños
      'fa-graduation-cap', 'fa-school', 'fa-pencil', 'fa-child', 'fa-shapes',
      // Tecnología
      'fa-mobile-screen', 'fa-laptop', 'fa-desktop', 'fa-headphones', 'fa-keyboard',
      // Otros e Interesantes
      'fa-key', 'fa-gavel', 'fa-screwdriver-wrench', 'fa-hammer', 'fa-scissors', 'fa-brush', 'fa-basket-shopping', 'fa-shield-halved', 'fa-percent', 'fa-envelope', 'fa-bell', 'fa-heart', 'fa-star', 'fa-face-smile'
    ]

    const fetchSettings = async () => {
      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/settings.php`, {
          headers: { 'Authorization': `Bearer ${token}` }
        })
        const data = await response.json()
        if (response.ok) {
          form.value = data
        }
      } catch (err) {
        console.error(err)
      }
    }

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

    const saveSettings = async () => {
      btnLoading.value = true
      successMsg.value = ''
      errorMsg.value = ''

      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/settings.php`, {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`
          },
          body: JSON.stringify(form.value)
        })

        const data = await response.json()
        if (!response.ok) {
          throw new Error(data.error || 'Error al guardar los ajustes.')
        }

        const userStored = JSON.parse(localStorage.getItem('user'))
        userStored.name = form.value.name
        userStored.currency = form.value.currency
        localStorage.setItem('user', JSON.stringify(userStored))

        successMsg.value = 'Configuración guardada exitosamente.'
      } catch (err) {
        errorMsg.value = err.message
      } finally {
        btnLoading.value = false
      }
    }

    const saveGeminiKey = () => {
      localStorage.setItem('gemini_api_key', geminiApiKey.value.trim())
      aiKeySuccessMsg.value = '¡Clave de API vinculada con éxito de forma local!'
      setTimeout(() => {
        aiKeySuccessMsg.value = ''
      }, 3000)
    }

    // Guardar / Crear categoría
    const submitCategoryForm = async () => {
      catError.value = ''
      const token = localStorage.getItem('token')

      // Validar prefijos de iconos
      let iconClass = catForm.value.icon.trim()
      if (iconClass && !iconClass.startsWith('fa-')) {
        iconClass = 'fa-' + iconClass
      }

      try {
        let response
        if (editingCategory.value) {
          // Editar categoría existente (PUT)
          response = await fetch(`${API_BASE}/categories.php?id=${editingCategory.value.id}`, {
            method: 'PUT',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({
              name: catForm.value.name,
              type: catForm.value.type,
              color: catForm.value.color,
              icon: iconClass || 'fa-tag'
            })
          })
        } else {
          // Crear nueva categoría (POST)
          response = await fetch(`${API_BASE}/categories.php`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({
              name: catForm.value.name,
              type: catForm.value.type,
              color: catForm.value.color,
              icon: iconClass || 'fa-tag'
            })
          })
        }

        const data = await response.json()
        if (!response.ok) {
          throw new Error(data.error || 'Error al procesar la categoría.')
        }

        cancelEdit()
        await fetchCategories()
      } catch (err) {
        catError.value = err.message
      }
    }

    const startEdit = (cat) => {
      editingCategory.value = cat
      catForm.value = {
        name: cat.name,
        type: cat.type,
        color: cat.color,
        icon: cat.icon || 'fa-tag'
      }
      catError.value = ''
    }

    const cancelEdit = () => {
      editingCategory.value = null
      catForm.value = {
        name: '',
        type: 'egreso',
        color: '#6366f1',
        icon: 'fa-tag'
      }
      catError.value = ''
    }

    const deleteCategory = async (id) => {
      if (!confirm('¿Estás seguro de eliminar esta categoría? Las transacciones asociadas quedarán sin categoría.')) return
      
      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/categories.php?id=${id}`, {
          method: 'DELETE',
          headers: { 'Authorization': `Bearer ${token}` }
        })

        if (!response.ok) {
          const data = await response.json()
          throw new Error(data.error || 'Error al eliminar.')
        }

        if (editingCategory.value && editingCategory.value.id === id) {
          cancelEdit()
        }
        await fetchCategories()
      } catch (err) {
        alert(err.message)
      }
    }

    // Exportar datos JSON
    const exportData = async () => {
      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/settings.php?action=export_data`, {
          headers: { 'Authorization': `Bearer ${token}` }
        })
        
        if (!response.ok) {
          throw new Error('Error al exportar los datos.')
        }

        const data = await response.json()
        
        // Disparar la descarga en el navegador
        const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(data, null, 2))
        const downloadAnchor = document.createElement('a')
        downloadAnchor.setAttribute("href", dataStr)
        downloadAnchor.setAttribute("download", `respaldo_finanzas_${new Date().toISOString().split('T')[0]}.json`)
        document.body.appendChild(downloadAnchor)
        downloadAnchor.click()
        downloadAnchor.remove()
      } catch (err) {
        alert(err.message)
      }
    }

    // Restablecer base de datos
    const resetDatabase = async () => {
      const confirm1 = confirm('¿ATENCIÓN! ¿Estás seguro de que deseas restablecer la base de datos? Se borrarán todas las transacciones, cuentas y presupuestos creados.')
      if (!confirm1) return

      const confirm2 = confirm('Esta acción no se puede deshacer. Escribe "ACEPTAR" para proceder a borrar todo.')
      if (!confirm2) return

      const token = localStorage.getItem('token')
      try {
        const response = await fetch(`${API_BASE}/settings.php?action=reset_db`, {
          method: 'POST',
          headers: { 'Authorization': `Bearer ${token}` }
        })

        const data = await response.json()
        if (!response.ok) {
          throw new Error(data.error || 'Error al restablecer la base de datos.')
        }

        alert(data.message)
        window.location.reload() // Recargar para limpiar estados de cuentas y transacciones
      } catch (err) {
        alert(err.message)
      }
    }

    onMounted(() => {
      fetchSettings()
      fetchCategories()
    })

    return {
      form,
      categories,
      catForm,
      editingCategory,
      btnLoading,
      successMsg,
      errorMsg,
      catError,
      fontAwesomeIcons,
      saveSettings,
      submitCategoryForm,
      startEdit,
      cancelEdit,
      deleteCategory,
      exportData,
      resetDatabase,
      currentTheme,
      toggleTheme,
      geminiApiKey,
      aiKeySuccessMsg,
      saveGeminiKey
    }
  }
}
</script>

<style scoped>
.settings-container {
  display: flex;
  flex-direction: column;
  gap: 30px;
  animation: fadeIn 0.4s ease-out;
}

.settings-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 24px;
}

@media (min-width: 992px) {
  .settings-grid {
    grid-template-columns: 1.2fr 1fr;
  }
}

.card-title {
  font-size: 18px;
  font-weight: 700;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.card-subtitle {
  color: var(--text-secondary);
  font-size: 13px;
  margin-bottom: 20px;
}

.input-disabled {
  background: rgba(255,255,255,0.03);
  color: var(--text-muted);
  cursor: not-allowed;
}

.settings-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* Herramientas */
.tools-buttons {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.tool-action-group {
  background: rgba(255, 255, 255, 0.02);
  padding: 16px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--card-border);
}

.tool-action-group h4 {
  font-size: 14px;
  font-weight: 700;
  margin-bottom: 6px;
}

.tool-action-group p {
  font-size: 12px;
  color: var(--text-secondary);
  margin-bottom: 12px;
}

.btn-tool {
  font-size: 14px;
  padding: 10px 20px;
}

.danger-zone {
  border-color: rgba(239, 68, 68, 0.2);
  background: rgba(239, 68, 68, 0.03);
}

.danger-zone h4 {
  color: var(--color-danger);
}

.btn-danger {
  background: linear-gradient(135deg, var(--color-danger), #b91c1c);
  color: #fff;
  border: none;
  border-radius: var(--radius-sm);
  padding: 10px 20px;
  font-weight: 600;
  cursor: pointer;
  box-shadow: 0 4px 14px 0 rgba(239, 68, 68, 0.3);
  transition: var(--transition-smooth);
}

.btn-danger:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px 0 rgba(239, 68, 68, 0.5);
  filter: brightness(1.1);
}

/* Gestión de Categorías */
.category-manager-panel {
  padding: 24px;
}

.category-manager-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 30px;
  margin-top: 10px;
}

@media (min-width: 992px) {
  .category-manager-grid {
    grid-template-columns: 1fr 1.2fr;
  }
}

.form-section-title {
  font-size: 16px;
  font-weight: 700;
  margin-bottom: 16px;
  border-bottom: 1px solid var(--card-border);
  padding-bottom: 8px;
}

.cat-editor {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.color-picker-input {
  height: 48px;
  padding: 4px;
  cursor: pointer;
}

/* Selector de iconos FontAwesome */
.icon-selector-grid {
  display: grid;
  grid-template-columns: repeat(8, 1fr);
  gap: 8px;
  max-height: 160px;
  overflow-y: auto;
  background: rgba(11, 15, 25, 0.3);
  border: 1px solid var(--card-border);
  padding: 10px;
  border-radius: var(--radius-sm);
}

.btn-icon-option {
  background: transparent;
  border: 1px solid transparent;
  color: var(--text-secondary);
  height: 38px;
  border-radius: 6px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  transition: var(--transition-smooth);
}

.btn-icon-option:hover {
  background: rgba(255, 255, 255, 0.05);
  color: var(--text-primary);
}

.btn-icon-option.active {
  background: rgba(139, 92, 246, 0.2);
  color: var(--color-primary);
  border-color: rgba(139, 92, 246, 0.4);
}

.input-help-text {
  font-size: 11px;
  color: var(--text-muted);
  margin-top: 4px;
  display: block;
}

.form-actions {
  display: flex;
  gap: 10px;
}

/* Listado de Categorías */
.categories-container-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
  max-height: 520px;
  overflow-y: auto;
  padding-right: 6px;
}

.category-item-card {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  background: rgba(30, 41, 59, 0.2);
  border: 1px solid rgba(255, 255, 255, 0.02);
  border-radius: var(--radius-sm);
  transition: var(--transition-smooth);
}

.category-item-card:hover {
  background: rgba(30, 41, 59, 0.35);
  border-color: rgba(255, 255, 255, 0.06);
}

.cat-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.cat-icon-badge {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
}

.cat-title {
  font-size: 15px;
  display: block;
}

.cat-type-label {
  font-size: 10px;
  color: var(--text-muted);
  text-transform: uppercase;
  font-weight: 600;
}

.cat-right-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.btn-edit-action {
  background: rgba(99, 102, 241, 0.15);
  border: 1px solid rgba(99, 102, 241, 0.3);
  color: var(--color-accent);
  width: 32px;
  height: 32px;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: var(--transition-smooth);
}

.btn-edit-action:hover {
  background: var(--color-accent);
  color: #fff;
}

.btn-delete-action {
  background: rgba(239, 68, 68, 0.15);
  border: 1px solid rgba(239, 68, 68, 0.3);
  color: var(--color-danger);
  width: 32px;
  height: 32px;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: var(--transition-smooth);
}

.btn-delete-action:hover {
  background: var(--color-danger);
  color: #fff;
}

.badge-system {
  font-size: 10px;
  color: var(--text-muted);
  font-style: italic;
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

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.btn-secondary.active {
  background: rgba(10, 132, 255, 0.12) !important;
  border-color: var(--color-primary) !important;
  color: var(--color-primary) !important;
}
</style>
