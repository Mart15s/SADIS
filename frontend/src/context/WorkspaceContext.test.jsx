import { render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { WorkspaceProvider } from './WorkspaceContext.jsx'
import { useWorkspace } from './useWorkspace.js'
import { api } from '../lib/api.js'

vi.mock('./auth-context.js', () => ({
  useAuth: () => ({ isAuthenticated: true }),
}))

vi.mock('../lib/api.js', () => ({
  api: { listContexts: vi.fn() },
}))

function WorkspaceProbe() {
  const { active } = useWorkspace()
  return <span data-testid="workspace">{active?.permissions?.join(',') || 'none'}</span>
}

describe('WorkspaceProvider context capabilities', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.clearAllMocks()
  })

  it('keeps the selected context but refreshes its resolved permissions', async () => {
    localStorage.setItem(
      'yava-active-context',
      JSON.stringify({ id: '7', type: 'farm', name: 'Old farm', permissions: [] }),
    )
    api.listContexts.mockResolvedValue([
      {
        id: 7,
        type: 'farm',
        name: 'Sunrise Farm',
        role: 'viewer',
        permissions: ['view_farm', 'manage_members'],
      },
    ])

    render(
      <WorkspaceProvider>
        <WorkspaceProbe />
      </WorkspaceProvider>,
    )

    await waitFor(() =>
      expect(screen.getByTestId('workspace')).toHaveTextContent('view_farm,manage_members'),
    )
  })
})
