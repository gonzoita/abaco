<template>
  <div class="legal-container fade-in">
    <div class="glass-card legal-card">
      <div class="legal-header">
        <img src="../assets/logo-white.png" class="logo-img logo-dark" alt="Ábaco" style="max-height:32px;" />
        <img src="../assets/logo-black.png" class="logo-img logo-light" alt="Ábaco" style="max-height:32px;" />
        <router-link to="/" class="btn-secondary" style="height:36px; padding:0 14px; font-size:12.5px; border-radius:8px; display:flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-arrow-left"></i> Volver
        </router-link>
      </div>

      <div class="segmented-control" style="margin-bottom:20px;">
        <button class="segment-btn" :class="{ active: tab === 'privacy' }" @click="tab = 'privacy'">
          <i class="fa-solid fa-lock"></i> Privacidad
        </button>
        <button class="segment-btn" :class="{ active: tab === 'terms' }" @click="tab = 'terms'">
          <i class="fa-solid fa-file-contract"></i> Términos y Condiciones
        </button>
      </div>

      <!-- ================= POLÍTICA DE PRIVACIDAD ================= -->
      <div v-if="tab === 'privacy'" class="legal-content">
        <h1>Política de Tratamiento de Datos Personales</h1>
        <p class="legal-updated">Última actualización: {{ lastUpdated }}</p>

        <p>Ábaco ("la Aplicación", "nosotros") es una herramienta de gestión de finanzas personales y de negocio. Esta política explica qué información recopilamos, para qué la usamos, con quién la compartimos y qué derechos tienes sobre ella, en cumplimiento de la Ley 1581 de 2012 de Colombia (Ley de Protección de Datos Personales / Habeas Data) y buenas prácticas internacionales de privacidad.</p>

        <h2>1. Información que recopilamos</h2>
        <ul>
          <li><strong>Datos de cuenta:</strong> nombre, correo electrónico y, si inicias sesión con Google, tu identificador y foto de perfil de Google.</li>
          <li><strong>Datos financieros que tú registras:</strong> cuentas, saldos, transacciones, presupuestos, metas de ahorro, préstamos y categorías. Esta información la ingresas voluntariamente para que la Aplicación funcione.</li>
          <li><strong>Datos de uso:</strong> fecha de tu última actividad en la Aplicación, para fines de soporte y seguridad de la cuenta.</li>
          <li><strong>Recibos escaneados y dictado por voz:</strong> si usas estas funciones de IA, la imagen o el texto se envía únicamente para ser procesado y no se almacena permanentemente en nuestros servidores más allá del tiempo necesario para completar la operación.</li>
        </ul>

        <h2>2. Para qué usamos tu información</h2>
        <ul>
          <li>Prestar el servicio: mostrar tus balances, generar reportes, calcular tu salud financiera y autonomía financiera.</li>
          <li>Generar análisis y recomendaciones con inteligencia artificial (ver sección 3).</li>
          <li>Enviarte recordatorios de pagos y notificaciones dentro de la propia Aplicación.</li>
          <li>Prevenir fraude y accesos no autorizados a tu cuenta (por ejemplo, límites de intentos de inicio de sesión).</li>
        </ul>

        <h2>3. Uso de Inteligencia Artificial (Google Gemini)</h2>
        <p>Las funciones de asesor de IA, escaneo de recibos, dictado por voz y análisis de salud financiera envían la información estrictamente necesaria (por ejemplo, un resumen de tus movimientos o la imagen de un recibo) a la API de <strong>Google Gemini</strong> para generar una respuesta. Esta información se transmite de forma cifrada (HTTPS) y no se usa por Google para entrenar sus modelos de uso público según los términos de la API de Gemini vigentes al momento de esta política. Te recomendamos no incluir información sensible de terceros (números de tarjeta completos, contraseñas, documentos de identidad) en tus descripciones de transacciones o notas.</p>
        <p><strong>El asesor de IA no es un asesor financiero certificado.</strong> Sus recomendaciones son generadas automáticamente con base en los datos que registras y tienen fines educativos e informativos; no constituyen asesoría financiera, legal o tributaria profesional. Las decisiones financieras que tomes son tu responsabilidad.</p>

        <h2>4. Con quién compartimos tu información</h2>
        <ul>
          <li><strong>Google:</strong> para autenticación ("Acceder con Google"), y si tú lo activas voluntariamente, para respaldar tus datos en tu propio Google Drive (la Aplicación solo accede a los archivos que ella misma crea, nunca al resto de tu Drive).</li>
          <li><strong>Google Gemini (IA):</strong> según se describe en la sección 3.</li>
          <li><strong>Nuestro proveedor de hosting</strong>, donde se almacena la base de datos de forma segura.</li>
          <li>No vendemos, alquilamos ni compartimos tu información financiera con anunciantes ni con terceros para fines comerciales ajenos al funcionamiento de la Aplicación.</li>
        </ul>

        <h2>5. Seguridad</h2>
        <ul>
          <li>Tu contraseña se almacena con hash criptográfico (bcrypt), nunca en texto plano.</li>
          <li>Todas las comunicaciones entre tu dispositivo y nuestros servidores usan HTTPS.</li>
          <li>Limitamos los intentos fallidos de inicio de sesión para prevenir ataques de fuerza bruta.</li>
          <li>El acceso al panel de administración está restringido a personal autorizado.</li>
        </ul>

        <h2>6. Tus derechos (Habeas Data)</h2>
        <p>Como titular de tus datos, en cualquier momento puedes:</p>
        <ul>
          <li><strong>Acceder</strong> a tus datos personales y financieros almacenados (desde la propia Aplicación, o solicitándolo por correo).</li>
          <li><strong>Rectificar</strong> información incorrecta desde Ajustes.</li>
          <li><strong>Exportar</strong> toda tu información en formato JSON desde Ajustes → Herramientas y Datos.</li>
          <li><strong>Eliminar</strong> tu cuenta y todos tus datos asociados, escribiéndonos al correo de contacto o solicitándolo al administrador.</li>
          <li><strong>Revocar</strong> el permiso de acceso a Google Drive en cualquier momento desde tu cuenta de Google (myaccount.google.com → Seguridad → Aplicaciones de terceros).</li>
        </ul>

        <h2>7. Conservación de datos</h2>
        <p>Conservamos tu información mientras tu cuenta esté activa. Si solicitas la eliminación de tu cuenta, tus datos financieros y personales se eliminan de forma permanente de nuestra base de datos en un plazo razonable, salvo que la ley exija conservar cierta información por más tiempo.</p>

        <h2>8. Contacto</h2>
        <p>Para ejercer tus derechos o resolver dudas sobre esta política, escríbenos a <a href="mailto:soporte@briela.app">soporte@briela.app</a>.</p>
      </div>

      <!-- ================= TÉRMINOS Y CONDICIONES ================= -->
      <div v-if="tab === 'terms'" class="legal-content">
        <h1>Términos y Condiciones de Uso</h1>
        <p class="legal-updated">Última actualización: {{ lastUpdated }}</p>

        <h2>1. Aceptación</h2>
        <p>Al crear una cuenta y usar Ábaco, aceptas estos Términos y la Política de Privacidad. Si no estás de acuerdo, no debes usar la Aplicación.</p>

        <h2>2. Descripción del servicio</h2>
        <p>Ábaco es una herramienta de gestión de finanzas personales y de negocio (registro de ingresos/gastos, presupuestos, metas de ahorro, préstamos, reportes y un asesor con inteligencia artificial). Se ofrece "tal cual" ("as is"), con un período de prueba gratuita y planes de suscripción según se indique en la Aplicación.</p>

        <h2>3. Responsabilidad del usuario</h2>
        <ul>
          <li>Eres responsable de la exactitud de la información financiera que registras.</li>
          <li>Eres responsable de mantener la confidencialidad de tu sesión y credenciales de acceso.</li>
          <li>Debes usar la Aplicación de forma lícita y no intentar vulnerar su seguridad.</li>
        </ul>

        <h2>4. Naturaleza informativa del Asesor IA</h2>
        <p>Las recomendaciones, análisis y proyecciones generadas por el Asesor de IA (incluyendo el índice de Salud Financiera y la Autonomía Financiera) son estimaciones automatizadas basadas en los datos que tú mismo ingresas. <strong>No sustituyen el consejo de un asesor financiero, contable o legal certificado.</strong> Ábaco no se hace responsable por decisiones financieras tomadas con base en estas recomendaciones.</p>

        <h2>5. Suscripciones y período de prueba</h2>
        <p>Al registrarte obtienes un período de prueba gratuito. Al finalizar, según el plan vigente informado en la Aplicación, podrás continuar con una suscripción de pago o tu cuenta podrá limitarse a funciones básicas. Los términos de facturación específicos se comunican dentro de la Aplicación al momento de suscribirte.</p>

        <h2>6. Limitación de responsabilidad</h2>
        <p>Ábaco se ofrece sin garantías de ningún tipo, expresas o implícitas. En la máxima medida permitida por la ley, no seremos responsables por pérdidas indirectas, incidentales o consecuentes derivadas del uso de la Aplicación, incluyendo errores en cálculos, interrupciones del servicio o pérdida de datos, aunque hacemos nuestro mejor esfuerzo por evitarlos (respaldos, monitoreo y buenas prácticas de desarrollo).</p>

        <h2>7. Terminación de cuenta</h2>
        <p>Puedes eliminar tu cuenta en cualquier momento. Nos reservamos el derecho de suspender cuentas que violen estos Términos, usen la Aplicación de forma fraudulenta o representen un riesgo de seguridad para otros usuarios.</p>

        <h2>8. Cambios a estos Términos</h2>
        <p>Podemos actualizar estos Términos y la Política de Privacidad. Los cambios importantes se notificarán dentro de la Aplicación. El uso continuado después de un cambio implica su aceptación.</p>

        <h2>9. Ley aplicable</h2>
        <p>Estos Términos se rigen por las leyes de la República de Colombia.</p>

        <h2>10. Contacto</h2>
        <p>Para preguntas sobre estos Términos, escríbenos a <a href="mailto:soporte@briela.app">soporte@briela.app</a>.</p>
      </div>
    </div>
  </div>
