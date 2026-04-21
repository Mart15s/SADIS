import { describe, expect, it } from 'vitest'
import { getZoneLabelConfig } from './plotCanvasLabels.js'

describe('getZoneLabelConfig', () => {
  it('returns a centered readable config for reasonably sized zones across zoom levels', () => {
    const config = getZoneLabelConfig('Tomato Bed', {
      width: 14,
      height: 8,
      centerX: 7,
      centerY: 4,
    }, 18)

    expect(config).not.toBeNull()
    expect(config.text).toBe('Tomato Bed')
    expect(config.fontSize).toBeGreaterThan(0)
    expect(config.width).toBeGreaterThan(0)
    expect(config.x).toBeLessThan(7)
    expect(config.y).toBeLessThan(4)
  })

  it('hides labels for very small zones and truncates long names when needed', () => {
    expect(getZoneLabelConfig('Tiny', {
      width: 2,
      height: 1,
      centerX: 1,
      centerY: 0.5,
    }, 10)).toBeNull()

    const longNameConfig = getZoneLabelConfig('Very long greenhouse tomato and basil rotation zone', {
      width: 10,
      height: 5,
      centerX: 5,
      centerY: 2.5,
    }, 20)

    expect(longNameConfig).not.toBeNull()
    expect(longNameConfig.text.endsWith('...')).toBe(true)
  })

  it('falls back to compact labels for medium zones that cannot fit the inline label shell', () => {
    const config = getZoneLabelConfig('Herbs', {
      width: 4.5,
      height: 2.8,
      centerX: 2.25,
      centerY: 1.4,
    }, 18)

    expect(config).not.toBeNull()
    expect(config.variant).toBe('compact')
    expect(config.height).toBeGreaterThan(0)
  })
})
