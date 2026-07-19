// C:\laragon\www\control-finanzas\frontend\src\registerServiceWorker.js

// Desregistrar Service Worker y limpiar cachés para forzar actualización inmediata
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.getRegistrations().then((registrations) => {
    for (let registration of registrations) {
      registration.unregister().then((success) => {
        if (success) {
          console.log('Service Worker desregistrado para forzar actualización.');
        }
      });
    }
  });
}

if ('caches' in window) {
  caches.keys().then((names) => {
    for (let name of names) {
      caches.delete(name).then(() => {
        console.log('Caché eliminada:', name);
      });
    }
  });
}
