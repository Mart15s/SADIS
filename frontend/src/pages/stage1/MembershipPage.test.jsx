import { render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MembershipPage from './MembershipPage.jsx'
import { api } from '../../lib/api.js'
import { I18nProvider } from '../../i18n/I18nContext.jsx'

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
    <I18nProvider>
      <MemoryRouter initialEntries={['/communities/9/members']}>
        <Routes>
          <Route
            path="/communities/:communityId/members"
            element={<MembershipPage scope="community" />}
          />
        </Routes>
      </MemoryRouter>
    </I18nProvider>,
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
    expect(screen.queryByRole('heading', { name: 'Invitation history' })).not.toBeInTheDocument()
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

  it('renders privacy-safe invitation history for community managers', async () => {
    workspaceState.contexts = [
      { type: 'community', id: '9', role: 'admin', permissions: ['view', 'manage_members'] },
    ]
    api.listV1Path.mockImplementation((path) => {
      if (path.endsWith('/members')) return Promise.resolve([])
      if (path.endsWith('/join-requests')) return Promise.resolve([])
      return Promise.resolve([
        {
          id: 31,
          email: 'pending@example.test',
          role: 'coordinator',
          status: 'pending',
          expires_at: '2099-06-15T12:00:00Z',
          code_hash: 'must-not-render',
        },
        {
          id: 32,
          phone: '+37060000000',
          role: 'member',
          status: 'accepted',
          expires_at: '2099-06-18T12:00:00Z',
        },
        {
          id: 33,
          email: 'expired@example.test',
          role: 'member',
          status: 'pending',
          expires_at: '2020-01-01T00:00:00Z',
        },
      ])
    })

    renderCommunityMembers()

    const history = (await screen.findByRole('heading', { name: 'Invitation history' })).closest(
      'section',
    )
    const invitationHistory = within(history)
    const pendingRecipient = invitationHistory.getByText('pending@example.test')
    expect(pendingRecipient).toBeInTheDocument()
    expect(pendingRecipient.nextElementSibling).toHaveTextContent(/Expires.*2099/)
    expect(invitationHistory.getByText('+37060000000')).toBeInTheDocument()
    expect(invitationHistory.getByText('expired@example.test')).toBeInTheDocument()
    expect(invitationHistory.getByText('accepted')).toBeInTheDocument()
    expect(invitationHistory.getByText('expired')).toBeInTheDocument()
    expect(screen.queryByText('must-not-render')).not.toBeInTheDocument()
  })
})
