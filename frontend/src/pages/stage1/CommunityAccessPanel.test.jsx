import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CommunityAccessPanel from './CommunityAccessPanel.jsx'
import { api } from '../../lib/api.js'

vi.mock('../../lib/api.js', () => ({
  api: {
    listV1Path: vi.fn(),
    postV1Path: vi.fn(),
  },
}))

function renderPanel(onChanged = vi.fn()) {
  return render(
    <MemoryRouter>
      <CommunityAccessPanel onChanged={onChanged} />
    </MemoryRouter>,
  )
}

describe('CommunityAccessPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.listV1Path.mockResolvedValue([
      { id: 4, name: 'River Growers', locality: 'Kaunas', state_code: 'LT-KU' },
      {
        id: 7,
        name: 'Coastal Cooperative',
        locality: 'Klaipeda',
        join_request_status: 'pending',
      },
    ])
    api.postV1Path.mockResolvedValue({ id: 18, status: 'pending' })
  })

  it('loads privacy-safe discovery options and submits the selected community', async () => {
    const user = userEvent.setup()
    renderPanel()

    expect(api.listV1Path).toHaveBeenCalledWith('communities/discover')
    const communitySelect = await screen.findByRole('combobox', { name: 'Community' })
    expect(screen.queryByLabelText('Community ID')).not.toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'River Growers — Kaunas, LT-KU' })).toBeEnabled()
    expect(
      screen.getByRole('option', { name: 'Coastal Cooperative — Klaipeda · request pending' }),
    ).toBeDisabled()

    await user.selectOptions(communitySelect, '4')
    await user.type(screen.getByLabelText('Message (optional)'), 'We farm nearby.')
    await user.click(screen.getByRole('button', { name: 'Request to join' }))

    await waitFor(() =>
      expect(api.postV1Path).toHaveBeenCalledWith('communities/4/join-requests', {
        message: 'We farm nearby.',
      }),
    )
    expect(screen.getByText(/Join request submitted/)).toBeInTheDocument()
    expect(communitySelect).toHaveValue('')
  })

  it('offers a retry when community discovery fails', async () => {
    const user = userEvent.setup()
    api.listV1Path.mockRejectedValueOnce(new Error('Communities unavailable'))
    renderPanel()

    expect(await screen.findByRole('alert')).toHaveTextContent('Communities unavailable')
    await user.click(screen.getByRole('button', { name: 'Retry communities' }))

    expect(
      await screen.findByRole('option', { name: 'River Growers — Kaunas, LT-KU' }),
    ).toBeEnabled()
    expect(api.listV1Path).toHaveBeenCalledTimes(2)
  })
})
