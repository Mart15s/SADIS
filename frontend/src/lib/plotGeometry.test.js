import { describe, expect, it } from 'vitest'
import {
  canvasToPlotNormalizedPoint,
  plotNormalizedToCanvasPoint,
  sanitizeNormalizedGeometry,
} from './plotGeometry.js'

describe('plotGeometry', () => {
  it('clamps finite geometry points before API save', () => {
    const result = sanitizeNormalizedGeometry({
      points: [
        { x: -0.2, y: 0.1 },
        { x: 1.4, y: 0.1 },
        { x: 1.2, y: 1.3 },
        { x: 0, y: 1.1 },
      ],
    })

    expect(result.error).toBeNull()
    expect(result.geometry.points[0]).toEqual({ x: 0, y: 0.1 })
    expect(result.geometry.points[2]).toEqual({ x: 1, y: 1 })
  })

  it('accepts triangular plot geometry for map-created boundaries', () => {
    const result = sanitizeNormalizedGeometry({
      points: [
        { x: 0.1, y: 0.1 },
        { x: 0.9, y: 0.2 },
        { x: 0.4, y: 0.8 },
      ],
    })

    expect(result.error).toBeNull()
    expect(result.geometry.points).toHaveLength(3)
  })

  it('rejects non-finite coordinates instead of sending them', () => {
    const result = sanitizeNormalizedGeometry({
      points: [
        { x: 0, y: 0 },
        { x: Number.POSITIVE_INFINITY, y: 0 },
        { x: 1, y: 1 },
        { x: 0, y: 1 },
      ],
    })

    expect(result.error).toContain('non-numeric')
  })

  it('rejects polygons that collapse after clamping', () => {
    const result = sanitizeNormalizedGeometry({
      points: [
        { x: -1, y: -1 },
        { x: -2, y: -2 },
        { x: -3, y: -3 },
        { x: -4, y: -4 },
      ],
    })

    expect(result.error).toContain('invalid after clamping')
  })

  it('converts between screen and plot-local normalized coordinates', () => {
    const reference = { originX: 10, originY: 20, size: 100 }
    const viewport = { x: 5, y: 10, scale: 2 }
    const normalized = canvasToPlotNormalizedPoint({ x: 65, y: 70 }, viewport, reference)
    const screen = plotNormalizedToCanvasPoint(normalized, viewport, reference)

    expect(normalized).toEqual({ x: 0.2, y: 0.1 })
    expect(screen).toEqual({ x: 65, y: 70 })
  })
})
