import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MembershipPage from './MembershipPage.jsx'
import { api } from '../../lib/api.js'

const workspaceState = {
  contexts: [
    {
      type: 'community',
      id: '9',
      role: 'member',
      permissions: ['view'],
    },
  ],
}

vi.mock('../../context/useWorkspace.js', () => ({
  useWorkspace: () => workspaceState,
}))

vi.mock('../../lib/api.js', () => ({
  api: {
    listV1Path: vi.fn(),
    createCommunityInvitation: vi.fn(),
    postV1Path: vi.fn(),
    patchV1Path: vi.fn(),
  },
}))

function renderCommunityMembers() {
  return render(
    <MemoryRouter initialEntries={['/communities/9/members']}>
      <Routes>
        <Route
          path="/communities/:communityId/members"
          element={<MembershipPage scope="community" />}
        />
      </Routes>
    </MemoryRouter>,
  )
}

describe('membership privacy controls', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    workspaceState.contexts = [
      { type: 'community', id: '9', role: 'member', permissions: ['view'] },
    ]
    api.listV1Path.mockResolvedValue([
      { id: 2, user_id: 15, role: 'member', status: 'active', user: { name: 'Maya' } },
    ])
  })

  it('keeps the safe roster visible without requesting or rendering privileged management', async () => {
    renderCommunityMembers()

    expect(await screen.findByText('Maya')).toBeInTheDocument()
    expect(api.listV1Path).toHaveBeenCalledTimes(1)
    expect(api.listV1Path).toHaveBeenCalledWith('communities/9/members')
    expect(screen.queryByRole('heading', { name: 'Invite a member' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Revoke' })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Join requests' })).not.toBeInTheDocument()
  })

  it('preserves the roster when a privileged side request is rejected', async () => {
    workspaceState.contexts = [
      { type: 'community', id: '9', role: 'admin', permissions: ['view', 'manage_members'] },
    ]
    api.listV1Path.mockImplementation((path) => {
      if (path.endsWith('/members')) {
        return Promise.resolve([
          { id: 2, user_id: 15, role: 'member', status: 'active', user: { name: 'Maya' } },
        ])
      }
      if (path.endsWith('/invitations')) return Promise.reject(new Error('Not authorized'))
      return Promise.resolve([])
    })

    renderCommunityMembers()

    expect(await screen.findByText('Maya')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('Not authorized')).toBeInTheDocument())
  })
})
