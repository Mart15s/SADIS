import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DomainWorkspacePage from './DomainWorkspacePage.jsx'
import { I18nProvider } from '../../i18n/I18nContext.jsx'
import { api } from '../../lib/api.js'

const workspaceState = vi.hoisted(() => ({
  active: {
    type: 'farm',
    id: '7',
    name: 'Sunrise Farm',
    timezone: 'Asia/Kolkata',
    permissions: ['manage_fields', 'manage_crops', 'manage_tasks', 'manage_inventory'],
  },
  contexts: [],
  reload: vi.fn(),
}))

vi.mock('../../context/useWorkspace.js', () => ({
  useWorkspace: () => workspaceState,
}))
vi.mock('../../lib/api.js', () => ({
  api: {
    listV1: vi.fn(),
    listV1Path: vi.fn(),
    getV1: vi.fn(),
    createV1: vi.fn(),
    updateV1: vi.fn(),
    deleteV1: vi.fn(),
    transitionV1: vi.fn(),
    postV1Path: vi.fn(),
  },
}))

function renderPage(resource = 'tasks') {
  return render(
    <MemoryRouter>
      <I18nProvider>
        <DomainWorkspacePage resource={resource} />
      </I18nProvider>
    </MemoryRouter>,
  )
}

describe('Stage 1 resilient domain workspace', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    workspaceState.active.permissions = [
      'manage_fields',
      'manage_crops',
      'manage_tasks',
      'manage_inventory',
    ]
    api.listV1.mockResolvedValue([])
    api.listV1Path.mockResolvedValue([])
    api.getV1.mockResolvedValue(null)
  })

  it('scopes requests to the active farm and prevents duplicate submissions while saving', async () => {
    let resolveCreate
    api.createV1.mockReturnValue(
      new Promise((resolve) => {
        resolveCreate = resolve
      }),
    )
    renderPage()
    await waitFor(() =>
      expect(api.listV1).toHaveBeenCalledWith('tasks', expect.objectContaining({ farm_id: '7' })),
    )
    fireEvent.click(screen.getAllByRole('button', { name: 'Add task' })[0])
    fireEvent.change(screen.getByLabelText('Task title'), {
      target: { value: 'Inspect irrigation' },
    })
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
    fireEvent.change(screen.getByLabelText('Task title'), {
      target: { value: 'Water north field' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The task could not be saved.')
    expect(screen.getByLabelText('Task title')).toHaveValue('Water north field')
  })

  it('auto-scopes a crop season and offers scoped fields and crops as selectors', async () => {
    api.listV1.mockImplementation((resource) => {
      if (resource === 'fields') return Promise.resolve([{ id: 3, name: 'North field' }])
      if (resource === 'crops') {
        return Promise.resolve([{ id: 5, name: 'Rice', varieties: [] }])
      }
      return Promise.resolve([])
    })
    api.createV1.mockResolvedValue({
      id: 18,
      farm_id: 7,
      field_id: 3,
      crop_id: 5,
      starts_on: '2026-08-04',
    })

    renderPage('crop-seasons')
    await screen.findByText('Start here')
    fireEvent.click(screen.getAllByRole('button', { name: 'Add crop season' })[0])
    await waitFor(() => expect(screen.getByLabelText('Field')).toHaveTextContent('North field'))
    expect(screen.queryByLabelText('Farm ID')).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Field'), { target: { value: '3' } })
    fireEvent.change(screen.getByLabelText('Crop'), { target: { value: '5' } })
    fireEvent.change(screen.getByLabelText('Starts on'), { target: { value: '2026-08-04' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() =>
      expect(api.createV1).toHaveBeenCalledWith(
        'crop-seasons',
        expect.objectContaining({ farm_id: '7', field_id: '3', crop_id: '5' }),
      ),
    )
  })

  it('hides task mutations when the resolved workspace permission is absent', async () => {
    workspaceState.active.permissions = ['view_farm']
    renderPage('tasks')
    await screen.findByText('Start here')
    expect(screen.queryByRole('button', { name: 'Add task' })).not.toBeInTheDocument()
  })

  it('records a crop-season harvest once and updates the visible history count', async () => {
    let resolveHarvest
    api.listV1.mockImplementation((resource) =>
      Promise.resolve(
        resource === 'crop-seasons'
          ? [
              {
                id: 18,
                name: 'Monsoon rice',
                status: 'active',
                starts_on: '2026-06-01',
                conditions: [],
                harvests: [],
              },
            ]
          : [],
      ),
    )
    api.postV1Path.mockReturnValue(
      new Promise((resolve) => {
        resolveHarvest = resolve
      }),
    )

    renderPage('crop-seasons')
    await screen.findByRole('heading', { name: 'Monsoon rice' })
    fireEvent.click(screen.getByRole('button', { name: 'Record harvest' }))
    fireEvent.change(screen.getByLabelText('Quantity'), { target: { value: '12.5' } })
    fireEvent.change(screen.getByLabelText('Harvested on'), {
      target: { value: '2026-08-02' },
    })
    const submit = within(screen.getByRole('dialog')).getByRole('button', {
      name: 'Record harvest',
    })
    fireEvent.click(submit)
    fireEvent.click(submit)

    expect(api.postV1Path).toHaveBeenCalledTimes(1)
    expect(api.postV1Path).toHaveBeenCalledWith('crop-seasons/18/harvests', {
      quantity: '12.5',
      unit: 'kg',
      harvested_on: '2026-08-02',
    })
    resolveHarvest({ id: 91, quantity: 12.5, unit: 'kg', harvested_on: '2026-08-02' })

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.getByText('Harvest records').nextElementSibling).toHaveTextContent('1')
    expect(screen.getByText('Harvest recorded.')).toBeInTheDocument()
  })

  it('records a scoped inventory movement and updates the available balance', async () => {
    api.listV1.mockImplementation((resource) => {
      if (resource === 'inventories') {
        return Promise.resolve([
          { id: 25, name: 'Seed bags', quantity: 10, unit: 'bag', movements: [] },
        ])
      }
      if (resource === 'fields') return Promise.resolve([{ id: 3, name: 'North field' }])
      if (resource === 'crop-seasons') {
        return Promise.resolve([{ id: 18, name: 'Monsoon rice' }])
      }
      return Promise.resolve([])
    })
    api.postV1Path.mockResolvedValue({
      id: 52,
      type: 'receipt',
      quantity: 5,
      balance_after: 15,
      field_id: 3,
      crop_season_id: 18,
    })

    renderPage('inventories')
    await screen.findByRole('heading', { name: 'Seed bags' })
    fireEvent.click(screen.getByRole('button', { name: 'Record movement' }))
    await waitFor(() =>
      expect(screen.getByLabelText('Field (optional)')).toHaveTextContent('North field'),
    )
    fireEvent.change(screen.getByLabelText('Quantity'), { target: { value: '5' } })
    fireEvent.change(screen.getByLabelText('Field (optional)'), { target: { value: '3' } })
    fireEvent.change(screen.getByLabelText('Crop season (optional)'), {
      target: { value: '18' },
    })
    fireEvent.click(
      within(screen.getByRole('dialog')).getByRole('button', { name: 'Record movement' }),
    )

    await waitFor(() =>
      expect(api.postV1Path).toHaveBeenCalledWith('inventory-movements', {
        inventory_id: 25,
        type: 'receipt',
        quantity: '5',
        field_id: '3',
        crop_season_id: '18',
      }),
    )
    expect(screen.getByText('Available').nextElementSibling).toHaveTextContent('15 bag')
    expect(screen.getByText('Movements').nextElementSibling).toHaveTextContent('1')
    expect(screen.getByLabelText('Recent movements for Seed bags')).toHaveTextContent(
      'receipt — 5 bag · Field #3 · Crop season #18',
    )
  })
})
