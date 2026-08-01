import { beforeEach, describe, expect, it } from 'vitest'
import { clearStoredAuth, getAuthToken, readStoredAuth, writeStoredAuth } from './auth.js'

describe('cookie-first session storage', () => {
  beforeEach(() => localStorage.clear())

  it('reads a legacy bearer token only for migration', () => {
    localStorage.setItem('sad-system-auth', JSON.stringify({ token: 'legacy', user: { id: 1 } }))
    expect(getAuthToken()).toBe('legacy')
    expect(readStoredAuth().token).toBe('legacy')
  })

  it('clears the bearer token after a cookie session user is confirmed', () => {
    localStorage.setItem('sad-system-auth', JSON.stringify({ token: 'legacy', user: { id: 1 } }))
    const session = writeStoredAuth({ token: 'never-persist', user: { id: 1, email: 'farmer@example.test' } })
    expect(session.token).toBeNull()
    expect(getAuthToken()).toBeNull()
    expect(readStoredAuth().user.email).toBe('farmer@example.test')
    clearStoredAuth()
    expect(readStoredAuth().user).toBeNull()
  })
})
