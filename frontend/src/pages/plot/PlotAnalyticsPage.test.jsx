import React from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PlotAnalyticsPage from './PlotAnalyticsPage.jsx'
import { api } from '../../lib/api.js'

vi.mock('../../lib/api.js', () => ({
  api: {
    listPlots: vi.fn(),
    getPlot: vi.fn(),
    generatePlotAnalytics: vi.fn(),
  },
}))

describe('PlotAnalyticsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()

    api.listPlots.mockResolvedValue([{ id: 5, access_role: 'owner' }])
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
      expect(screen.getByRole('heading', { name: /North Plot/i })).toBeInTheDocument()
    })

    const generateButton = screen.getByRole('button', { name: /Generuoti analitiką/i })

    expect(generateButton).toBeDisabled()

    await user.click(screen.getByLabelText(/Planavimo sprendimai/i))

    expect(generateButton).toBeEnabled()

    await user.click(generateButton)

    await waitFor(() => {
      expect(api.generatePlotAnalytics).toHaveBeenCalledWith('5', {
        analysisTypes: ['planning'],
      })
    })

    expect(screen.getByRole('heading', { name: /Planavimo sprendimų analizė/i })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: /Derliaus analizė/i })).not.toBeInTheDocument()
  })

  it('limits analytics header badges to the access role and active plot name', async () => {
    render(
      <MemoryRouter initialEntries={['/plots/5/analytics']}>
        <Routes>
          <Route path="/plots/:plotId/analytics" element={<PlotAnalyticsPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /North Plot/i })).toBeInTheDocument()
    })

    const headerMeta = screen.getByLabelText(/Sklypo metaduomenys/i)
    const badges = headerMeta.querySelectorAll('.status-badge')

    expect(badges).toHaveLength(2)
    expect(badges[0]).toHaveTextContent('Savininkas')
    expect(badges[1]).toHaveTextContent('North Plot')
    expect(headerMeta).not.toHaveTextContent('Vilnius')
    expect(headerMeta).not.toHaveTextContent('Pasirinkite analitikos rinkinius')
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
      warnings: ['Derliaus istorijos nėra pasirinktam sklypui.'],
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
      expect(screen.getByRole('heading', { name: /North Plot/i })).toBeInTheDocument()
    })

    await user.click(screen.getByLabelText(/Planavimo sprendimai/i))
    await user.click(screen.getByLabelText(/Derlius/i))
    await user.click(screen.getByRole('button', { name: /Generuoti analitiką/i }))

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /Įspėjimai/i })).toBeInTheDocument()
    })

    expect(screen.getByText(/Derliaus istorijos nėra pasirinktam sklypui/i)).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /Derliaus analizė/i })).toBeInTheDocument()
    expect(screen.getByText(/Šiam sklypui derliaus istorijos dar nėra/i)).toBeInTheDocument()
  })
})
