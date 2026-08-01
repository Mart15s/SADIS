import { describe, expect, it } from 'vitest'
import { markerPosition, plantVisual } from './plantVisual.js'

describe('plant visual registry', () => {
  it('resolves distinct category and name visuals before a generic fallback', () => {
    expect(plantVisual({ name: 'Blueberry' }).key).toBe('berry')
    expect(plantVisual({ name: 'Apple Mint' }).key).toBe('herb')
    expect(plantVisual({ name: 'Pomidoras' }).key).toBe('fruit')
    expect(plantVisual({ name: 'Nežinomas augalas' }).key).toBe('generic')
  })

  it('gives an explicit icon priority and only accepts complete normalized marker positions', () => {
    expect(plantVisual({ name: 'Blueberry', icon: 'leaf-custom' }).key).toBe('explicit')
    expect(markerPosition({ marker_position_x: 0.25, marker_position_y: 0.75 })).toEqual({ x: 0.25, y: 0.75 })
    expect(markerPosition({ marker_position_x: 2, marker_position_y: 0.5 })).toBeNull()
    expect(markerPosition({ marker_position_x: 0.2 })).toBeNull()
  })
})
