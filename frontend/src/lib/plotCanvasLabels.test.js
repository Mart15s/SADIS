import { describe, expect, it } from 'vitest'
import { getBoundaryLabelLayout, getZoneLabelLayout, getZoneLabelMetrics } from './plotCanvasLabels.js'

function rectPoints(width, height, x = 0, y = 0) {
  return [
    { x, y },
    { x: x + width, y },
    { x: x + width, y: y + height },
    { x, y: y + height },
  ]
}

describe('getZoneLabelLayout', () => {
  it('keeps large zone labels readable and screen-stable across zoom levels', () => {
    const lowZoom = getZoneLabelLayout({
      zoneName: 'Tomato Bed',
      screenPoints: rectPoints(140, 88),
      context: 'editor',
    })
    const highZoom = getZoneLabelLayout({
      zoneName: 'Tomato Bed',
      screenPoints: rectPoints(320, 200),
      context: 'editor',
    })

    expect(lowZoom?.mode).toBe('full')
    expect(highZoom?.mode).toBe('full')
    expect(lowZoom?.fontSize).toBe(highZoom?.fontSize)
    expect(lowZoom?.text).toBe('Tomato Bed')
  })

  it('downgrades to a compact label for medium screen-space zones', () => {
    const config = getZoneLabelLayout({
      zoneName: 'Greenhouse Strip',
      screenPoints: rectPoints(92, 34),
      context: 'editor',
    })

    expect(config).not.toBeNull()
    expect(config?.mode).toBe('compact')
    expect(config?.text.length).toBeLessThanOrEqual('Greenhouse Strip'.length)
  })

  it('uses a deterministic marker fallback for very small zones', () => {
    const editorConfig = getZoneLabelLayout({
      zoneName: 'Tiny Herbs',
      screenPoints: rectPoints(30, 18),
      context: 'editor',
      markerText: 3,
    })
    const previewConfig = getZoneLabelLayout({
      zoneName: 'Tiny Herbs',
      screenPoints: rectPoints(30, 18),
      context: 'preview',
      markerText: 3,
    })

    expect(editorConfig?.mode).toBe('marker')
    expect(previewConfig?.mode).toBe('marker')
    expect(previewConfig?.text).toBe('3')
  })

  it('caps label shells to the safe interior span instead of stretching to the full bbox', () => {
    const screenPoints = [
      { x: 0, y: 0 },
      { x: 170, y: 22 },
      { x: 112, y: 96 },
      { x: 36, y: 96 },
    ]
    const metrics = getZoneLabelMetrics(screenPoints)
    const config = getZoneLabelLayout({
      zoneName: 'Zone 2',
      screenPoints,
      context: 'preview',
    })

    expect(config).not.toBeNull()
    expect(config?.width).toBeLessThan(metrics.bounds.width)
    expect(config?.width).toBeLessThanOrEqual(metrics.horizontalSpan.size)
    expect(config?.height).toBeLessThanOrEqual(metrics.verticalSpan.size)
  })

  it('keeps projected labels inside the visible viewport when a zone hugs the canvas edge', () => {
    const config = getZoneLabelLayout({
      zoneName: 'Edge Zone',
      screenPoints: rectPoints(92, 40, 2, 6),
      context: 'editor',
      viewportBounds: { width: 112, height: 76 },
    })

    expect(config).not.toBeNull()
    expect(config?.x).toBeGreaterThanOrEqual(10)
    expect((config?.x ?? 0) + (config?.width ?? 0)).toBeLessThanOrEqual(102)
    expect(config?.y).toBeGreaterThanOrEqual(10)
  })
})

describe('getBoundaryLabelLayout', () => {
  it('anchors plot boundary labels near the top of the visible boundary and keeps them readable', () => {
    const config = getBoundaryLabelLayout({
      plotName: 'North Plot',
      areaText: '42.0 m2',
      screenPoints: rectPoints(240, 150, 12, 12),
      viewportBounds: { width: 280, height: 200 },
    })

    expect(config).not.toBeNull()
    expect(config?.mode).toBe('full')
    expect(config?.text).toBe('North Plot | 42.0 m2')
    expect(config?.y).toBeLessThan(70)
    expect((config?.x ?? 0) + (config?.width ?? 0)).toBeLessThanOrEqual(268)
  })
})
