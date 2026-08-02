import { render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './AuthContext.jsx'
import { useAuth } from './auth-context.js'
import { api } from '../lib/api.js'

vi.mock('../lib/api.js', () => ({
  api: { getMe: vi.fn(), verifyOtp: vi.fn() },
  registerUnauthorizedHandler: vi.fn(() => vi.fn()),
}))

function AuthProbe() {
  const auth = useAuth()
  return (
    <div data-testid="auth-state">
      {auth.restoring ? 'restoring' : auth.isAuthenticated ? 'authenticated' : 'signed-out'}
    </div>
  )
}

describe('authentication restoration', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('does not retain a cached user after the active-account check returns 403', async () => {
    localStorage.setItem(
      'yava-session-user',
      JSON.stringify({ user: { id: 7, email: 'disabled@example.com' } }),
    )
    api.getMe.mockRejectedValue(Object.assign(new Error('Account deactivated'), { status: 403 }))

    render(
      <AuthProvider>
        <AuthProbe />
      </AuthProvider>,
    )
    expect(screen.getByTestId('auth-state')).toHaveTextContent('restoring')
    await waitFor(() => expect(screen.getByTestId('auth-state')).toHaveTextContent('signed-out'))
    expect(localStorage.getItem('yava-session-user')).toBeNull()
  })
})
