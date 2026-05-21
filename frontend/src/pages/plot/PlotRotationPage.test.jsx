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
    updateRotationDraftItem: vi.fn(),
    confirmRotationPlan: vi.fn(),
    rejectRotationPlan: vi.fn(),
  },
}))

vi.mock('../../components/plot/PlotSectionNav.jsx', () => ({
  default: ({ plotName, sectionLabel = 'Rotacija', description, meta, actions }) => (
    <nav data-testid="plot-section-nav">
      <span>Sklypai</span>
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
    api.updateRotationDraftItem.mockResolvedValue({
      draft: {
        id: 90,
        plan: {
          status: 'ready',
          summary: {
            plant_count: 1,
            annual_plant_count: 1,
            assigned_plant_count: 1,
            manually_edited_count: 1,
            unresolved_plant_count: 0,
            blocked_plant_count: 0,
          },
          plants: [
            {
              plant: { id: 22, name: 'Pepper' },
              current_zone: { id: 11, name: 'Seedlings' },
              generated_target_zone: null,
              selected_target_zone: {
                zone_id: 12,
                zone_name: 'Zone A',
                is_eligible: true,
                score: 7,
                positive_reasons: ['Target zone has enough space for this plant.'],
              },
              decision_mode: 'target',
              resolution_status: 'manual_override',
              manual_note: null,
              alternatives: [],
              fallback_solutions: [],
              candidate_zones: [
                {
                  zone_id: 12,
                  zone_name: 'Zone A',
                  is_eligible: true,
                  score: 7,
                  hard_blocking_reasons: [],
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
      expect(screen.getAllByText('North Plot').length).toBeGreaterThan(0)
    })

    fireEvent.click(screen.getByRole('button', { name: 'Generuoti rotacijos juodraštį' }))

    await waitFor(() => {
      expect(api.createRotationPlan).toHaveBeenCalledWith('5', expect.objectContaining({
        planning_date: expect.any(String),
      }))
    })

    expect(screen.getByText('Reikia korekcijos')).toBeInTheDocument()
    expect(screen.getByText('Pepper')).toBeInTheDocument()
    expect(screen.getByText('Zone A')).toBeInTheDocument()
    expect(screen.getByText('Tos pačios šeimos augalas čia sodintas 2025 m.')).toBeInTheDocument()
    expect(screen.getByText('Šio juodraščio patvirtinti negalima, nes 1 vienmečiams augalams dar reikia tinkamos tikslinės zonos.')).toBeInTheDocument()
    expect(screen.getByText('Reikia tinkamos tikslinės zonos')).toBeInTheDocument()
    expect(screen.getByLabelText('Sprendimas')).toBeInTheDocument()
    expect(screen.queryByText('Needs manual review')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Patvirtinti rotacijos planą' })).toBeDisabled()
  })

  it('lets a user edit a generated draft item target zone inline', async () => {
    api.createRotationPlan.mockResolvedValueOnce({
      draft: {
        id: 90,
        plan: {
          status: 'needs_adjustment',
          summary: {
            plant_count: 1,
            annual_plant_count: 1,
            assigned_plant_count: 0,
            unresolved_plant_count: 1,
            blocked_plant_count: 1,
          },
          plants: [
            {
              plant: { id: 22, name: 'Pepper' },
              current_zone: { id: 11, name: 'Seedlings' },
              generated_target_zone: null,
              selected_target_zone: null,
              decision_mode: 'unresolved',
              resolution_status: 'unresolved',
              alternatives: [],
              fallback_solutions: [],
              candidate_zones: [
                {
                  zone_id: 12,
                  zone_name: 'Zone A',
                  is_eligible: true,
                  score: 7,
                  hard_blocking_reasons: [],
                },
              ],
            },
          ],
        },
      },
    })

    renderPage()

    await waitFor(() => {
      expect(screen.getAllByText('North Plot').length).toBeGreaterThan(0)
    })

    fireEvent.click(screen.getByRole('button', { name: 'Generuoti rotacijos juodraštį' }))

    await waitFor(() => {
      expect(screen.getByLabelText('Sprendimas')).toBeInTheDocument()
    })

    fireEvent.change(screen.getByLabelText('Sprendimas'), { target: { value: 'zone:12' } })

    await waitFor(() => {
      expect(api.updateRotationDraftItem).toHaveBeenCalledWith('5', 90, 22, {
        decision: 'target',
        target_zone_id: 12,
        manual_note: null,
      })
    })

    expect(screen.getByText('Paruošta patvirtinti')).toBeInTheDocument()
    expect(screen.getByText(/Perkelti į/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Patvirtinti rotacijos planą' })).toBeEnabled()
  })

  it('shows permanent plantings as non-blocking annual rotation context', async () => {
    api.createRotationPlan.mockResolvedValueOnce({
      draft: {
        id: 91,
        plan: {
          status: 'ready',
          summary: {
            plant_count: 2,
            annual_plant_count: 0,
            permanent_plant_count: 2,
            assigned_plant_count: 0,
            unresolved_plant_count: 0,
            blocked_plant_count: 0,
          },
          plants: [
            {
              plant: { id: 31, name: "Apple Tree 'Auksis'" },
              current_zone: { id: 12, name: 'Jaunos obels zona' },
              selected_target_zone: null,
              is_rotatable: false,
              rotation_mode: 'permanent_planting',
              exclusion_reason: 'Daugiametis sodinimas – neįtraukiamas į metinę rotaciją.',
              alternatives: [],
              fallback_solutions: [],
              candidate_zones: [],
            },
            {
              plant: { id: 32, name: "Raspberry 'Glen Ample'" },
              current_zone: { id: 13, name: 'Aviečių zona' },
              selected_target_zone: null,
              is_rotatable: false,
              rotation_mode: 'permanent_planting',
              exclusion_reason: 'Daugiametis sodinimas – neįtraukiamas į metinę rotaciją.',
              alternatives: [],
              fallback_solutions: [],
              candidate_zones: [],
            },
          ],
        },
      },
    })
    api.confirmRotationPlan.mockResolvedValueOnce({})

    renderPage()

    await waitFor(() => {
      expect(screen.getAllByText('North Plot').length).toBeGreaterThan(0)
    })

    fireEvent.click(screen.getByRole('button', { name: 'Generuoti rotacijos juodraštį' }))

    await waitFor(() => {
      expect(screen.getByText('Paruošta patvirtinti')).toBeInTheDocument()
    })

    expect(screen.getByText('Šiam sklypui metinė rotacija nereikalinga. Daugiametis sodinimas rodomas kontekstui ir gali likti vietoje.')).toBeInTheDocument()
    expect(screen.getAllByText('Daugiametis sodinimas')).toHaveLength(2)
    expect(screen.getAllByText('Daugiametis sodinimas – neįtraukiamas į metinę rotaciją.').length).toBeGreaterThanOrEqual(2)
    expect(screen.getByRole('button', { name: 'Patvirtinti rotacijos planą' })).toBeEnabled()

    fireEvent.click(screen.getByRole('button', { name: 'Patvirtinti rotacijos planą' }))

    await waitFor(() => {
      expect(api.confirmRotationPlan).toHaveBeenCalledWith('5', 91)
    })
  })
})
