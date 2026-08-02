import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nContext.jsx'
import { api } from '../../lib/api.js'
import AnalyticsWorkspacePage from './AnalyticsWorkspacePage.jsx'

const workspace = {
  active: {
    type: 'farm',
    id: '7',
    name: 'Sunrise Farm',
    timezone: 'Asia/Kolkata',
    permissions: ['view_farm', 'view_analytics'],
  },
}

vi.mock('../../context/useWorkspace.js', () => ({
  useWorkspace: () => workspace,
}))

vi.mock('../../lib/api.js', () => ({
  api: { getV1Path: vi.fn(), listV1Path: vi.fn() },
}))

describe('Stage 1 analytics workspace', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.getV1Path.mockResolvedValue({
      area_square_metres: 12000,
      active_crop_seasons: 2,
      open_tasks: 3,
      harvest_quantities: { kg: 850, tonne: 1.25 },
    })
    api.listV1Path.mockResolvedValue([
      {
        id: 11,
        event: 'crop_season_created',
        field_name: 'North Field',
        created_at: '2026-08-02T04:45:00.000Z',
      },
    ])
  })

  it('keeps harvest units separate and renders farm planning history', async () => {
    render(
      <MemoryRouter>
        <I18nProvider>
          <AnalyticsWorkspacePage />
        </I18nProvider>
      </MemoryRouter>,
    )

    await waitFor(() => expect(api.getV1Path).toHaveBeenCalledWith('farms/7/analytics'))
    expect(api.listV1Path).toHaveBeenCalledWith('planning-history', { farm_id: '7' })
    expect(await screen.findByText(/850 kg/)).toBeInTheDocument()
    expect(screen.getByText(/1\.25 tonne/)).toBeInTheDocument()
    expect(screen.getByText('crop season created')).toBeInTheDocument()
    expect(screen.getByText('North Field')).toBeInTheDocument()
  })
})
