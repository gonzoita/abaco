// frontend/src/utils/aiProvider.js
// Proveedores de IA que el usuario puede vincular con SU PROPIA clave
// gratuita. La clave y el proveedor se guardan solo en este navegador.
//
// La clave EN USO se sigue guardando en 'gemini_api_key' (el nombre que ya
// leen todas las vistas para mandar la cabecera X-Gemini-API-Key): así no hay
// que tocar cada vista. Además, cada proveedor recuerda su propia clave
// ('ai_key_<proveedor>') para poder alternar sin volver a pegarla.

export const PROVIDERS = {
  gemini: {
    id: 'gemini',
    name: 'Gemini',
    company: 'Google',
    keysUrl: 'https://aistudio.google.com/apikey',
    keysLabel: 'aistudio.google.com/apikey',
    placeholder: 'AIzaSy...',
    keyPrefix: 'AIza',
  },
  groq: {
    id: 'groq',
    name: 'Groq',
    company: 'Groq',
    keysUrl: 'https://console.groq.com/keys',
    keysLabel: 'console.groq.com/keys',
    placeholder: 'gsk_...',
    keyPrefix: 'gsk_',
  },
}

const ACTIVE_KEY = 'gemini_api_key'
const PROVIDER_KEY = 'ai_provider'

function read(name) {
  try {
    return localStorage.getItem(name) || ''
  } catch {
    return ''
  }
}

function write(name, value) {
  try {
    if (value) localStorage.setItem(name, value)
    else localStorage.removeItem(name)
  } catch {
    // Sin almacenamiento disponible (ventana privada, datos bloqueados): la
    // clave simplemente no se recuerda; nunca debe tumbar la pantalla.
  }
}

/** Qué proveedor delata el prefijo de una clave pegada, o null si no se reconoce. */
export function detectProvider(key) {
  const k = (key || '').trim()
  for (const provider of Object.values(PROVIDERS)) {
    if (k.startsWith(provider.keyPrefix)) return provider.id
  }
  return null
}

/**
 * Proveedor en uso. Quien ya tenía una clave de Gemini guardada antes de que
 * existiera el selector no tiene 'ai_provider': se deduce de su clave.
 */
export function getActiveProvider() {
  const stored = read(PROVIDER_KEY)
  if (PROVIDERS[stored]) return stored
  return detectProvider(read(ACTIVE_KEY)) || 'gemini'
}

/** La clave guardada de un proveedor ('' si no hay). */
export function getStoredKey(provider) {
  const own = read('ai_key_' + provider)
  if (own) return own
  const active = read(ACTIVE_KEY)
  return active && getActiveProvider() === provider ? active : ''
}

/** Recuerda la clave de un proveedor sin activarla. */
export function rememberKey(provider, key) {
  write('ai_key_' + provider, key)
}

/**
 * Deja un proveedor y su clave como los que usa la app. Antes de pisar la
 * clave que estaba en uso se guarda bajo SU proveedor: quien vinculó Gemini
 * antes de que existiera el selector solo la tiene en 'gemini_api_key', y sin
 * esto perdería su clave al probar Groq.
 */
export function activateProvider(provider, key) {
  const previousKey = read(ACTIVE_KEY)
  const previousProvider = getActiveProvider()
  if (previousKey && !read('ai_key_' + previousProvider)) {
    write('ai_key_' + previousProvider, previousKey)
  }
  rememberKey(provider, key)
  write(PROVIDER_KEY, provider)
  write(ACTIVE_KEY, key)
}

/** Borra la clave de un proveedor; si era la que se usaba, la app queda sin clave. */
export function forgetKey(provider) {
  const wasActive = getActiveProvider() === provider
  write('ai_key_' + provider, '')
  if (wasActive) write(ACTIVE_KEY, '')
}

/**
 * ¿Un fallo de verificación significa que la clave NO sirve? Los demás casos
 * (límite de peticiones, servidor saturado...) son pasajeros: la clave es
 * buena y conviene dejarla en uso.
 */
export function keyIsRejected(errorKind) {
  return errorKind === 'invalid_key' || errorKind === 'no_model'
}
