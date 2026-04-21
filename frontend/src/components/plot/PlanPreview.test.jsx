import React from 'react'
import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import PlanPreview from './PlanPreview.jsx'

describe('PlanPreview', () => {
  it('rebuilds zone geometry from the same canonical boundary reference used by the editor', () => {
    const { container } = render(
      <PlanPreview
        plotName="Shared plot"
        plotGeometry={{
          points: [
            { x: 0.04, y: 0.08 },
            { x: 0.92, y: 0.08 },
            { x: 0.92, y: 0.9 },
            { x: 0.1, y: 0.9 },
          ],
        }}
        zones={[
          {
            id: 1,
            name: 'Zone 2',
            geometry: {
              points: [
                { x: 0.1, y: 0.12 },
                { x: 0.67, y: 0.22 },
                { x: 0.67, y: 0.72 },
                { x: 0.2, y: 0.72 },
              ],
            },
          },
        ]}
      />,
    )

    const clipPath = container.querySelector('clipPath polygon')
    const zonePolygon = container.querySelector('.plan-preview-zone')

    expect(clipPath).not.toBeNull()
    expect(zonePolygon).not.toBeNull()
    expect(zonePolygon.getAttribute('points')).not.toBe('10.00,12.00 67.00,22.00 67.00,72.00 20.00,72.00')
    expect(screen.getByLabelText(/Shared plot visual preview/i)).toBeInTheDocument()
  })

  it('renders the shared preview shell and geometry source caption without crashing', () => {
    const { container } = render(
      <PlanPreview
        plotName="Compact labels"
        plotGeometry={{
          points: [
            { x: 0.06, y: 0.08 },
            { x: 0.94, y: 0.08 },
            { x: 0.94, y: 0.92 },
            { x: 0.06, y: 0.92 },
          ],
        }}
        zones={[
          {
            id: 1,
            name: 'Herbs',
            geometry: {
              points: [
                { x: 0.28, y: 0.5 },
                { x: 0.82, y: 0.5 },
                { x: 0.82, y: 0.86 },
                { x: 0.28, y: 0.86 },
              ],
            },
          },
        ]}
      />,
    )

    expect(container.querySelector('.plan-preview-zone')).not.toBeNull()
    expect(screen.getByText(/Synced plot geometry preview/i)).toBeInTheDocument()
  })
})
