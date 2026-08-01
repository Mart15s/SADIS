import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DomainWorkspacePage from './DomainWorkspacePage.jsx'
import { I18nProvider } from '../../i18n/I18nContext.jsx'
import { api } from '../../lib/api.js'

vi.mock('../../context/useWorkspace.js', () => ({ useWorkspace: () => ({ active: { type: 'farm', id: '7', name: 'Sunrise Farm', timezone: 'Asia/Kolkata' } }) }))
vi.mock('../../lib/api.js', () => ({ api: { listV1: vi.fn(), createV1: vi.fn(), updateV1: vi.fn(), deleteV1: vi.fn(), transitionV1: vi.fn() } }))

function renderPage(resource = 'tasks') {
  return render(<MemoryRouter><I18nProvider><DomainWorkspacePage resource={resource} /></I18nProvider></MemoryRouter>)
}

describe('Stage 1 resilient domain workspace', () => {
  beforeEach(() => { vi.clearAllMocks(); api.listV1.mockResolvedValue([]) })

  it('scopes requests to the active farm and prevents duplicate submissions while saving', async () => {
    let resolveCreate
    api.createV1.mockReturnValue(new Promise((resolve) => { resolveCreate = resolve }))
    renderPage()
    await waitFor(() => expect(api.listV1).toHaveBeenCalledWith('tasks', expect.objectContaining({ farm_id: '7' })))
    fireEvent.click(screen.getAllByRole('button', { name: 'Add task' })[0])
    fireEvent.change(screen.getByLabelText('Task title'), { target: { value: 'Inspect irrigation' } })
    const save = screen.getByRole('button', { name: 'Save' })
    fireEvent.click(save)
    fireEvent.click(save)
    expect(api.createV1).toHaveBeenCalledTimes(1)
    resolveCreate({ id: 12, title: 'Inspect irrigation', status: 'pending' })
    await waitFor(() => expect(screen.getByText('Inspect irrigation')).toBeInTheDocument())
  })

  it('preserves form input and shows the server error when a mutation fails', async () => {
    api.createV1.mockRejectedValue(new Error('The task could not be saved.'))
    renderPage()
    await screen.findByText('Start here')
    fireEvent.click(screen.getAllByRole('button', { name: 'Add task' })[0])
    fireEvent.change(screen.getByLabelText('Task title'), { target: { value: 'Water north field' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The task could not be saved.')
    expect(screen.getByLabelText('Task title')).toHaveValue('Water north field')
  })
})
