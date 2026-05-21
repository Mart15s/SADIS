import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import PlotBoundaryMiniMap, { normalizeMapBoundary } from './PlotBoundaryMiniMap.jsx'

vi.mock('react-leaflet', () => ({
  MapContainer: ({ children, className }) => <div className={className} data-testid="mini-map">{children}</div>,
  TileLayer: () => <div data-testid="tile-layer" />,
  Polygon: ({ positions }) => <div data-testid="boundary-polygon">{positions.length} points</div>,
  useMap: () => ({
    fitBounds: () => {},
    invalidateSize: () => {},
  }),
}))

describe('PlotBoundaryMiniMap', () => {
  it('normalizes saved map boundary points and renders the boundary polygon', () => {
    render(
      <PlotBoundaryMiniMap
        plotName="North Plot"
        plotGeometry={{
          map: {
            center: { lat: 54.68, lng: 25.27 },
            boundary: [
              { lat: 54.681, lng: 25.271 },
              { lat: 54.682, lng: 25.276 },
              { lat: 54.678, lng: 25.277 },
              { lat: 54.677, lng: 25.272 },
            ],
          },
        }}
      />,
    )

    expect(screen.getByTestId('tile-layer')).toBeInTheDocument()
    expect(screen.getByTestId('boundary-polygon')).toHaveTextContent('4 points')
    expect(screen.queryByText('Riba nenurodyta')).not.toBeInTheDocument()
    expect(screen.queryByText('Map boundary preview')).not.toBeInTheDocument()
  })

  it('keeps a clean fallback when no usable boundary exists', () => {
    render(<PlotBoundaryMiniMap plotName="Empty Plot" plotGeometry={{ points: [] }} />)

    expect(screen.getByLabelText('Empty Plot ribų peržiūra nepasiekiama')).toBeInTheDocument()
    expect(screen.getByText('Riba nenurodyta')).toBeInTheDocument()
  })

  it('drops invalid coordinates while preserving valid boundary data', () => {
    expect(normalizeMapBoundary({
      map: {
        boundary: [
          { lat: 54.681, lng: 25.271 },
          { lat: 200, lng: 25.276 },
          { lat: 54.678, lng: 25.277 },
        ],
      },
    })).toHaveLength(2)
  })
})
