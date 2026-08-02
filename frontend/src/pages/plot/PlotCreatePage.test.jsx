import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, test, vi } from 'vitest'
import PlotCreatePage from './PlotCreatePage.jsx'

vi.mock('../../components/plot/PlotLocationMap.jsx', () => ({
  default: function MockPlotLocationMap({
    mode,
    boundaryPoints,
    onBoundaryPointAdd,
  }) {
    return (
      <div data-testid="plot-location-map" data-mode={mode}>
        <button type="button" onClick={() => onBoundaryPointAdd({ lat: 54.681 + boundaryPoints.length, lng: 25.271 })}>
          Add boundary point
        </button>
      </div>
    )
  },
}))

vi.mock('../../components/plot/PlotDesignerCanvas.jsx', () => ({
  default: function MockPlotDesignerCanvas() {
    return <div data-testid="plot-designer-canvas" />
  },
}))

describe('PlotCreatePage Leaflet creation flow', () => {
  beforeEach(() => {
    window.localStorage.clear()
  })

  test('starts directly in boundary drawing mode and enables zones after closing a valid boundary', () => {
    render(
      <MemoryRouter>
        <PlotCreatePage />
      </MemoryRouter>,
    )

    expect(screen.getByTestId('plot-location-map')).toHaveAttribute('data-mode', 'boundary')

    fireEvent.click(screen.getByRole('button', { name: 'Add boundary point' }))
    fireEvent.click(screen.getByRole('button', { name: 'Add boundary point' }))
    fireEvent.click(screen.getByRole('button', { name: 'Add boundary point' }))

    expect(screen.getAllByText(/3\s+points/).length).toBeGreaterThan(0)

    fireEvent.click(screen.getByRole('button', { name: 'Close boundary' }))

    expect(screen.getByRole('button', { name: 'Zones' })).not.toBeDisabled()
  })
})
