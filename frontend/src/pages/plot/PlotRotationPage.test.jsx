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

    fireEvent.click(screen.getByRole('button', { name: 'Generate rotation draft' }))

    await waitFor(() => {
      expect(api.createRotationPlan).toHaveBeenCalledWith('5', expect.objectContaining({
        planning_date: expect.any(String),
      }))
    })

    expect(screen.getByText('Needs adjustment')).toBeInTheDocument()
    expect(screen.getByText('Pepper')).toBeInTheDocument()
    expect(screen.getByText('Zone A')).toBeInTheDocument()
    expect(screen.getByText('A plant from the same family was planted here in 2025.')).toBeInTheDocument()
    expect(screen.getByText('This draft cannot be confirmed because 1 annual plants still need a suitable target zone.')).toBeInTheDocument()
    expect(screen.getByText('Needs a suitable target zone')).toBeInTheDocument()
    expect(screen.getByLabelText('Decision')).toBeInTheDocument()
    expect(screen.queryByText('Needs manual review')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Confirm rotation plan' })).toBeDisabled()
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

    fireEvent.click(screen.getByRole('button', { name: 'Generate rotation draft' }))

    await waitFor(() => {
      expect(screen.getByLabelText('Decision')).toBeInTheDocument()
    })

    fireEvent.change(screen.getByLabelText('Decision'), { target: { value: 'zone:12' } })

    await waitFor(() => {
      expect(api.updateRotationDraftItem).toHaveBeenCalledWith('5', 90, 22, {
        decision: 'target',
        target_zone_id: 12,
        manual_note: null,
      })
    })

    expect(screen.getByText('Ready to confirm')).toBeInTheDocument()
    expect(screen.getByText(/Move to/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Confirm rotation plan' })).toBeEnabled()
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
              exclusion_reason: 'Permanent planting — excluded from annual crop rotation.',
              alternatives: [],
              fallback_solutions: [],
              candidate_zones: [],
            },
            {
              plant: { id: 32, name: "Raspberry 'Glen Ample'" },
              current_zone: { id: 13, name: 'Raspberry zone' },
              selected_target_zone: null,
              is_rotatable: false,
              rotation_mode: 'permanent_planting',
              exclusion_reason: 'Permanent planting — excluded from annual crop rotation.',
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

    fireEvent.click(screen.getByRole('button', { name: 'Generate rotation draft' }))

    await waitFor(() => {
      expect(screen.getByText('Ready to confirm')).toBeInTheDocument()
    })

    expect(screen.getByText('This plot does not need annual rotation. Permanent plantings are shown for context and can remain in place.')).toBeInTheDocument()
    expect(screen.getAllByText('Permanent planting')).toHaveLength(2)
    expect(screen.getAllByText('Permanent planting is excluded from annual crop rotation.').length).toBeGreaterThanOrEqual(2)
    expect(screen.getByRole('button', { name: 'Confirm rotation plan' })).toBeEnabled()

    fireEvent.click(screen.getByRole('button', { name: 'Confirm rotation plan' }))

    await waitFor(() => {
      expect(api.confirmRotationPlan).toHaveBeenCalledWith('5', 91)
    })
  })
})
