const LEGACY_AUTH_STORAGE_KEY = 'sad-system-auth'
const SESSION_CACHE_KEY = 'yava-session-user'

export function normalizeAuthPayload(payload) {
  return {
    token: payload?.token ?? null,
    user: payload?.user ?? null,
    profile: payload?.profile ?? null,
  }
}

export function readStoredAuth() {
  try {
    const cachedSession = window.localStorage.getItem(SESSION_CACHE_KEY)
    if (cachedSession) return normalizeAuthPayload(JSON.parse(cachedSession))

    // One-time compatibility bridge for existing installations. The bearer
    // token is removed when the cookie-backed session is confirmed.
    const raw = window.localStorage.getItem(LEGACY_AUTH_STORAGE_KEY)

    if (!raw) {
      return normalizeAuthPayload({})
    }

    return normalizeAuthPayload(JSON.parse(raw))
  } catch {
    return normalizeAuthPayload({})
  }
}

export function writeStoredAuth(payload) {
  const auth = normalizeAuthPayload(payload)

  if (!auth.user) {
    clearStoredAuth()
    return auth
  }

  const session = { ...auth, token: null }
  window.localStorage.setItem(SESSION_CACHE_KEY, JSON.stringify(session))
  window.localStorage.removeItem(LEGACY_AUTH_STORAGE_KEY)
  return session
}

export function clearStoredAuth() {
  window.localStorage.removeItem(LEGACY_AUTH_STORAGE_KEY)
  window.localStorage.removeItem(SESSION_CACHE_KEY)
}

export function getAuthToken() {
  try {
    return JSON.parse(window.localStorage.getItem(LEGACY_AUTH_STORAGE_KEY))?.token ?? null
  } catch {
    return null
  }
}
