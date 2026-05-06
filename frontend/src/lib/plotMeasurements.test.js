import { describe, expect, it } from 'vitest'
import { createRectShape } from './plotDesigner.js'
import { buildShapeMetrics, createDimensionLabels } from './plotMeasurements.js'

describe('plotMeasurements', () => {
  it('calculates real side lengths and perimeter from the meter-scaled shape', () => {
    const metrics = buildShapeMetrics(createRectShape({
      x: 0,
      y: 0,
      width: 12,
      height: 8,
    }))

    expect(metrics.perimeter).toBe(40)
    expect(metrics.edges.map((edge) => edge.length)).toEqual([12, 8, 12, 8])
    expect(metrics.sideSummary).toContain('K1: 12')
    expect(metrics.sideSummary).toContain('K2: 8')
  })

  it('renders a label for every side even when edges are short at the current zoom', () => {
    const labels = createDimensionLabels({
      shape: createRectShape({
        x: 0,
        y: 0,
        width: 1,
        height: 1,
      }),
      viewport: { x: 20, y: 20, scale: 10 },
      viewportBounds: { width: 300, height: 240 },
      idPrefix: 'tiny',
    })

    expect(labels).toHaveLength(4)
    expect(labels.map((label) => label.id)).toEqual(['tiny-0', 'tiny-1', 'tiny-2', 'tiny-3'])
  })

  it('places visible labels at edge midpoints and rotates them along the edge', () => {
    const labels = createDimensionLabels({
      shape: createRectShape({
        x: 0,
        y: 0,
        width: 10,
        height: 5,
      }),
      viewport: { x: 20, y: 20, scale: 20 },
      viewportBounds: { width: 320, height: 220 },
      idPrefix: 'rect',
    })

    expect(labels).toHaveLength(4)
    expect(labels[0]).toMatchObject({
      text: expect.stringContaining('m'),
      x: 120,
      y: 20,
      angle: 0,
    })
  })
})
