import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '../../context/auth-context.js'
import AppShell from './AppShell.jsx'
import PageHeader from './PageHeader.jsx'

vi.mock('../../context/auth-context.js', () => ({
  useAuth: vi.fn(),
}))

vi.mock('./Sidebar.jsx', () => ({
  default: () => null,
}))

vi.mock('./ContextSwitcher.jsx', () => ({
  default: () => null,
}))

describe('AppShell field editor chrome', () => {
  beforeEach(() => {
    useAuth.mockReturnValue({ isAdmin: false, isAuthenticated: true })
    window.matchMedia = vi.fn().mockReturnValue({ matches: false })
  })

  it('keeps desktop field save actions visible in the shell topbar', async () => {
    render(
      <MemoryRouter initialEntries={['/fields/7/editor']}>
        <Routes>
          <Route element={<AppShell />}>
            <Route
              path="/fields/:fieldId/editor"
              element={
                <PageHeader
                  eyebrow="Field workspace"
                  title="North Field"
                  actions={
                    <>
                      <button type="button">Cancel</button>
                      <button type="button">Save field</button>
                    </>
                  }
                />
              }
            />
          </Route>
        </Routes>
      </MemoryRouter>,
    )

    expect(await screen.findByRole('heading', { name: 'North Field' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save field' })).toBeInTheDocument()
  })
})
