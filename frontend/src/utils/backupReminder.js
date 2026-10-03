// frontend/src/utils/backupReminder.js
// El respaldo a Drive usa el flujo de token implícito de Google, que abre un
// popup y por eso exige un clic del usuario: no se puede disparar solo al
// cargar la app. Lo que sí se puede es no depender de que el usuario se
// acuerde, avisándole cuando el último respaldo ya está viejo.

export const BACKUP_STORAGE_KEY = 'abaco_last_backup_at'
export const STALE_AFTER_DAYS = 7

export function daysSince(lastBackupIso, now = new Date()) {
  if (!lastBackupIso) return null
  const last = new Date(lastBackupIso)
  if (isNaN(last.getTime())) return null
  return Math.floor((now.getTime() - last.getTime()) / 86400000)
}

/**
 * Sin respaldo registrado también cuenta como "viejo": es justo el caso de
 * quien nunca respaldó, que es el que más necesita el aviso.
 */
export function isBackupStale(lastBackupIso, now = new Date(), staleAfterDays = STALE_AFTER_DAYS) {
  const days = daysSince(lastBackupIso, now)
  if (days === null) return true
  return days >= staleAfterDays
}

// localStorage puede no estar disponible (ventana privada, datos del sitio
// bloqueados), y ahí el aviso simplemente no se muestra: nunca debe tumbar
// la pantalla de Ajustes.
export function readLastBackup() {
  try {
    return localStorage.getItem(BACKUP_STORAGE_KEY)
  } catch {
    return null
  }
}

export function recordBackup(now = new Date()) {
  const iso = now.toISOString()
  try {
    localStorage.setItem(BACKUP_STORAGE_KEY, iso)
  } catch {
    // Sin almacenamiento disponible no hay nada que recordar.
  }
  return iso
}
