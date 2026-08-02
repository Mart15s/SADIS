import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FarmCommunityLinksPanel from './FarmCommunityLinksPanel.jsx'
import { api } from '../../lib/api.js'

const farmContext = { type: 'farm', id: '7', permissions: ['manage_members'] }

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
    api.listV1Path.mockResolvedValue([])
    api.listV1.mockResolvedValue([{ id: 12, name: 'Mysuru Growers' }])
    api.postV1Path.mockResolvedValue({ id: 31, status: 'pending' })
  })

  it('requests a scoped community link from the active farm', async () => {
    render(<FarmCommunityLinksPanel scope="farm" context={farmContext} />)

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
    const context = { type: 'farm', id: '7', permissions: ['view_farm'] }
    const { container } = render(<FarmCommunityLinksPanel scope="farm" context={context} />)
    expect(container).toBeEmptyDOMElement()
    expect(api.listV1Path).not.toHaveBeenCalled()
  })

  it('lets an authorized community manager approve a pending link', async () => {
    const context = { type: 'community', id: '9', permissions: ['manage_members'] }
    api.listV1Path.mockResolvedValue([
      {
        id: 31,
        status: 'pending',
        farm: { id: 7, name: 'North field' },
        analytics_scopes: ['crop_summary'],
        farm_access_permissions: ['view_farm'],
      },
    ])

    render(<FarmCommunityLinksPanel scope="community" context={context} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Approve' }))

    await waitFor(() =>
      expect(api.postV1Path).toHaveBeenCalledWith('farm-community-links/31/approve'),
    )
    expect(api.listV1Path).toHaveBeenCalledWith('farm-community-links', { community_id: '9' })
    expect(api.listV1).not.toHaveBeenCalled()
  })
})
