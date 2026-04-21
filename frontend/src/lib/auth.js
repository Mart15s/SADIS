const AUTH_STORAGE_KEY = 'sad-system-auth'

export function normalizeAuthPayload(payload) {
  return {
    token: payload?.token ?? null,
    user: payload?.user ?? null,
    profile: payload?.profile ?? null,
  }
}

export function readStoredAuth() {
  try {
    const raw = window.localStorage.getItem(AUTH_STORAGE_KEY)

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

  if (!auth.token) {
    clearStoredAuth()
    return auth
  }

  window.localStorage.setItem(AUTH_STORAGE_KEY, JSON.stringify(auth))
  return auth
}

export function clearStoredAuth() {
  window.localStorage.removeItem(AUTH_STORAGE_KEY)
}

export function getAuthToken() {
  return readStoredAuth().token
}
