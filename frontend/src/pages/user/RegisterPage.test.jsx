import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import RegisterPage from './RegisterPage.jsx'

const register = vi.fn().mockResolvedValue({ user: { id: 1 } })

vi.mock('../../context/auth-context.js', () => ({
  useAuth: () => ({ register }),
}))

describe('registration handoff', () => {
  it('opens onboarding after creating the account', async () => {
    render(
      <MemoryRouter initialEntries={['/register']}>
        <Routes>
          <Route path="/register" element={<RegisterPage />} />
          <Route path="/onboarding" element={<p>Onboarding workspace</p>} />
        </Routes>
      </MemoryRouter>,
    )

    fireEvent.change(screen.getByLabelText('First name'), { target: { value: 'Maya' } })
    fireEvent.change(screen.getByLabelText('Last name'), { target: { value: 'Grower' } })
    fireEvent.change(screen.getByLabelText('Email address'), {
      target: { value: 'maya@example.test' },
    })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'password123' } })
    fireEvent.change(screen.getByLabelText('Confirm password'), {
      target: { value: 'password123' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Create account' }))

    await waitFor(() => expect(screen.getByText('Onboarding workspace')).toBeInTheDocument())
    expect(register).toHaveBeenCalledWith({
      name: 'Maya',
      surname: 'Grower',
      email: 'maya@example.test',
      password: 'password123',
      password_confirmation: 'password123',
    })
  })
})
