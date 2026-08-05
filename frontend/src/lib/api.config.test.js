import { afterEach, describe, expect, it, vi } from 'vitest'

describe('API deployment configuration', () => {
  afterEach(() => {
    vi.unstubAllEnvs()
    vi.resetModules()
  })

  it('uses the local same-origin proxy when no production URL is configured', async () => {
    vi.stubEnv('VITE_API_BASE_URL', '')
    vi.resetModules()

    const { apiClient } = await import('./api.js')

    expect(apiClient.defaults.baseURL).toBe('/api')
    expect(apiClient.defaults.withCredentials).toBe(true)
  })

  it('uses bearer-token requests without cookies for a cross-origin backend', async () => {
    vi.stubEnv('VITE_API_BASE_URL', 'https://sad-system-web.onrender.com/api/')
    vi.resetModules()

    const { apiClient } = await import('./api.js')

    expect(apiClient.defaults.baseURL).toBe('https://sad-system-web.onrender.com/api')
    expect(apiClient.defaults.withCredentials).toBe(false)
  })
})
