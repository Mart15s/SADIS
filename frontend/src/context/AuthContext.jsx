import { useCallback, useEffect, useMemo, useState } from 'react'
import { AuthContext } from './auth-context.js'
import { api, registerUnauthorizedHandler } from '../lib/api.js'
import {
  clearStoredAuth,
  normalizeAuthPayload,
  readStoredAuth,
  writeStoredAuth,
} from '../lib/auth.js'

export function AuthProvider({ children }) {
  const [authState, setAuthState] = useState(() => readStoredAuth())
  const [restoring, setRestoring] = useState(true)

  useEffect(
    () =>
      registerUnauthorizedHandler(() => {
        clearStoredAuth()
        setAuthState(normalizeAuthPayload({}))
        setRestoring(false)
      }),
    [],
  )

  useEffect(() => {
    let cancelled = false

    async function restoreSession() {
      try {
        const currentUser = await api.getMe()

        if (cancelled) {
          return
        }

        const payload = writeStoredAuth({
          user: currentUser,
          profile: currentUser.profile,
        })

        setAuthState(payload)
      } catch (error) {
        if (cancelled) {
          return
        }

        // Keep the last confirmed session during transient network/server
        // failures. Only a confirmed authentication response signs out.
        if ([401, 403, 419].includes(error.status)) {
          clearStoredAuth()
          setAuthState(normalizeAuthPayload({}))
        }
      } finally {
        if (!cancelled) setRestoring(false)
      }
    }

    restoreSession()

    return () => {
      cancelled = true
    }
  }, [])

  const authenticate = useCallback(async (request) => {
    const currentUser = request?.user ?? (await api.getMe())
    const payload = writeStoredAuth({
      user: currentUser,
      profile: request?.profile ?? currentUser?.profile,
    })
    setAuthState(payload)
    return payload
  }, [])

  const syncCurrentUser = useCallback(async (currentUser) => {
    const payload = writeStoredAuth({
      user: currentUser,
      profile: currentUser.profile,
    })

    setAuthState(payload)
    return payload
  }, [])

  const login = useCallback(
    async (credentials) => {
      const payload = await api.login(credentials)
      return authenticate(payload)
    },
    [authenticate],
  )

  const verifyOtpLogin = useCallback(
    async (credentials) => {
      const payload = await api.verifyOtp({ ...credentials, purpose: 'login' })
      return authenticate(payload)
    },
    [authenticate],
  )

  const register = useCallback(
    async (profileData) => {
      const payload = await api.register(profileData)
      return authenticate(payload)
    },
    [authenticate],
  )

  const updateAccount = useCallback(
    async (accountData) => {
      const currentUser = await api.updateMe(accountData)
      return syncCurrentUser(currentUser)
    },
    [syncCurrentUser],
  )

  const logout = useCallback(async () => {
    try {
      if (authState.user) {
        await api.logout()
      }
    } finally {
      clearStoredAuth()
      setAuthState(normalizeAuthPayload({}))
    }
  }, [authState.user])

  const value = useMemo(() => {
    const displayName = [authState.profile?.name, authState.profile?.surname]
      .filter(Boolean)
      .join(' ')

    return {
      ...authState,
      restoring,
      isAuthenticated: Boolean(authState.user),
      isAdmin: authState.user?.role === 'admin',
      displayName: displayName || authState.user?.email || 'Guest',
      login,
      verifyOtpLogin,
      register,
      updateAccount,
      logout,
    }
  }, [authState, login, logout, register, restoring, updateAccount, verifyOtpLogin])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
