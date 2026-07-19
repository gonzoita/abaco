// C:\laragon\www\control-finanzas\frontend\src\config.js

// Autodetecta la ruta de la API dependiendo del entorno
const getApiBase = () => {
  const origin = window.location.origin;
  const port = window.location.port;

  // Si estamos en el servidor de desarrollo de Vite
  if (port === '5173') {
    // Redirige al servidor Laragon local por defecto
    return 'http://localhost/control-finanzas/backend/api';
  }

  // Si estamos en producción (Hostinger o Laragon con host virtual)
  // Limpiamos barras finales y resolvemos la ruta relativa de la API
  let basePath = window.location.pathname;
  if (basePath.endsWith('index.html')) {
    basePath = basePath.substring(0, basePath.lastIndexOf('/'));
  }
  if (!basePath.endsWith('/')) {
    basePath += '/';
  }

  // Si estamos usando Laragon con dominio virtual (ej: http://control-finanzas.test)
  if (origin.includes('.test')) {
    return `${origin}/backend/api`;
  }

  // Para carpetas compartidas o dominios finales (ej: http://midominio.com/backend/api)
  return `${origin}${basePath}backend/api`;
};

export const API_BASE = getApiBase();
export const GOOGLE_CLIENT_ID = '15579802304-4vs3kkl6pi5o3imkvdq6aecc9mcjgd8g.apps.googleusercontent.com';
