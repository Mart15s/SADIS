import React from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import InventoryPage from './InventoryPage.jsx'
import { api } from '../../lib/api.js'

vi.mock('../../lib/api.js', () => ({
  api: {
    listInventory: vi.fn(),
    getInventoryItem: vi.fn(),
    createInventoryItem: vi.fn(),
    updateInventoryItem: vi.fn(),
    deleteInventoryItem: vi.fn(),
  },
}))

describe('InventoryPage task replenishment flow', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('prefills the add form and keeps a direct way back to the task context', async () => {
    api.listInventory.mockResolvedValue([])

    const missing = encodeURIComponent(JSON.stringify([
      {
        id: 1,
        name: 'Protective cover',
        type: 'tool',
        unit: 'unit',
        required_quantity: 23,
        available_quantity: 0,
        shortage_quantity: 23,
      },
    ]))

    render(
      <MemoryRouter initialEntries={[`/inventory?taskId=41&taskName=Buy%20Protective%20cover&returnTo=%2Fplots%2F5%2Fcalendar%3FcalendarId%3D9%26date%3D2026-04-20&returnLabel=Back%20to%20calendar%20day&missing=${missing}`]}>
        <Routes>
          <Route path="/inventory" element={<InventoryPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await waitFor(() => {
      expect(screen.getByDisplayValue('Protective cover')).toBeInTheDocument()
    })

    expect(screen.getByRole('heading', { name: /Add inventory item/i })).toBeInTheDocument()
    expect(screen.getByLabelText(/Name/i)).toHaveValue('Protective cover')
    expect(screen.getByLabelText(/Type/i)).toHaveValue('tool')
    expect(screen.getByLabelText(/Quantity/i)).toHaveValue(23)
    expect(screen.getAllByRole('link', { name: /Back to calendar day/i })[0]).toHaveAttribute(
      'href',
      '/plots/5/calendar?calendarId=9&date=2026-04-20',
    )
  })

  it('switches to restock editing when the missing item already exists', async () => {
    api.listInventory.mockResolvedValue([
      {
        id: 7,
        name: 'Protective cover',
        type: 'tool',
        unit: 'unit',
        quantity: 1,
        minimum_quantity: 0,
        is_below_minimum: false,
      },
    ])

    const missing = encodeURIComponent(JSON.stringify([
      {
        id: 1,
        name: 'Protective cover',
        type: 'tool',
        unit: 'unit',
        required_quantity: 23,
        available_quantity: 1,
        shortage_quantity: 22,
      },
    ]))

    render(
      <MemoryRouter initialEntries={[`/inventory?taskId=41&taskName=Buy%20Protective%20cover&missing=${missing}`]}>
        <Routes>
          <Route path="/inventory" element={<InventoryPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /Edit inventory item/i })).toBeInTheDocument()
    })

    expect(screen.getByLabelText(/Name/i)).toHaveValue('Protective cover')
    expect(screen.getByLabelText(/Quantity/i)).toHaveValue(23)
  })
})
