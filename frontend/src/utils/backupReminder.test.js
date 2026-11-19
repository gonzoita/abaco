import { describe, it, expect, beforeEach } from 'vitest'
import { daysSince, isBackupStale, backupStorageKey, readLastBackup, recordBackup } from './backupReminder.js'

describe('daysSince', () => {
  it('returns null when there is no recorded backup', () => {
    expect(daysSince(null)).toBeNull()
  })

  it('returns null for an unparseable date instead of NaN', () => {
    expect(daysSince('no es una fecha')).toBeNull()
  })

  it('counts whole days between the backup and now', () => {
    const now = new Date('2026-10-02T12:00:00Z')
    expect(daysSince('2026-09-25T12:00:00Z', now)).toBe(7)
  })
})

describe('isBackupStale', () => {
  it('treats "never backed up" as stale', () => {
    expect(isBackupStale(null)).toBe(true)
  })

  it('is not stale right after a backup', () => {
    const now = new Date('2026-10-02T12:00:00Z')
    expect(isBackupStale('2026-10-02T11:00:00Z', now)).toBe(false)
  })

  // El caso real: el último respaldo en Drive era del 7 de agosto y nadie
  // avisó nada durante casi dos meses.
  it('is stale for a backup from almost two months ago', () => {
    const now = new Date('2026-10-02T12:00:00Z')
    expect(isBackupStale('2026-08-07T20:34:00Z', now)).toBe(true)
  })

  it('becomes stale exactly at the threshold', () => {
    const now = new Date('2026-10-02T12:00:00Z')
    expect(isBackupStale('2026-09-25T12:00:00Z', now, 7)).toBe(true)
    expect(isBackupStale('2026-09-26T12:00:00Z', now, 7)).toBe(false)
  })
})

describe('respaldo por espacio', () => {
  beforeEach(() => {
    const data = new Map()
    globalThis.localStorage = {
      getItem: (k) => (data.has(k) ? data.get(k) : null),
      setItem: (k, v) => data.set(k, String(v)),
      removeItem: (k) => data.delete(k),
    }
  })

  it('keeps the legacy key for the personal space', () => {
    expect(backupStorageKey('personal')).toBe('abaco_last_backup_at')
    expect(backupStorageKey()).toBe('abaco_last_backup_at')
    expect(backupStorageKey('business')).not.toBe(backupStorageKey('personal'))
  })

  // Caso real: respaldar el espacio personal no debe apagar el aviso del negocio.
  it('backing up one space does not clear the warning of the other', () => {
    recordBackup('personal', new Date('2026-11-14T12:00:00Z'))

    expect(readLastBackup('personal')).toBe('2026-11-14T12:00:00.000Z')
    expect(readLastBackup('business')).toBeNull()
    expect(isBackupStale(readLastBackup('business'))).toBe(true)
  })

  it('does not lose a backup date recorded before spaces existed', () => {
    localStorage.setItem('abaco_last_backup_at', '2026-11-10T00:00:00.000Z')

    expect(readLastBackup('personal')).toBe('2026-11-10T00:00:00.000Z')
  })
})
