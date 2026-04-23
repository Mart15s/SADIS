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
    expect(zonePolygon?.getAttribute('points')).not.toBe('10.00,12.00 67.00,22.00 67.00,72.00 20.00,72.00')
    expect(screen.getByLabelText(/Shared plot visual preview/i)).toBeInTheDocument()
  })

  it('renders labels consistently for multiple visible zones and keeps a numbered legend fallback', () => {
    const { container } = render(
      <PlanPreview
        plotName="Community plot"
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
            name: 'Zone 1',
            geometry: {
              points: [
                { x: 0.08, y: 0.12 },
                { x: 0.48, y: 0.14 },
                { x: 0.45, y: 0.54 },
                { x: 0.1, y: 0.52 },
              ],
            },
          },
          {
            id: 2,
            name: 'Zone 2',
            geometry: {
              points: [
                { x: 0.52, y: 0.16 },
                { x: 0.86, y: 0.16 },
                { x: 0.86, y: 0.54 },
                { x: 0.52, y: 0.54 },
              ],
            },
          },
          {
            id: 3,
            name: 'Zone 3',
            geometry: {
              points: [
                { x: 0.5, y: 0.66 },
                { x: 0.78, y: 0.66 },
                { x: 0.82, y: 0.88 },
                { x: 0.46, y: 0.88 },
              ],
            },
          },
        ]}
      />,
    )

    const labels = container.querySelectorAll('.plan-preview-label')
    const legendItems = container.querySelectorAll('.plan-preview-legend-item')
    const legendIndexes = container.querySelectorAll('.plan-preview-legend-index')

    expect(labels.length).toBeGreaterThanOrEqual(2)
    expect(legendItems).toHaveLength(3)
    expect(legendIndexes).toHaveLength(3)
    expect(screen.getAllByText('Zone 1').length).toBeGreaterThanOrEqual(1)
    expect(screen.getAllByText('Zone 2').length).toBeGreaterThanOrEqual(1)
    expect(screen.getAllByText('Zone 3').length).toBeGreaterThanOrEqual(1)
  })

  it('keeps skewed zone label shells bounded instead of drawing a giant distorted oval', () => {
    const { container } = render(
      <PlanPreview
        plotName="Skewed labels"
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
            name: 'Zone 2',
            geometry: {
              points: [
                { x: 0.08, y: 0.1 },
                { x: 0.78, y: 0.2 },
                { x: 0.58, y: 0.62 },
                { x: 0.18, y: 0.62 },
              ],
            },
          },
        ]}
      />,
    )

    const shell = container.querySelector('.plan-preview-label-shell')
    const shellWidth = Number(shell?.getAttribute('width'))
    const shellHeight = Number(shell?.getAttribute('height'))

    expect(shell).not.toBeNull()
    expect(shellWidth).toBeLessThan(50)
    expect(shellHeight).toBeLessThan(28)
    expect(shellWidth / shellHeight).toBeLessThan(2.5)
  })
})
