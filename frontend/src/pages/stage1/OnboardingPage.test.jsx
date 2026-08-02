import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import OnboardingPage from './OnboardingPage.jsx'
import { api } from '../../lib/api.js'

vi.mock('../../lib/api.js', () => ({
  api: { getOnboarding: vi.fn(), saveOnboarding: vi.fn() },
}))

const workspace = { contexts: [], reload: vi.fn() }
vi.mock('../../context/useWorkspace.js', () => ({ useWorkspace: () => workspace }))

describe('resumable onboarding', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.getOnboarding.mockResolvedValue({
      current_step: 'farm',
      completed_steps: ['profile'],
      draft: { farm_name: 'Sunrise Farm', farm_area_square_metres: '12000', state_code: 'KA' },
    })
    api.saveOnboarding.mockResolvedValue({})
    workspace.contexts = []
    workspace.reload.mockReset().mockResolvedValue([])
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
          current_step: 'community',
          completed_steps: ['profile', 'farm'],
          draft: expect.objectContaining({ farm_name: 'Sunrise Farm', district: 'Mysuru' }),
          completed: false,
        }),
      ),
    )
  })

  it('finishes with a fully provisionable draft and reloads the preferred farm context', async () => {
    const draft = {
      first_name: 'Asha',
      last_name: 'Patel',
      mode: 'independent',
      farm_action: 'create',
      farm_name: 'Sunrise Farm',
      farm_area_square_metres: '12000',
      timezone: 'Asia/Kolkata',
      field_name: 'North Field',
      field_area_square_metres: '6000',
      crop_name: 'Millet',
      starts_on: '2026-08-02',
      area_unit: 'hectare',
      locale: 'en-IN',
    }
    api.getOnboarding.mockResolvedValue({
      current_step: 'preferences',
      completed_steps: ['profile', 'mode', 'farm', 'community', 'field', 'season'],
      draft,
    })
    api.saveOnboarding.mockResolvedValue({
      provisioned: { preferred_context: { type: 'farm', id: 42, name: 'Sunrise Farm' } },
    })

    render(
      <MemoryRouter>
        <OnboardingPage />
      </MemoryRouter>,
    )
    expect(await screen.findByLabelText('Timezone')).toHaveValue('Asia/Kolkata')
    fireEvent.click(screen.getByRole('button', { name: 'Finish setup' }))

    await waitFor(() =>
      expect(api.saveOnboarding).toHaveBeenCalledWith(
        expect.objectContaining({
          completed: true,
          draft: expect.objectContaining({
            farm_name: 'Sunrise Farm',
            field_name: 'North Field',
            crop_name: 'Millet',
          }),
        }),
      ),
    )
    expect(workspace.reload).toHaveBeenCalledWith({ type: 'farm', id: 42, name: 'Sunrise Farm' })
  })

  it('saves an incomplete step in place so onboarding resumes where the user left it', async () => {
    render(
      <MemoryRouter>
        <OnboardingPage />
      </MemoryRouter>,
    )

    expect(await screen.findByLabelText('Farm name')).toHaveValue('Sunrise Farm')
    fireEvent.click(screen.getByRole('button', { name: 'Save and finish later' }))

    await waitFor(() =>
      expect(api.saveOnboarding).toHaveBeenCalledWith(
        expect.objectContaining({
          current_step: 'farm',
          completed_steps: ['profile'],
          completed: false,
        }),
      ),
    )
  })
})
