import { describe, it, expect } from 'vitest'
import { daysSince, isBackupStale } from './backupReminder.js'

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
