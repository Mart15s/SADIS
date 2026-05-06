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
    commitPlotWorkspace: vi.fn(),
    downloadPlotPdf: vi.fn(),
  },
}))

vi.mock('../../lib/plotWorkspaceDraft.js', () => ({
  createPlotWorkspaceSignature: vi.fn((workspace) => JSON.stringify(workspace)),
  loadPlotWorkspaceDraft: vi.fn(() => null),
  savePlotWorkspaceDraft: vi.fn(),
  clearPlotWorkspaceDraft: vi.fn(),
}))

vi.mock('../../components/plot/PlotDesignerCanvas.jsx', () => ({
  default: React.forwardRef(function MockPlotDesignerCanvas(props, ref) {
    React.useImperativeHandle(ref, () => ({
      createZoneFromForm: vi.fn(),
    }))

    return (
      <div data-testid="plot-designer-canvas">
        canvas
        <span data-testid="active-zone-id">{props.activeZoneId ?? 'none'}</span>
        <button type="button" onClick={() => props.onSelectZone(props.zones[0] ?? null)}>Select zone</button>
        <button type="button" onClick={() => props.onSelectZone(null)}>Clear zone</button>
        <button
          type="button"
          onClick={() => props.onBoundaryCommit(
            { kind: 'polygon', points: [{ x: 0, y: 0 }, { x: 8, y: 0 }, { x: 8, y: 8 }, { x: 0, y: 8 }] },
            {},
          )}
        >
          Commit boundary
        </button>
      </div>
    )
  }),
}))

vi.mock('../../components/plot/PlotLocationMap.jsx', () => ({
  default: ({ boundaryPoints, readOnly }) => (
    <div data-testid="plot-location-map" data-readonly={readOnly ? 'true' : 'false'}>
      {boundaryPoints.length} boundary points
    </div>
  ),
}))

vi.mock('../../components/plot/PlotPlantingDrawer.jsx', () => ({
  default: () => <div data-testid="plot-planting-drawer">planting drawer</div>,
}))

