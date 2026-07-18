// C:\laragon\www\control-finanzas\frontend\src\registerServiceWorker.js

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    // Registrar el Service Worker desde la raíz de la carpeta pública
    navigator.serviceWorker.register('./sw.js')
      .then((registration) => {
        console.log('PWA Service Worker registrado con éxito bajo el alcance:', registration.scope);
      })
      .catch((error) => {
        console.error('Error en el registro del PWA Service Worker:', error);
      });
  });
}
