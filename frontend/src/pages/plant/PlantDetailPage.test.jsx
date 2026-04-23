import React from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PlantDetailPage from './PlantDetailPage.jsx'
import { api } from '../../lib/api.js'

vi.mock('../../lib/api.js', () => ({
  api: {
    listPlots: vi.fn(),
    getManagedPlant: vi.fn(),
    getPlant: vi.fn(),
    listPlantConditions: vi.fn(),
    listHarvests: vi.fn(),
    listRotations: vi.fn(),
    createPlantCondition: vi.fn(),
    completeTask: vi.fn(),
  },
}))

function buildPlant(overrides = {}) {
  return {
    id: 12,
    name: 'Tomato',
    plant_type: 'vegetable',
    condition: 'growing',
    plant_date: '2026-04-01',
    fk_plot_id: 5,
    plot: {
      id: 5,
      name: 'North Plot',
    },
    plant_zone: {
      id: 7,
      name: 'Zone A',
    },
    plant_care: {
      id: 33,
      watering_interval_days: 2,
      fertilizing_interval_days: 10,
      pest_check_interval_days: 7,
      germinating_duration_days: 4,
      growing_duration_days: 10,
      flowering_duration_days: 5,
      mature_duration_days: 8,
      regenerating_duration_days: 0,
    },
    lifecycle: {
      current_condition: 'growing',
      current_condition_anchor_date: '2026-04-05',
      next_review: {
        target_condition: 'flowering',
        expected_on: '2026-04-15',
        is_overdue: false,
      },
      next_harvest: {
        expected_on: '2026-04-28',
        is_overdue: false,
      },
      scheduled_stage_starts: {
        planted: '2026-04-01',
        germinating: '2026-04-02',
        growing: '2026-04-06',
        flowering: '2026-04-16',
        mature: '2026-04-21',
      },
      supports_regeneration: false,
    },
    ...overrides,
  }
}

describe('PlantDetailPage routing', () => {
  beforeEach(() => {
    vi.clearAllMocks()

    api.listPlots.mockResolvedValue([{ id: 5, access_role: 'owner' }])
    api.getManagedPlant.mockResolvedValue(buildPlant())
    api.getPlant.mockResolvedValue(buildPlant())
    api.listPlantConditions.mockResolvedValue([
      { id: 1, measured_at: '2026-04-05T12:00:00Z', condition: 'growing', notes: 'Stable' },
    ])
    api.listHarvests.mockResolvedValue([
      { id: 9, harvested_on: '2026-04-28', quantity: 4, task_name: 'Harvest Tomato' },
    ])
    api.listRotations.mockResolvedValue([])
  })

  it('uses the unified detail experience from the plants list route', async () => {
    render(
      <MemoryRouter initialEntries={['/plants/12']}>
        <Routes>
          <Route path="/plants/:plantId" element={<PlantDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Tomato' })).toBeInTheDocument()
    })

    expect(screen.getByRole('heading', { name: /Lifecycle Guidance/i })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /Condition History/i })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /Harvest History/i })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Back to plants/i })).toHaveAttribute('href', '/plants')
  })

  it('uses the same detail experience from the plot route while keeping plot back navigation', async () => {
    render(
      <MemoryRouter initialEntries={['/plots/5/plants/12']}>
        <Routes>
          <Route path="/plots/:plotId/plants/:plantId" element={<PlantDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await waitFor(() => {
      expect(api.getPlant).toHaveBeenCalledWith('5', '12')
    })

    expect(screen.getByRole('heading', { name: /Lifecycle Guidance/i })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /Condition History/i })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /Harvest History/i })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Back to plot/i })).toHaveAttribute('href', '/plots/5')
  })
})
