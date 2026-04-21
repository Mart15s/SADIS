import { createContext, useContext, useEffect, useMemo, useState } from 'react'
import { api, registerUnauthorizedHandler } from '../lib/api.js'
import {
  clearStoredAuth,
  normalizeAuthPayload,
  readStoredAuth,
  writeStoredAuth,
} from '../lib/auth.js'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [authState, setAuthState] = useState(() => readStoredAuth())

  useEffect(() => registerUnauthorizedHandler(() => {
    clearStoredAuth()
    setAuthState(normalizeAuthPayload({}))
  }), [])

  useEffect(() => {
    if (!authState.token) {
      return undefined
    }

    let cancelled = false

    async function restoreSession() {
      try {
        const currentUser = await api.getMe()

        if (cancelled) {
          return
        }

        const payload = writeStoredAuth({
          token: authState.token,
          user: currentUser,
          profile: currentUser.profile,
        })

        setAuthState(payload)
      } catch {
        if (cancelled) {
          return
        }

        clearStoredAuth()
        setAuthState(normalizeAuthPayload({}))
      }
    }

    restoreSession()

    return () => {
      cancelled = true
    }
  }, [authState.token])

  async function authenticate(request) {
    const payload = writeStoredAuth(request)
    setAuthState(payload)
    return payload
  }

  async function syncCurrentUser(currentUser) {
    const payload = writeStoredAuth({
      token: authState.token,
      user: currentUser,
      profile: currentUser.profile,
    })

    setAuthState(payload)
    return payload
  }

  async function login(credentials) {
    const payload = await api.login(credentials)
    return authenticate(payload)
  }

  async function register(profileData) {
    const payload = await api.register(profileData)
    return authenticate(payload)
  }

  async function updateAccount(accountData) {
    const currentUser = await api.updateMe(accountData)
    return syncCurrentUser(currentUser)
  }

  async function logout() {
    try {
      if (authState.token) {
        await api.logout()
      }
    } finally {
      clearStoredAuth()
      setAuthState(normalizeAuthPayload({}))
    }
  }

  const value = useMemo(() => {
    const displayName = [authState.profile?.name, authState.profile?.surname]
      .filter(Boolean)
      .join(' ')

    return {
      ...authState,
      isAuthenticated: Boolean(authState.token),
      isAdmin: authState.user?.role === 'admin',
      displayName: displayName || authState.user?.email || 'Guest',
      login,
      register,
      updateAccount,
      logout,
    }
  }, [authState])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const value = useContext(AuthContext)

  if (!value) {
    throw new Error('useAuth must be used within AuthProvider.')
  }

  return value
}
