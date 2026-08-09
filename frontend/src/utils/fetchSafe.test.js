import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fetchJsonSafe } from './fetchSafe.js'

describe('fetchJsonSafe', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('returns the parsed JSON on a successful response', async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ hello: 'world' })
    })

    const data = await fetchJsonSafe('https://example.test/api')
    expect(data).toEqual({ hello: 'world' })
    expect(global.fetch).toHaveBeenCalledTimes(1)
  })

  it('retries once after a network error and succeeds on the second try', async () => {
    global.fetch = vi.fn()
      .mockRejectedValueOnce(new Error('network blip'))
      .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ ok: 1 }) })

    const data = await fetchJsonSafe('https://example.test/api')

    expect(data).toEqual({ ok: 1 })
    expect(global.fetch).toHaveBeenCalledTimes(2)
  })

  it('throws after exhausting retries so a caller can show a real error instead of silently returning nothing', async () => {
    global.fetch = vi.fn().mockRejectedValue(new Error('server down'))

    await expect(fetchJsonSafe('https://example.test/api')).rejects.toThrow('server down')
    // 1 intento inicial + 1 reintento por defecto = 2 llamadas
    expect(global.fetch).toHaveBeenCalledTimes(2)
  })

  it('treats a non-ok HTTP status as a failure worth retrying, not valid empty data', async () => {
    global.fetch = vi.fn()
      .mockResolvedValueOnce({ ok: false, status: 500 })
      .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ recovered: true }) })

    const data = await fetchJsonSafe('https://example.test/api')
    expect(data).toEqual({ recovered: true })
  })

  it('passes through the fetch options (headers, method) unchanged', async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({}) })
    const options = { headers: { Authorization: 'Bearer abc' } }

    await fetchJsonSafe('https://example.test/api', options)

    expect(global.fetch).toHaveBeenCalledWith('https://example.test/api', options)
  })
})
