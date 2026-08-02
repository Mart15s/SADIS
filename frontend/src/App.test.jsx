import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthRoute } from './App.jsx'
import { useAuth } from './context/auth-context.js'

vi.mock('./context/auth-context.js', () => ({
  useAuth: vi.fn(),
}))

describe('AuthRoute', () => {
  beforeEach(() => {
    useAuth.mockReturnValue({ isAuthenticated: true, restoring: false })
  })

  it('preserves the safe protected destination after authentication', async () => {
    render(
      <MemoryRouter initialEntries={['/login?redirect=%2Ffields']}>
        <Routes>
          <Route
            path="/login"
            element={
              <AuthRoute>
                <div>Sign in form</div>
              </AuthRoute>
            }
          />
          <Route path="/fields" element={<div>Fields workspace</div>} />
        </Routes>
      </MemoryRouter>,
    )

    expect(await screen.findByText('Fields workspace')).toBeInTheDocument()
  })
})
