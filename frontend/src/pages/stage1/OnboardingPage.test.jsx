import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import OnboardingPage from './OnboardingPage.jsx'
import { api } from '../../lib/api.js'

vi.mock('../../lib/api.js', () => ({
  api: { getOnboarding: vi.fn(), saveOnboarding: vi.fn() },
}))

describe('resumable onboarding', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.getOnboarding.mockResolvedValue({
      current_step: 'farm',
      completed_steps: ['profile'],
      draft: { farm_name: 'Sunrise Farm', state_code: 'KA' },
    })
    api.saveOnboarding.mockResolvedValue({})
  })

  it('restores the backend draft and saves the documented onboarding shape', async () => {
    render(
      <MemoryRouter>
        <OnboardingPage />
      </MemoryRouter>,
    )

    expect(await screen.findByLabelText('Farm name')).toHaveValue('Sunrise Farm')
    fireEvent.change(screen.getByLabelText('District'), { target: { value: 'Mysuru' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save and continue' }))

    await waitFor(() =>
      expect(api.saveOnboarding).toHaveBeenCalledWith(
        expect.objectContaining({
          current_step: 'preferences',
          completed_steps: ['profile', 'farm'],
          draft: expect.objectContaining({ farm_name: 'Sunrise Farm', district: 'Mysuru' }),
          completed: false,
        }),
      ),
    )
  })
})
