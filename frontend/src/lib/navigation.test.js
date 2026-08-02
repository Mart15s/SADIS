import { describe, expect, it } from 'vitest'
import { safeRedirectPath } from './navigation.js'

describe('safeRedirectPath', () => {
  it('keeps local application paths', () => {
    expect(safeRedirectPath('/fields/9/editor?tab=zones')).toBe('/fields/9/editor?tab=zones')
  })

  it.each(['https://example.com', '//example.com', '/\\example.com', 'javascript:alert(1)'])(
    'rejects unsafe redirect %s',
    (value) => {
      expect(safeRedirectPath(value)).toBe('/')
    },
  )
})
