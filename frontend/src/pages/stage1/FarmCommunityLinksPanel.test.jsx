import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FarmCommunityLinksPanel from './FarmCommunityLinksPanel.jsx'
import { api } from '../../lib/api.js'

const workspaceState = {
  active: { type: 'farm', id: '7', permissions: ['manage_members'] },
}

vi.mock('../../context/useWorkspace.js', () => ({
  useWorkspace: () => workspaceState,
}))

vi.mock('../../lib/api.js', () => ({
  api: {
    listV1Path: vi.fn(),
    listV1: vi.fn(),
    postV1Path: vi.fn(),
    deleteV1Path: vi.fn(),
  },
}))

describe('farm–community link management', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    workspaceState.active = { type: 'farm', id: '7', permissions: ['manage_members'] }
    api.listV1Path.mockResolvedValue([])
    api.listV1.mockResolvedValue([{ id: 12, name: 'Mysuru Growers' }])
    api.postV1Path.mockResolvedValue({ id: 31, status: 'pending' })
  })

  it('requests a scoped community link from the active farm', async () => {
    render(<FarmCommunityLinksPanel scope="farm" />)

    fireEvent.change(await screen.findByLabelText('Community'), { target: { value: '12' } })
    fireEvent.click(screen.getByLabelText('crop summary'))
    fireEvent.click(screen.getByLabelText('manage fields'))
    fireEvent.click(screen.getByRole('button', { name: 'Request community link' }))

    await waitFor(() =>
      expect(api.postV1Path).toHaveBeenCalledWith('farms/7/communities/12', {
        analytics_scopes: ['crop_summary'],
        farm_access_permissions: ['view_farm', 'manage_fields'],
      }),
    )
  })

  it('does not expose link management without resolved permission', () => {
    workspaceState.active = { type: 'farm', id: '7', permissions: ['view_farm'] }
    const { container } = render(<FarmCommunityLinksPanel scope="farm" />)
    expect(container).toBeEmptyDOMElement()
    expect(api.listV1Path).not.toHaveBeenCalled()
  })
})
