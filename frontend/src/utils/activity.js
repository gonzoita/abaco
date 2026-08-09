// frontend/src/utils/activity.js
// La señal más confiable de "última vez que usó la herramienta": la más
// reciente entre last_login_at (actividad en la app desde que se agregó el
// seguimiento) y last_transaction_date (útil para usuarios con historial de
// antes de ese seguimiento, que si no aparecerían como "Nunca" pese a tener
// transacciones).
export function lastActivity(user) {
  const dates = [user.last_login_at, user.last_transaction_date].filter(Boolean)
  if (dates.length === 0) return null
  return dates.reduce((latest, d) => (new Date(d) > new Date(latest) ? d : latest))
}
