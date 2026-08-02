import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nContext.jsx'
import { api } from '../../lib/api.js'
import CalendarWorkspacePage from './CalendarWorkspacePage.jsx'

const workspace = {
  active: { type: 'farm', id: '7', name: 'Sunrise', timezone: 'Asia/Kolkata' },
}

vi.mock('../../context/useWorkspace.js', () => ({
  useWorkspace: () => workspace,
}))

vi.mock('../../lib/api.js', () => ({
  api: { listV1: vi.fn() },
}))

describe('Stage 1 calendar', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.listV1.mockResolvedValue([
      {
        id: 2,
        title: 'Inspect irrigation',
        starts_at: '2026-08-02T04:45:00.000Z',
        priority: 'high',
        status: 'pending',
      },
      { id: 3, title: 'Unscheduled note', status: 'pending' },
    ])
  })

  it('loads and renders dated tasks from the active scope', async () => {
    render(
      <MemoryRouter>
        <I18nProvider>
          <CalendarWorkspacePage />
        </I18nProvider>
      </MemoryRouter>,
    )

    await waitFor(() => expect(api.listV1).toHaveBeenCalledWith('tasks', { farm_id: '7' }))
    expect(await screen.findByText('Inspect irrigation')).toBeInTheDocument()
    expect(screen.queryByText('Unscheduled note')).not.toBeInTheDocument()
    expect(screen.getByRole('list', { name: 'Planned tasks' })).toBeInTheDocument()
  })
})
