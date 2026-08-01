import { describe, expect, it } from 'vitest'
import { formatArea, formatDate, formatNumber, formatQuantity } from './formatters.js'

describe('locale formatters', () => {
  it('uses square metres canonically and supports hectare or acre display', () => {
    expect(formatArea(15000, 'hectare', 'en-IN')).toBe('1.5 ha')
    expect(formatArea(4046.8564224, 'acre', 'en-IN')).toBe('1 ac')
    expect(formatArea(900, 'square_meter', 'en-IN')).toBe('900 m²')
  })

  it('handles invalid values without leaking Invalid Date or NaN into the UI', () => {
    expect(formatDate('not-a-date')).toBe('—')
    expect(formatNumber(undefined)).toBe('—')
    expect(formatQuantity(12.5, 'kilogram')).toBe('12.5 kg')
  })
})
