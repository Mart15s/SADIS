import React from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PlotDetailPage from './PlotDetailPage.jsx'
import { api } from '../../lib/api.js'

vi.mock('../../lib/api.js', () => ({
  api: {
    listPlots: vi.fn(),
    getPlot: vi.fn(),
    listPlantZones: vi.fn(),
    listPlants: vi.fn(),
    listRotations: vi.fn(),
    listAccessRights: vi.fn(),
    createPlant: vi.fn(),
    deletePlant: vi.fn(),
    createPlantZone: vi.fn(),
    updatePlantZone: vi.fn(),
    deletePlantZone: vi.fn(),
    updatePlot: vi.fn(),
    downloadPlotPdf: vi.fn(),
    createRotationPlan: vi.fn(),
    confirmRotationPlan: vi.fn(),
    rejectRotationPlan: vi.fn(),
    sharePlot: vi.fn(),
    revokeAccessRight: vi.fn(),
  },
}))

vi.mock('../../components/plot/PlotDesignerCanvas.jsx', () => ({
  default: () => <div data-testid="plot-designer-canvas">canvas</div>,
}))

vi.mock('../../components/layout/PageHeader.jsx', () => ({
  default: ({ title, description, actions }) => (
    <div>
      <h1>{title}</h1>
      <p>{description}</p>
      <div>{actions}</div>
    </div>
  ),
}))

describe('PlotDetailPage workspace layout', () => {
  beforeEach(() => {
    vi.clearAllMocks()

    api.listPlots.mockResolvedValue([{ id: 5, access_role: 'owner' }])
    api.getPlot.mockResolvedValue({
      id: 5,
      name: 'North Plot',
      city: 'Vilnius',
      plot_size: 42,
      geometry: null,
    })
    api.listPlantZones.mockResolvedValue([
      {
        id: 11,
        name: 'Zone A',
        zone_size: 12,
        soil_type: 'clay',
        rotation_stage: 0,
        geometry: null,
      },
    ])
    api.listPlants.mockResolvedValue([
      {
        id: 22,
        name: 'Cucumber',
        type: 'vegetable',
        condition: 'growing',
        plant_zone_id: 11,
        fk_plant_zone_id: 11,
        plant_zone: { id: 11, name: 'Zone A' },
      },
    ])
    api.listRotations.mockResolvedValue([])
    api.listAccessRights.mockResolvedValue([])
    api.createRotationPlan.mockResolvedValue({
      draft: {
        id: 77,
        planning_date: '2026-04-20',
        plan: {
          status: 'needs_adjustment',
          summary: {
            assigned_plant_count: 0,
            plant_count: 1,
          },
          plants: [
            {
              plant: { id: 22, name: 'Cucumber' },
              current_zone: { id: 11, name: 'Zone A' },
              selected_target_zone: null,
              alternatives: [],
              candidate_zones: [
                {
                  zone_id: 11,
                  zone_name: 'Zone A',
                  verdict: 'invalid',
                  score: 0,
                  blocking_reasons: ['Target zone conflict detected.'],
                  passed_reasons: [],
                },
              ],
              fallback_solutions: ['Delay the plan until another zone becomes available.'],
            },
          ],
        },
      },
    })
  })

  it('renders the locked workspace classes used to avoid the old double-scroll layout', async () => {
    const { container } = render(
      <MemoryRouter initialEntries={['/plots/5']}>
        <Routes>
          <Route path="/plots/:plotId" element={<PlotDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await waitFor(() => {
      expect(screen.getByText('North Plot')).toBeInTheDocument()
    })

    expect(screen.getByTestId('workspace-page')).toHaveClass('workspace-page--locked')
    expect(container.querySelector('.plot-workspace-main--locked')).not.toBeNull()
    expect(container.querySelector('.plot-workspace-sidebar--locked')).not.toBeNull()
  })

  it('shows rejected target zones without any passed-checks copy', async () => {
    render(
      <MemoryRouter initialEntries={['/plots/5']}>
        <Routes>
          <Route path="/plots/:plotId" element={<PlotDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await waitFor(() => {
      expect(screen.getByText('North Plot')).toBeInTheDocument()
    })

    fireEvent.click(screen.getByRole('button', { name: 'Generate rotation scheme' }))

    await waitFor(() => {
      expect(screen.getByTestId('rotation-plan-preview')).toBeInTheDocument()
    })

    expect(screen.getByText('Rejected target zones')).toBeInTheDocument()
    expect(screen.getByText('Target zone conflict detected.')).toBeInTheDocument()
    expect(screen.queryByText('Target zone passed the current suitability checks.')).not.toBeInTheDocument()
  })
})