</template>

<script>
import { ref } from 'vue'

export default {
  name: 'LegalView',
  setup() {
    const tab = ref('privacy')
    const lastUpdated = new Date().toLocaleDateString('es-CO', { year: 'numeric', month: 'long', day: 'numeric' })
    return { tab, lastUpdated }
  }
}
</script>

<style scoped>
.legal-container {
  min-height: 100vh;
  display: flex;
  justify-content: center;
  padding: 24px 16px 60px 16px;
}

.legal-card {
  width: 100%;
  max-width: 780px;
  padding: 24px;
  height: fit-content;
}

.legal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  gap: 12px;
}

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
  font-size: 13.5px;
  font-weight: 500;
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

.legal-content h1 {
  font-size: 22px;
  font-weight: 800;
  margin-bottom: 4px;
  color: var(--text-primary);
}

.legal-updated {
  font-size: 12px;
  color: var(--text-muted);
  margin-bottom: 20px;
}

.legal-content h2 {
  font-size: 15px;
  font-weight: 700;
  margin: 22px 0 8px 0;
  color: var(--color-primary);
}

.legal-content p {
  font-size: 13.5px;
  line-height: 1.65;
  color: var(--text-secondary);
  margin-bottom: 10px;
}

.legal-content ul {
  margin: 0 0 10px 0;
  padding-left: 20px;
}

.legal-content li {
  font-size: 13.5px;
  line-height: 1.65;
  color: var(--text-secondary);
  margin-bottom: 6px;
}

.legal-content strong {
  color: var(--text-primary);
}

.legal-content a {
  color: var(--color-primary);
}
</style>
