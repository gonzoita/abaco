// frontend/src/utils/fetchSafe.js
// Carga tolerante a fallos: en hosting compartido una petición suelta puede
// fallar por un pico de carga aunque las demás respondan bien. Reintenta 1
// vez (con una pequeña espera) antes de darse por vencida, y exige
// response.ok para no confundir un error del servidor con datos vacíos
// válidos. Usado por Dashboard y Préstamos (antes duplicado en cada uno).
export async function fetchJsonSafe(url, options = {}, retries = 1) {
  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      const res = await fetch(url, options)
      if (!res.ok) throw new Error(`HTTP ${res.status} en ${url}`)
      return await res.json()
    } catch (err) {
      if (attempt === retries) throw err
      await new Promise(resolve => setTimeout(resolve, 700))
    }
  }
}
