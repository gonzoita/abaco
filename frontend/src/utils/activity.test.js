import { describe, it, expect } from 'vitest'
import { lastActivity } from './activity.js'

describe('lastActivity', () => {
  it('returns null when the user has never logged in nor transacted', () => {
    expect(lastActivity({ last_login_at: null, last_transaction_date: null })).toBeNull()
  })

  it('returns the login date when there is no transaction history', () => {
    const user = { last_login_at: '2026-08-09 10:00:00', last_transaction_date: null }
    expect(lastActivity(user)).toBe('2026-08-09 10:00:00')
  })

  it('returns the transaction date for users who registered before activity tracking existed', () => {
    // Este es exactamente el caso real que reportó el usuario: Julieta con
    // 13 transacciones pero sin ningún login registrado todavía.
    const user = { last_login_at: null, last_transaction_date: '2026-07-15' }
    expect(lastActivity(user)).toBe('2026-07-15')
  })

  it('picks whichever of the two signals is more recent', () => {
    const loginIsNewer = { last_login_at: '2026-08-09', last_transaction_date: '2026-07-01' }
    expect(lastActivity(loginIsNewer)).toBe('2026-08-09')

    const transactionIsNewer = { last_login_at: '2026-07-01', last_transaction_date: '2026-08-09' }
    expect(lastActivity(transactionIsNewer)).toBe('2026-08-09')
  })
})
