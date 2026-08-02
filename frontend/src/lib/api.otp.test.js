import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosGet, clientPost, client } = vi.hoisted(() => {
  const axiosGet = vi.fn()
  const clientPost = vi.fn()
  return {
    axiosGet,
    clientPost,
    client: {
      get: vi.fn(),
      post: clientPost,
      patch: vi.fn(),
      put: vi.fn(),
      delete: vi.fn(),
      interceptors: { request: { use: vi.fn() }, response: { use: vi.fn() } },
    },
  }
})

vi.mock('axios', () => ({
  default: { get: axiosGet, create: vi.fn(() => client) },
}))

import { api } from './api.js'

describe('guest mutation CSRF protection', () => {
  beforeEach(() => {
    axiosGet.mockReset().mockResolvedValue({})
    clientPost.mockReset().mockResolvedValue({ data: { data: { status: 'ok' } } })
  })

  it.each([
    ['forgotPassword', '/forgot-password', { email: 'grower@example.com' }],
    [
      'resetPassword',
      '/reset-password',
      {
        email: 'grower@example.com',
        reset_code: 'reset-code',
        password: 'new-password',
        password_confirmation: 'new-password',
      },
    ],
    ['logout', '/logout', undefined],
    ['requestOtp', '/v1/auth/otp/request', { phone: '+919876543210' }],
    ['verifyOtp', '/v1/auth/otp/verify', { phone: '+919876543210', code: '123456' }],
  ])('initializes a Sanctum CSRF cookie before %s', async (method, path, payload) => {
    await api[method](payload)
    expect(axiosGet).toHaveBeenCalledWith(
      '/sanctum/csrf-cookie',
      expect.objectContaining({ withCredentials: true }),
    )
    if (payload === undefined) expect(clientPost).toHaveBeenCalledWith(path)
    else expect(clientPost).toHaveBeenCalledWith(path, payload)
    expect(axiosGet.mock.invocationCallOrder[0]).toBeLessThan(
      clientPost.mock.invocationCallOrder[0],
    )
  })
})
