import { describe, it, expect, beforeEach } from 'vitest'
import {
  detectProvider,
  getActiveProvider,
  getStoredKey,
  activateProvider,
  rememberKey,
  forgetKey,
  keyIsRejected,
} from './aiProvider.js'

function memoryStorage() {
  const data = new Map()
  return {
    getItem: (k) => (data.has(k) ? data.get(k) : null),
    setItem: (k, v) => data.set(k, String(v)),
    removeItem: (k) => data.delete(k),
  }
}

describe('aiProvider', () => {
  beforeEach(() => {
    globalThis.localStorage = memoryStorage()
  })

  describe('detectProvider', () => {
    it('recognizes each provider by the prefix of its key', () => {
      expect(detectProvider('gsk_abc123')).toBe('groq')
      expect(detectProvider('AIzaSyAbc123')).toBe('gemini')
    })

    it('ignores surrounding spaces and returns null for unknown keys', () => {
      expect(detectProvider('  gsk_abc  ')).toBe('groq')
      expect(detectProvider('sk-algo')).toBeNull()
      expect(detectProvider('')).toBeNull()
      expect(detectProvider(undefined)).toBeNull()
    })
  })

  describe('getActiveProvider', () => {
    it('defaults to gemini when nothing is stored', () => {
      expect(getActiveProvider()).toBe('gemini')
    })

    // Caso real: usuarios que vincularon su clave antes de que existiera el selector.
    it('keeps working for users who only have the legacy Gemini key', () => {
      localStorage.setItem('gemini_api_key', 'AIzaSyAntigua')

      expect(getActiveProvider()).toBe('gemini')
      expect(getStoredKey('gemini')).toBe('AIzaSyAntigua')
    })

    it('uses the explicit provider when one was chosen', () => {
      activateProvider('groq', 'gsk_nueva')

      expect(getActiveProvider()).toBe('groq')
    })

    it('ignores an invalid stored provider', () => {
      localStorage.setItem('ai_provider', 'openai')

      expect(getActiveProvider()).toBe('gemini')
    })
  })

  describe('activateProvider', () => {
    it('makes the key the one every view sends to the server', () => {
      activateProvider('groq', 'gsk_nueva')

      expect(localStorage.getItem('gemini_api_key')).toBe('gsk_nueva')
      expect(localStorage.getItem('ai_provider')).toBe('groq')
    })

    // Caso real, visto en el navegador: la clave de Gemini de quien vinculó
    // antes del selector se perdía al activar Groq.
    it('does not lose the legacy Gemini key when switching to another provider', () => {
      localStorage.setItem('gemini_api_key', 'AIzaSyAntigua')

      activateProvider('groq', 'gsk_nueva')

      expect(getStoredKey('gemini')).toBe('AIzaSyAntigua')
      expect(getStoredKey('groq')).toBe('gsk_nueva')
    })

    it('does not overwrite a key already remembered for the previous provider', () => {
      activateProvider('gemini', 'AIzaSyUna')
      rememberKey('gemini', 'AIzaSyMasReciente')

      activateProvider('groq', 'gsk_nueva')

      expect(getStoredKey('gemini')).toBe('AIzaSyMasReciente')
    })

    it('lets the user switch back and forth without pasting the key again', () => {
      activateProvider('gemini', 'AIzaSyUna')
      activateProvider('groq', 'gsk_otra')

      expect(getStoredKey('gemini')).toBe('AIzaSyUna')
      expect(getStoredKey('groq')).toBe('gsk_otra')
      expect(localStorage.getItem('gemini_api_key')).toBe('gsk_otra')

      activateProvider('gemini', getStoredKey('gemini'))
      expect(localStorage.getItem('gemini_api_key')).toBe('AIzaSyUna')
      expect(getActiveProvider()).toBe('gemini')
    })
  })

  describe('rememberKey', () => {
    it('keeps a key without making it the one in use', () => {
      activateProvider('gemini', 'AIzaSyUna')
      rememberKey('groq', 'gsk_pendiente')

      expect(getStoredKey('groq')).toBe('gsk_pendiente')
      expect(localStorage.getItem('gemini_api_key')).toBe('AIzaSyUna')
      expect(getActiveProvider()).toBe('gemini')
    })
  })

  describe('forgetKey', () => {
    it('clears the key in use when the active provider is forgotten', () => {
      activateProvider('groq', 'gsk_nueva')
      forgetKey('groq')

      expect(getStoredKey('groq')).toBe('')
      expect(localStorage.getItem('gemini_api_key')).toBeNull()
    })

    it('does not touch the key in use when another provider is forgotten', () => {
      activateProvider('gemini', 'AIzaSyUna')
      rememberKey('groq', 'gsk_pendiente')
      forgetKey('groq')

      expect(getStoredKey('groq')).toBe('')
      expect(localStorage.getItem('gemini_api_key')).toBe('AIzaSyUna')
    })
  })

  describe('keyIsRejected', () => {
    it('rejects only keys that cannot work', () => {
      expect(keyIsRejected('invalid_key')).toBe(true)
      expect(keyIsRejected('no_model')).toBe(true)
    })

    it('keeps a good key when the failure is temporary', () => {
      expect(keyIsRejected('rate_limit')).toBe(false)
      expect(keyIsRejected('max_tokens')).toBe(false)
      expect(keyIsRejected(undefined)).toBe(false)
    })
  })
})
