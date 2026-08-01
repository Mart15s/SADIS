import { describe, expect, it } from 'vitest'
import {
  getContrastColor,
  getPlantStatusSemantic,
  getZoomTier,
  normalizeZoneColor,
  suggestZoneColor,
  ZONE_PALETTE,
} from './plotPlan.js'

describe('plot plan presentation utilities', () => {
  it('validates and normalizes opaque six digit colours', () => {
    expect(normalizeZoneColor('#4caf50')).toBe('#4CAF50')
    expect(normalizeZoneColor('#FFF')).toBeNull()
    expect(normalizeZoneColor('green')).toBeNull()
  })

  it('suggests a deterministic visually distinct palette colour', () => {
    const first = suggestZoneColor([])
    const second = suggestZoneColor([first])
    expect(first).toBe(ZONE_PALETTE[0])
    expect(second).not.toBe(first)
    expect(suggestZoneColor([first])).toBe(second)
  })

  it('maps status and zoom to centralized semantic tiers', () => {
    expect(getPlantStatusSemantic({ condition: 'growing' }).key).toBe('healthy')
    expect(getPlantStatusSemantic({ condition: 'diseased' }).key).toBe('critical')
    expect(getPlantStatusSemantic({ condition: 'dried' }).key).toBe('inactive')
    expect(getZoomTier(10)).toBe('distant')
    expect(getZoomTier(30)).toBe('medium')
    expect(getZoomTier(60)).toBe('close')
  })

  it('selects readable light or dark foregrounds', () => {
    expect(getContrastColor('#FFFFFF')).toBe('#1D2A1F')
    expect(getContrastColor('#17311E')).toBe('#FFFFFF')
  })
})
