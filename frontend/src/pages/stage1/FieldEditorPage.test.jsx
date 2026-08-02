import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FieldEditorPage from './FieldEditorPage.jsx'
import { api } from '../../lib/api.js'

const access = vi.hoisted(() => ({
  isAdmin: false,
  contexts: [{ type: 'farm', id: '7', permissions: ['view_farm', 'manage_fields'] }],
}))

vi.mock('../../context/auth-context.js', () => ({
  useAuth: () => ({ isAdmin: access.isAdmin }),
}))

vi.mock('../../context/useWorkspace.js', () => ({
  useWorkspace: () => ({ contexts: access.contexts, loading: false }),
}))

vi.mock('../../lib/api.js', () => ({
  api: {
    getV1: vi.fn(),
    putV1Path: vi.fn(),
  },
}))

function renderEditor() {
  return render(
    <MemoryRouter initialEntries={['/fields/9/editor']}>
      <Routes>
        <Route path="/fields/:fieldId/editor" element={<FieldEditorPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('Field Editor workspace', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
    access.isAdmin = false
    access.contexts = [{ type: 'farm', id: '7', permissions: ['view_farm', 'manage_fields'] }]
    api.getV1.mockResolvedValue({
      id: 9,
      farm_id: 7,
      name: 'North field',
      workspace_revision: 2,
      boundary: [
        { x: 10, y: 10 },
        { x: 80, y: 10 },
        { x: 80, y: 70 },
      ],
      zones: [],
      markers: [],
    })
  })

  it('edits boundary coordinates, creates a zone, and saves one workspace mutation', async () => {
    api.putV1Path.mockResolvedValue({ workspace_revision: 3 })
    renderEditor()

    await screen.findByRole('heading', { name: 'North field' })
    expect(screen.getAllByRole('button', { name: 'Save field' })[0]).toHaveClass(
      'field-editor-desktop-action',
    )
    fireEvent.click(screen.getByRole('button', { name: 'Layers' }))
    fireEvent.click(screen.getByText('Boundary coordinates'))
    fireEvent.change(screen.getByLabelText('Point 1 X coordinate'), { target: { value: '15' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zones' }))
    fireEvent.click(screen.getByRole('button', { name: 'Add zone' }))
    fireEvent.change(screen.getByLabelText('Zone name'), { target: { value: 'Irrigation zone' } })
    fireEvent.click(screen.getAllByRole('button', { name: 'Save field' })[0])

    await waitFor(() => expect(api.putV1Path).toHaveBeenCalledTimes(1))
    expect(api.putV1Path).toHaveBeenCalledWith(
      'fields/9/workspace',
      expect.objectContaining({
        client_revision: 2,
        boundary: expect.arrayContaining([expect.objectContaining({ x: 15 })]),
        zones: [expect.objectContaining({ name: 'Irrigation zone' })],
      }),
    )
    await screen.findByText('Field workspace saved.')
    expect(localStorage.getItem('yava-field-draft:9')).toBeNull()
  })

  it('keeps the recoverable draft and editing shell mounted when saving fails', async () => {
    api.putV1Path.mockRejectedValue(new Error('The field changed elsewhere.'))
    renderEditor()

    await screen.findByRole('heading', { name: 'North field' })
    fireEvent.click(screen.getByRole('button', { name: 'Layers' }))
    fireEvent.click(screen.getByText('Boundary coordinates'))
    fireEvent.change(screen.getByLabelText('Point 1 Y coordinate'), { target: { value: '22' } })
    fireEvent.click(screen.getAllByRole('button', { name: 'Save field' })[0])

    expect(await screen.findByText('The field changed elsewhere.')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'North field' })).toBeInTheDocument()
    expect(screen.getByLabelText('Point 1 Y coordinate')).toHaveValue(22)
    expect(localStorage.getItem('yava-field-draft:9')).toContain('"y":22')
  })

  it('does not mount editing controls for a viewer opening the editor directly', async () => {
    access.contexts = [{ type: 'farm', id: '7', permissions: ['view_farm'] }]
    renderEditor()

    expect(
      await screen.findByText('You do not have permission to edit this field.'),
    ).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Save field' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Boundary' })).not.toBeInTheDocument()
    expect(api.putV1Path).not.toHaveBeenCalled()
  })
})
