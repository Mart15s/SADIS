import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PlotRotationPage from './PlotRotationPage.jsx'
import { api } from '../../lib/api.js'

vi.mock('../../lib/api.js', () => ({
  api: {
    listPlots: vi.fn(),
    getPlot: vi.fn(),
    listPlants: vi.fn(),
    listPlantZones: vi.fn(),
    listRotations: vi.fn(),
    createRotationPlan: vi.fn(),
    confirmRotationPlan: vi.fn(),
    rejectRotationPlan: vi.fn(),
  },
}))

vi.mock('../../components/plot/PlotSectionNav.jsx', () => ({
  default: ({ plotName, sectionLabel = 'Rotation', description, meta, actions }) => (
    <nav data-testid="plot-section-nav">
      <span>Plots</span>
      <span>{sectionLabel}</span>
      <h1>{plotName}</h1>
      <p>{description}</p>
      <div>{meta}</div>
      <div>{actions}</div>
    </nav>
  ),
}))

vi.mock('../../components/layout/PageHeader.jsx', () => ({
  default: ({ title, description, actions, meta }) => (
    <header>
      <h1>{title}</h1>
      <p>{description}</p>
      <div>{meta}</div>
      <div>{actions}</div>
    </header>
  ),
}))

describe('PlotRotationPage', () => {
  function renderPage() {
    return render(
      <MemoryRouter initialEntries={['/plots/5/rotation']}>
        <Routes>
          <Route path="/plots/:plotId/rotation" element={<PlotRotationPage />} />
          <Route path="/plots/:plotId" element={<div>editor</div>} />
        </Routes>
      </MemoryRouter>,
    )
  }

  beforeEach(() => {
    vi.clearAllMocks()

    api.listPlots.mockResolvedValue([{ id: 5, access_role: 'owner' }])
    api.getPlot.mockResolvedValue({ id: 5, name: 'North Plot' })
    api.listPlants.mockResolvedValue([
      { id: 22, name: 'Pepper', plant_zone_id: 11, plant_zone: { id: 11, name: 'Seedlings' } },
    ])
    api.listPlantZones.mockResolvedValue([
      { id: 11, name: 'Seedlings' },
      { id: 12, name: 'Zone A' },
    ])
    api.listRotations.mockResolvedValue([])
    api.createRotationPlan.mockResolvedValue({
      draft: {
        id: 90,
        plan: {
          status: 'needs_adjustment',
          summary: {
            plant_count: 1,
            assigned_plant_count: 0,
            unresolved_plant_count: 1,
            blocked_plant_count: 1,
          },
          plants: [
            {
              plant: { id: 22, name: 'Pepper' },
              current_zone: { id: 11, name: 'Seedlings' },
              selected_target_zone: null,
              alternatives: [],
              fallback_solutions: ['Choose a different zone or wait another season.'],
              candidate_zones: [
                {
                  zone_id: 12,
                  zone_name: 'Zone A',
                  is_eligible: false,
                  score: 0,
                  hard_blocking_reasons: ['Same family was planted here in 2025.'],
                },
              ],
            },
          ],
        },
      },
    })
  })

  it('shows blocked rotation zones and keeps an unresolved draft unconfirmable', async () => {
    renderPage()

    await waitFor(() => {
      expect(screen.getByText('North Plot')).toBeInTheDocument()
    })

    fireEvent.click(screen.getByRole('button', { name: 'Generate rotation draft' }))

    await waitFor(() => {
      expect(api.createRotationPlan).toHaveBeenCalledWith('5', expect.objectContaining({
        planning_date: expect.any(String),
      }))
    })

    expect(screen.getByText('Needs adjustment')).toBeInTheDocument()
    expect(screen.getByText('Pepper')).toBeInTheDocument()
    expect(screen.getByText('Zone A')).toBeInTheDocument()
    expect(screen.getByText('Same family was planted here in 2025.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Confirm rotation plan' })).toBeDisabled()
  })
})
