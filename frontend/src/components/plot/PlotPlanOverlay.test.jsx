import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import PlotPlanOverlay from './PlotPlanOverlay.jsx'
import { createPlotPlanFixture } from '../../test/plotPlanFixture.js'

describe('PlotPlanOverlay', () => {
  const zone = { id: 1, name: 'Šiltnamis' }
  const layouts = {
    1: { kind: 'polygon', points: [{ x: 0, y: 0 }, { x: 8, y: 0 }, { x: 8, y: 5 }, { x: 0, y: 5 }] },
  }
  const plants = ['Pomidoras', 'Bazilikas', 'Paprika', 'Salota'].map((name, index) => ({
    id: index + 1,
    name,
    condition: index === 2 ? 'diseased' : 'growing',
    fk_plant_zone_id: 1,
    quantity: index + 2,
    plant_date: '2026-04-10',
  }))

  it('renders three markers plus overflow and opens full planting details', () => {
    render(
      <MemoryRouter>
        <PlotPlanOverlay
          zones={[zone]}
          plants={plants}
          layouts={layouts}
          viewport={{ x: 20, y: 20, scale: 50 }}
          plotId="5"
          canEdit
          onSelectZone={() => {}}
        />
      </MemoryRouter>,
    )

    expect(screen.getByRole('button', { name: /Pomidoras/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Show all crops in Šiltnamis' })).toHaveTextContent('+1')

    fireEvent.click(screen.getByRole('button', { name: 'Show all crops in Šiltnamis' }))
    expect(screen.getByRole('dialog', { name: 'All crops in zone' })).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: 'Salota' })).toHaveLength(1)

    fireEvent.click(screen.getByRole('button', { name: 'Pomidoras. Growing normally' }))
    expect(screen.getByRole('dialog', { name: 'Plant information' })).toBeInTheDocument()
    expect(screen.getByText('No recommended work')).toBeInTheDocument()
  })

  it('handles the development fixture with 100 zones and 300 plantings', () => {
    const fixture = createPlotPlanFixture()
    const { container } = render(
      <MemoryRouter>
        <PlotPlanOverlay {...fixture} viewport={{ x: 0, y: 0, scale: 50 }} plotId="5" canEdit={false} onSelectZone={() => {}} />
      </MemoryRouter>,
    )

    expect(fixture.zones).toHaveLength(100)
    expect(fixture.plants).toHaveLength(300)
    expect(container.querySelectorAll('.plot-plant-marker')).toHaveLength(300)
  })
})
