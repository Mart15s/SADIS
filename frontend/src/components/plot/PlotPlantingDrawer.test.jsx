import React from 'react'
import { MemoryRouter } from 'react-router-dom'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PlotPlantingDrawer from './PlotPlantingDrawer.jsx'
import { api } from '../../lib/api.js'

vi.mock('../../lib/api.js', () => ({
  api: {
    listCatalogPlants: vi.fn(),
  },
}))

describe('PlotPlantingDrawer', () => {
  const selectedZone = {
    id: 7,
    name: 'Greenhouse Zone',
    zone_size: 18.5,
    soil_type: 'loam',
  }

  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('keeps zone context and submits planting without per-instance plant care overrides', async () => {
    api.listCatalogPlants.mockResolvedValue([
      {
        id: 5,
        name: 'Tomato',
        canonical_name: 'tomato',
        plant_type: 'vegetable',
        source_scientific_name: 'Solanum lycopersicum',
        usage_count: 3,
        plant_care: {
          watering_interval_days: 3,
          fertilizing_interval_days: null,
          conditions: 'Full sun',
          reusable: false,
        },
      },
    ])

    const onCreatePlant = vi.fn().mockResolvedValue({ id: 11 })
    const user = userEvent.setup()

    render(
      <MemoryRouter>
        <PlotPlantingDrawer
          selectedZone={selectedZone}
          canEdit
          busy={false}
          onCreatePlant={onCreatePlant}
        />
      </MemoryRouter>,
    )

    await user.click(screen.getByTestId('open-plant-drawer'))

    await waitFor(() => {
      expect(api.listCatalogPlants).toHaveBeenCalledWith({})
    })

    expect(screen.getByText(/Zone context is locked to/i)).toHaveTextContent('Greenhouse Zone')

    await user.click(screen.getByTestId('catalog-option-5'))

    expect(screen.getByLabelText(/Display name/i)).toHaveValue('Tomato')
    expect(screen.queryByLabelText(/Watering interval/i)).not.toBeInTheDocument()
    expect(screen.getByText(/Shared plant care preview/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Edit shared care/i })).toBeInTheDocument()

    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: /Add plant to draft/i }))

    await waitFor(() => {
      expect(onCreatePlant).toHaveBeenCalledWith(expect.objectContaining({
        fk_catalog_plant_id: 5,
        fk_plant_zone_id: 7,
      }))
    })

    expect(onCreatePlant.mock.calls[0][0]).not.toHaveProperty('plant_care')
  })
})