vi.mock('../../components/plot/PlotSectionNav.jsx', () => ({
  default: ({ plotName, sectionLabel = 'Editor', description, meta, actions }) => (
    <div data-testid="plot-section-nav">
      <span>Plots</span>
      <span>{sectionLabel}</span>
      <h1>{plotName}</h1>
      <p>{description}</p>
      <div>{meta}</div>
      <div>{actions}</div>
    </div>
  ),
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

describe('PlotDetailPage explicit save workspace', () => {
  function renderPage() {
    return render(
      <MemoryRouter initialEntries={['/plots/5']}>
        <Routes>
          <Route path="/plots/:plotId" element={<PlotDetailPage />} />
          <Route path="/plots/:plotId/edit" element={<div>metadata page</div>} />
        </Routes>
      </MemoryRouter>,
    )
  }

  beforeEach(() => {
    vi.clearAllMocks()
    vi.stubGlobal('confirm', vi.fn(() => true))

    api.listPlots.mockResolvedValue([{ id: 5, access_role: 'owner' }])
    api.getPlot.mockResolvedValue({
      id: 5,
      name: 'North Plot',
      city: 'Vilnius',
      plot_size: 42,
      geometry: null,
      share: true,
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
    api.commitPlotWorkspace.mockResolvedValue({
      plot: {
        id: 5,
        name: 'North Plot',
        city: 'Vilnius',
        plot_size: 42,
        geometry: null,
        share: true,
      },
      zones: [
        {
          id: 11,
          name: 'Zone A Prime',
          zone_size: 12,
          soil_type: 'clay',
          rotation_stage: 0,
          geometry: null,
        },
      ],
      plants: [
        {
          id: 22,
          name: 'Cucumber',
          type: 'vegetable',
          condition: 'growing',
          fk_plant_zone_id: 11,
        },
      ],
      history_entry: {
        label: 'Committed zone changes',
      },
    })
  })

  it('renders the focused editor layout with a single primary save action', async () => {
    const { container } = renderPage()

    await waitFor(() => {
      expect(screen.getByText('North Plot')).toBeInTheDocument()
    })

    expect(screen.getByTestId('plot-designer-canvas')).toBeInTheDocument()
    expect(screen.getByTestId('plot-section-nav')).toBeInTheDocument()
    expect(container.querySelector('.workspace-page--editor')).not.toBeNull()
    expect(screen.getByRole('button', { name: 'Save plot changes' })).toBeDisabled()
  })

  it('keeps edits in draft until the explicit save action commits them', async () => {
    renderPage()

    await waitFor(() => {
      expect(screen.getByDisplayValue('Zone A')).toBeInTheDocument()
    })

    fireEvent.change(screen.getByLabelText('Zone name'), {
      target: { value: 'Zone A Prime' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Apply zone details' }))

    const saveButton = screen.getByRole('button', { name: 'Save plot changes' })
    expect(saveButton).toBeEnabled()

    fireEvent.click(saveButton)

    await waitFor(() => {
      expect(api.commitPlotWorkspace).toHaveBeenCalledTimes(1)
    })

    expect(api.commitPlotWorkspace).toHaveBeenCalledWith('5', expect.objectContaining({
      zones: [
        expect.objectContaining({
          id: 11,
          name: 'Zone A Prime',
        }),
      ],
    }))
  })

  it('keeps the zone selection cleared when the canvas reports an empty-background click', async () => {
    renderPage()

    await waitFor(() => {
      expect(screen.getByTestId('active-zone-id')).toHaveTextContent('11')
    })

    fireEvent.click(screen.getByRole('button', { name: 'Clear zone' }))

    await waitFor(() => {
      expect(screen.getByTestId('active-zone-id')).toHaveTextContent('none')
    })
    expect(screen.getByText('None')).toBeInTheDocument()
    expect(screen.getByText('Select or draw a zone')).toBeInTheDocument()
  })

  it('blocks route navigation when the user cancels the unsaved-changes confirmation', async () => {
    globalThis.confirm.mockReturnValue(false)

    renderPage()

    await waitFor(() => {
      expect(screen.getByDisplayValue('Zone A')).toBeInTheDocument()
    })

    fireEvent.change(screen.getByLabelText('Zone name'), {
      target: { value: 'Zone A Prime' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Apply zone details' }))
    fireEvent.click(screen.getByRole('button', { name: 'Edit metadata' }))

    expect(globalThis.confirm).toHaveBeenCalledWith('You have unsaved plot changes. Leave without saving this draft?')
    expect(screen.queryByText('metadata page')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save plot changes' })).toBeEnabled()
  })

  it('allows route navigation after the user confirms the unsaved-changes warning', async () => {
    globalThis.confirm.mockReturnValue(true)

    renderPage()

    await waitFor(() => {
      expect(screen.getByDisplayValue('Zone A')).toBeInTheDocument()
    })

    fireEvent.change(screen.getByLabelText('Zone name'), {
      target: { value: 'Zone A Prime' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Apply zone details' }))
    fireEvent.click(screen.getByRole('button', { name: 'Edit metadata' }))

    await waitFor(() => {
      expect(screen.getByText('metadata page')).toBeInTheDocument()
    })
  })

  it('renders saved map boundary preview and preserves map geometry on canvas boundary commits', async () => {
    const mapGeometry = {
      points: [
        { x: 0, y: 0 },
        { x: 1, y: 0 },
        { x: 1, y: 1 },
        { x: 0, y: 1 },
      ],
      map: {
        provider: 'openstreetmap',
        center: { lat: 54.681, lng: 25.271 },
        zoom: 16,
        boundary: [
          { lat: 54.681, lng: 25.271 },
          { lat: 54.681, lng: 25.272 },
          { lat: 54.68, lng: 25.272 },
          { lat: 54.68, lng: 25.271 },
        ],
      },
    }

    api.getPlot.mockResolvedValueOnce({
      id: 5,
      name: 'North Plot',
      city: 'Vilnius',
      plot_size: 42,
      geometry: mapGeometry,
      share: true,
    })

    renderPage()

    await waitFor(() => {
      expect(screen.getByTestId('plot-location-map')).toHaveTextContent('4 boundary points')
    })

    expect(screen.getByTestId('plot-location-map')).toHaveAttribute('data-readonly', 'true')

    fireEvent.click(screen.getByRole('button', { name: 'Commit boundary' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save plot changes' }))

    await waitFor(() => {
      expect(api.commitPlotWorkspace).toHaveBeenCalledTimes(1)
    })

    expect(api.commitPlotWorkspace).toHaveBeenCalledWith('5', expect.objectContaining({
      plot: expect.objectContaining({
        geometry: expect.objectContaining({
          map: mapGeometry.map,
        }),
      }),
    }))
  })
})
