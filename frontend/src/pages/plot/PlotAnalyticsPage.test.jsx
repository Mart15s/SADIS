import React from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PlotAnalyticsPage from './PlotAnalyticsPage.jsx'
import { api } from '../../lib/api.js'

vi.mock('../../lib/api.js', () => ({
  api: {
    getPlot: vi.fn(),
    generatePlotAnalytics: vi.fn(),
  },
}))

describe('PlotAnalyticsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()

    api.getPlot.mockResolvedValue({
      id: 5,
      name: 'North Plot',
      city: 'Vilnius',
    })
  })

  it('keeps generation disabled until at least one analysis type is selected and renders the chosen section', async () => {
    api.generatePlotAnalytics.mockResolvedValue({
      plot: { id: 5, name: 'North Plot' },
      selectedAnalysisTypes: ['planning'],
      sections: {
        planning: {
          status: 'ready',
          total_versions: 2,
          change_events_count: 1,
          plan_change_frequency: { changes_per_month: 1.5 },
          rotation_violation_count: 0,
          zone_season_selections: [],
          rotation_history: { zone_participation_counts: [] },
          rotation_violations: [],
        },
      },
      summary: {
        total_zones: 2,
        total_plants: 4,
        sections_with_data_count: 1,
        sections_without_data_count: 0,
        has_actionable_data: true,
      },
      warnings: [],
    })

    const user = userEvent.setup()

    render(
      <MemoryRouter initialEntries={['/plots/5/analytics']}>
        <Routes>
          <Route path="/plots/:plotId/analytics" element={<PlotAnalyticsPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /North Plot analytics/i })).toBeInTheDocument()
    })

    const generateButton = screen.getByRole('button', { name: /Generate analysis/i })

    expect(generateButton).toBeDisabled()

    await user.click(screen.getByLabelText(/Planning decisions/i))

    expect(generateButton).toBeEnabled()

    await user.click(generateButton)

    await waitFor(() => {
      expect(api.generatePlotAnalytics).toHaveBeenCalledWith('5', {
        analysisTypes: ['planning'],
      })
    })

    expect(screen.getByRole('heading', { name: /Planning decisions analysis/i })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: /Harvest analysis/i })).not.toBeInTheDocument()
  })

  it('renders warnings and no-data sections without crashing', async () => {
    api.generatePlotAnalytics.mockResolvedValue({
      plot: { id: 5, name: 'North Plot' },
      selectedAnalysisTypes: ['planning', 'harvest'],
      sections: {
        planning: {
          status: 'ready',
          total_versions: 1,
          change_events_count: 0,
          plan_change_frequency: { changes_per_month: 0 },
          rotation_violation_count: 0,
          zone_season_selections: [],
          rotation_history: { zone_participation_counts: [] },
          rotation_violations: [],
        },
        harvest: {
          status: 'no_data',
        },
      },
      summary: {
        total_zones: 1,
        total_plants: 2,
        sections_with_data_count: 1,
        sections_without_data_count: 1,
        has_actionable_data: true,
      },
      warnings: ['No harvest history is available for the selected plot.'],
    })

    const user = userEvent.setup()

    render(
      <MemoryRouter initialEntries={['/plots/5/analytics']}>
        <Routes>
          <Route path="/plots/:plotId/analytics" element={<PlotAnalyticsPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /North Plot analytics/i })).toBeInTheDocument()
    })

    await user.click(screen.getByLabelText(/Planning decisions/i))
    await user.click(screen.getByLabelText(/Harvest/i))
    await user.click(screen.getByRole('button', { name: /Generate analysis/i }))

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /Warnings/i })).toBeInTheDocument()
    })

    expect(screen.getByText(/No harvest history is available for the selected plot/i)).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /Harvest analysis/i })).toBeInTheDocument()
    expect(screen.getByText(/No harvest history is available for this plot yet/i)).toBeInTheDocument()
  })
})
