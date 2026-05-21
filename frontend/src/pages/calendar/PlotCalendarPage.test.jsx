import React from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PlotCalendarPage from './PlotCalendarPage.jsx'
import { api } from '../../lib/api.js'

const TODAY = new Date().toISOString().slice(0, 10)
const FIRST_DAY = '2026-04-21'
const SECOND_DAY = '2026-04-22'

vi.mock('../../lib/api.js', () => ({
  api: {
    listPlots: vi.fn(),
    getPlot: vi.fn(),
    listCalendars: vi.fn(),
    getCalendar: vi.fn(),
    listCalendarTasks: vi.fn(),
    generateCalendar: vi.fn(),
    completeTask: vi.fn(),
    rejectTask: vi.fn(),
  },
}))

function renderPage(initialEntry = '/plots/5/calendar') {
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <Routes>
        <Route path="/plots/:plotId/calendar" element={<PlotCalendarPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

function mockCommonPageState() {
  api.listPlots.mockResolvedValue([{ id: 5, access_role: 'owner' }])
  api.getPlot.mockResolvedValue({ id: 5, name: 'North Plot' })
}

describe('PlotCalendarPage inventory and lifecycle rendering', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockCommonPageState()
  })

  it('shows canonical blocked day summary, correct resource label, and distinct actual vs expected phase', async () => {
    const user = userEvent.setup()

    api.listCalendars.mockResolvedValue([
      { id: 9, start_date: TODAY, end_date: TODAY, tasks_count: 1 },
    ])
    api.getCalendar.mockResolvedValue({
      id: 9,
      start_date: TODAY,
      end_date: TODAY,
      available_dates: [TODAY],
      day_resource_summary: {
        [TODAY]: {
          day_inventory_status: 'blocked',
          blocked_task_count: 1,
          summary_text: '1 suplanuotų užduočių blokuojama dėl inventoriaus: Trąšos.',
          grouped_resource_summary: [
            {
              resource_key: 'material|kg|consumable|fertilizer',
              resource_name: 'Fertilizer',
              inventory_item_type: 'material',
              unit: 'kg',
              required_quantity: 2,
              available_quantity: 0,
              shortage_quantity: 2,
              resource_mode: 'consumable',
              resource_type_label: 'Consumable',
            },
          ],
          replenishment_tasks: [
            { id: 70, name: 'Buy Fertilizer', item: 'Fertilizer', item_quantity: 2 },
          ],
        },
      },
      tasks_by_date: {
        [TODAY]: [
          {
            id: 41,
            plant_id: 17,
            plant_name: 'Tomato',
            zone_id: 11,
            zone_name: 'Zone A',
          },
        ],
      },
      weather: [],
    })
    api.listCalendarTasks.mockResolvedValue([
      {
        id: 41,
        date: TODAY,
        name: 'Fertilize Tomato',
        type: 'fertilize',
        task_type: 'fertilize',
        priority: 'medium',
        status: 'pending',
        can_complete: false,
        plant_name: 'Tomato',
        zone_name: 'Zone A',
        actual_condition: 'planted',
        simulated_phase: 'germinating',
        lifecycle_transition: {
          from: 'planted',
          to: 'germinating',
          is_transition_day: true,
        },
        inventory_mode: 'shortage',
        inventory_context: {
          status: 'shortage',
          shortage_count: 1,
          is_actionable: false,
        },
        resource_requirements: [
          {
            id: 3,
            name: 'Fertilizer',
            type: 'material',
            unit: 'kg',
            required_quantity: 2,
            available_quantity: 0,
            shortage_quantity: 2,
            resource_mode: 'consumable',
            resource_type_label: 'Consumable',
            is_shortage: true,
          },
        ],
        inventory_shortages: [
          {
            id: 3,
            name: 'Fertilizer',
            type: 'material',
            unit: 'kg',
            shortage_quantity: 2,
            is_shortage: true,
          },
        ],
      },
    ])

    const { container } = renderPage()

    await waitFor(() => {
      expect(api.listCalendarTasks).toHaveBeenCalled()
    })

    await user.click(container.querySelector('.month-day.is-selected'))

    await waitFor(() => {
      expect(screen.getByText(/bus blokuota, kol bus atlikta/i)).toBeInTheDocument()
    })

    expect(screen.getByText(/Dienos resursai/i)).toBeInTheDocument()
    expect(screen.getByText('1 suplanuotų užduočių blokuojama dėl inventoriaus: Trąšos.')).toBeInTheDocument()
    expect(screen.getByText(/Sugeneruotos papildymo užduotys/i)).toBeInTheDocument()
    expect(screen.getByText(/Faktinė būklė: Pasodintas/i)).toBeInTheDocument()
    expect(screen.getByText(/Tikėtina būklė: Dygsta/i)).toBeInTheDocument()
    expect(screen.getByText(/planted -> germinating/i)).toBeInTheDocument()
    expect(
      screen.getAllByText((_, element) => element?.textContent?.includes('Consumable') ?? false).length,
    ).toBeGreaterThan(0)

    expect(screen.getByRole('link', { name: /Eiti į inventorių/i })).toHaveAttribute(
      'href',
      expect.stringContaining(`returnTo=%2Fplots%2F5%2Fcalendar%3FcalendarId%3D9%26date%3D${TODAY}`),
    )
    expect(screen.getByRole('button', { name: /Atlikti/i })).toBeDisabled()
    expect(api.completeTask).not.toHaveBeenCalled()
  })

  it('shows replenishment tasks without normal deduction messaging', async () => {
    const user = userEvent.setup()

    api.listCalendars.mockResolvedValue([
      { id: 9, start_date: TODAY, end_date: TODAY, tasks_count: 1 },
    ])
    api.getCalendar.mockResolvedValue({
      id: 9,
      start_date: TODAY,
      end_date: TODAY,
      available_dates: [TODAY],
      day_resource_summary: {
        [TODAY]: {
          day_inventory_status: 'partially_blocked',
          blocked_task_count: 1,
          summary_text: '1 suplanuotų užduočių blokuojama dėl inventoriaus: Trąšos.',
          grouped_resource_summary: [],
          replenishment_tasks: [{ id: 70, name: 'Buy Fertilizer', item: 'Fertilizer', item_quantity: 1 }],
        },
      },
      tasks_by_date: {
        [TODAY]: [
          {
            id: 70,
            plant_id: null,
            plant_name: null,
            zone_id: null,
            zone_name: null,
          },
        ],
      },
      weather: [],
    })
    api.listCalendarTasks.mockResolvedValue([
      {
        id: 70,
        date: TODAY,
        name: 'Buy Fertilizer',
        type: 'buy',
        task_type: 'buy',
        priority: 'medium',
        status: 'pending',
        can_complete: true,
        inventory_mode: 'replenishment',
        is_replenishment_task: true,
        comment: 'Update inventory after purchase so blocked work can continue.',
        inventory_shortages: [
          {
            id: 'material|kg|consumable|fertilizer',
            resource_name: 'Fertilizer',
            name: 'Fertilizer',
            unit: 'kg',
            shortage_quantity: 1,
            blocked_task_count: 1,
            is_shortage: true,
          },
        ],
        inventory_context: {
          status: 'replenishment',
          shortage_count: 1,
        },
      },
    ])

    const { container } = renderPage()

    await waitFor(() => {
      expect(api.listCalendarTasks).toHaveBeenCalled()
    })

    await user.click(container.querySelector('.month-day.is-selected'))

    await waitFor(() => {
      expect(screen.getByText(/Atlikus šią užduotį inventorius papildomas/i)).toBeInTheDocument()
    })

    expect(screen.getAllByText(/Trąšos/i).length).toBeGreaterThan(0)
    expect(screen.getByText(/atblokuojamų užduočių: 1/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Atlikti papildymą/i })).toBeInTheDocument()
    expect(screen.queryByText(/nurašys sunaudojamas atsargas/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/Atnaujinkite atsargas/i)).not.toBeInTheDocument()
  })

  it('marks the restock task completed and refreshes dependent tasks after replenishment', async () => {
    const user = userEvent.setup()

    api.listCalendars.mockResolvedValue([
      { id: 9, start_date: TODAY, end_date: TODAY, tasks_count: 2 },
    ])
    api.getCalendar
      .mockResolvedValueOnce({
        id: 9,
        start_date: TODAY,
        end_date: TODAY,
        available_dates: [TODAY],
        day_resource_summary: {
          [TODAY]: {
            day_inventory_status: 'blocked',
            blocked_task_count: 1,
            summary_text: '1 suplanuotų užduočių blokuojama dėl inventoriaus: Trąšos.',
            grouped_resource_summary: [
              {
                resource_key: 'material|kg|consumable|fertilizer',
                resource_name: 'Fertilizer',
                inventory_item_type: 'material',
                unit: 'kg',
                required_quantity: 1,
                available_quantity: 0,
                shortage_quantity: 1,
                resource_mode: 'consumable',
              },
            ],
            replenishment_tasks: [{ id: 70, name: 'Buy Fertilizer', item: 'Fertilizer', item_quantity: 1 }],
          },
        },
        tasks_by_date: {
          [TODAY]: [
            { id: 70, plant_id: null, plant_name: null, zone_id: null, zone_name: null },
            { id: 41, plant_id: 17, plant_name: 'Tomato', zone_id: 11, zone_name: 'Zone A' },
          ],
        },
        weather: [],
      })
      .mockResolvedValueOnce({
        id: 9,
        start_date: TODAY,
        end_date: TODAY,
        available_dates: [TODAY],
        day_resource_summary: {
          [TODAY]: {
            day_inventory_status: 'fully_covered',
            blocked_task_count: 0,
            summary_text: 'Inventoriaus pakanka visiems šios dienos suplanuotiems darbams.',
            grouped_resource_summary: [
              {
                resource_key: 'material|kg|consumable|fertilizer',
                resource_name: 'Fertilizer',
                inventory_item_type: 'material',
                unit: 'kg',
                required_quantity: 1,
                available_quantity: 1,
                shortage_quantity: 0,
                resource_mode: 'consumable',
              },
            ],
            replenishment_tasks: [],
          },
        },
        tasks_by_date: {
          [TODAY]: [
            { id: 70, plant_id: null, plant_name: null, zone_id: null, zone_name: null },
            { id: 41, plant_id: 17, plant_name: 'Tomato', zone_id: 11, zone_name: 'Zone A' },
          ],
        },
        weather: [],
      })
    api.listCalendarTasks
      .mockResolvedValueOnce([
        {
          id: 70,
          date: TODAY,
          name: 'Buy Fertilizer',
          type: 'buy',
          task_type: 'buy',
          priority: 'medium',
          status: 'pending',
          can_complete: true,
          inventory_mode: 'replenishment',
          is_replenishment_task: true,
          inventory_shortages: [
            {
              id: 'material|kg|consumable|fertilizer',
              resource_name: 'Fertilizer',
              name: 'Fertilizer',
              unit: 'kg',
              shortage_quantity: 1,
              blocked_task_count: 1,
              is_shortage: true,
            },
          ],
          inventory_context: {
            status: 'replenishment',
            shortage_count: 1,
          },
        },
        {
          id: 41,
          date: TODAY,
          name: 'Fertilize Tomato',
          type: 'fertilize',
          task_type: 'fertilize',
          priority: 'medium',
          status: 'pending',
          can_complete: false,
          plant_name: 'Tomato',
          zone_name: 'Zone A',
          inventory_mode: 'shortage',
          inventory_context: {
            status: 'shortage',
            shortage_count: 1,
            is_actionable: false,
            buy_task_ids: [70],
          },
          resource_requirements: [
            {
              id: 3,
              name: 'Fertilizer',
              type: 'material',
              unit: 'kg',
              required_quantity: 1,
              available_quantity: 0,
              shortage_quantity: 1,
              resource_mode: 'consumable',
              is_shortage: true,
            },
          ],
          inventory_shortages: [
            {
              id: 3,
              name: 'Fertilizer',
              type: 'material',
              unit: 'kg',
              shortage_quantity: 1,
              blocked_task_count: 1,
              is_shortage: true,
            },
          ],
        },
      ])
      .mockResolvedValueOnce([
        {
          id: 70,
          date: TODAY,
          name: 'Buy Fertilizer',
          type: 'buy',
          task_type: 'buy',
          priority: 'medium',
          status: 'completed',
          can_complete: false,
          inventory_mode: 'not_required',
          is_replenishment_task: true,
          comment: 'Restocked: Fertilizer (1.00 kg)',
          inventory_shortages: [],
          inventory_context: {
            status: 'completed',
            shortage_count: 0,
            is_actionable: false,
          },
        },
        {
          id: 41,
          date: TODAY,
          name: 'Fertilize Tomato',
          type: 'fertilize',
          task_type: 'fertilize',
          priority: 'medium',
          status: 'pending',
          can_complete: true,
          plant_name: 'Tomato',
          zone_name: 'Zone A',
          inventory_mode: 'available',
          inventory_context: {
            status: 'available',
            shortage_count: 0,
            is_actionable: true,
            buy_task_ids: [],
          },
          resource_requirements: [
            {
              id: 3,
              name: 'Fertilizer',
              type: 'material',
              unit: 'kg',
              required_quantity: 1,
              available_quantity: 1,
              shortage_quantity: 0,
              resource_mode: 'consumable',
              is_shortage: false,
            },
          ],
          inventory_shortages: [],
        },
      ])
    api.completeTask.mockResolvedValue({
      task: { id: 70, status: 'completed' },
    })

    const { container } = renderPage()

    await waitFor(() => {
      expect(api.listCalendarTasks).toHaveBeenCalledTimes(1)
    })

    await user.click(container.querySelector('.month-day.is-selected'))

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /Atlikti papildymą/i })).toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: /Atlikti papildymą/i }))

    await waitFor(() => {
      expect(api.completeTask).toHaveBeenCalledWith(70)
    })

    await waitFor(() => {
      expect(api.listCalendarTasks).toHaveBeenCalledTimes(2)
      expect(api.getCalendar).toHaveBeenCalledTimes(2)
    })

    expect(screen.queryByRole('button', { name: /Atlikti papildymą/i })).not.toBeInTheDocument()
    expect(screen.getByText('Inventoriaus pakanka visiems šios dienos suplanuotiems darbams.')).toBeInTheDocument()
    expect(screen.queryByText(/Sugeneruotos papildymo užduotys/i)).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Eiti į inventorių/i })).not.toBeInTheDocument()
    expect(screen.queryByText(/bus blokuota, kol bus atlikta/i)).not.toBeInTheDocument()
    expect(screen.getAllByText(/^Atlikta$/i).length).toBeGreaterThan(0)
    expect(screen.getByText('Papildyta: Trąšos (1,00 kg)')).toBeInTheDocument()
    expect(screen.queryByText(/Restocked/i)).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /^Atlikti$/i })).toBeEnabled()
  })

  it('restores the requested calendar day from the query string', async () => {
    api.listCalendars.mockResolvedValue([
      { id: 9, start_date: FIRST_DAY, end_date: SECOND_DAY, tasks_count: 1 },
    ])
    api.getCalendar.mockResolvedValue({
      id: 9,
      start_date: FIRST_DAY,
      end_date: SECOND_DAY,
      available_dates: [FIRST_DAY, SECOND_DAY],
      tasks_by_date: {
        [FIRST_DAY]: [],
        [SECOND_DAY]: [
          {
            id: 55,
            plant_id: 17,
            plant_name: 'Tomato',
            zone_id: 11,
            zone_name: 'Zone A',
          },
        ],
      },
      weather: [],
    })
    api.listCalendarTasks.mockResolvedValue([])

    renderPage(`/plots/5/calendar?calendarId=9&date=${SECOND_DAY}`)

    await waitFor(() => {
      expect(api.listCalendarTasks).toHaveBeenCalledWith('9', expect.objectContaining({ date: SECOND_DAY }))
    })
  })

  it('shows exact backend weather for the selected day and labels fallback sources', async () => {
    const user = userEvent.setup()

    api.listCalendars.mockResolvedValue([
      { id: 9, start_date: FIRST_DAY, end_date: SECOND_DAY, tasks_count: 2 },
    ])
    api.getCalendar.mockResolvedValue({
      id: 9,
      start_date: FIRST_DAY,
      end_date: SECOND_DAY,
      available_dates: [FIRST_DAY, SECOND_DAY],
      day_resource_summary: {},
      tasks_by_date: {
        [FIRST_DAY]: [],
        [SECOND_DAY]: [],
      },
      weather: [
        {
          date: FIRST_DAY,
          temp_min: 2.7,
          temp_max: 7.8,
          precipitation: 1.7,
          wind_kmh: 25.2,
          source: 'stored_city_date',
          source_date: FIRST_DAY,
          source_city: 'Kaunas',
          is_seasonal_fallback: false,
        },
        {
          date: SECOND_DAY,
          temp_min: 8.1,
          temp_max: 15.4,
          precipitation: 0.2,
          wind_kmh: 12.6,
          source: 'api',
          is_seasonal_fallback: false,
        },
      ],
    })
    api.listCalendarTasks.mockResolvedValue([])

    const { container } = renderPage(`/plots/5/calendar?calendarId=9&date=${FIRST_DAY}`)

    await waitFor(() => {
      expect(screen.getByText(/Orų prognozėje naudojami atsarginiai duomenys/i)).toBeInTheDocument()
    })

    await user.click(container.querySelector('.month-day.is-selected'))

    await waitFor(() => {
      expect(screen.getByLabelText(/Uždaryti/i)).toBeInTheDocument()
    })

    expect(screen.getByText(/Šaltinis: atsarginė Kaunas prognozė pagal .* duomenis/i)).toBeInTheDocument()
    expect(screen.getByText(/2,7 .*C/i)).toBeInTheDocument()
    expect(screen.getByText(/7,8 .*C/i)).toBeInTheDocument()
    expect(screen.getByText('1,7 mm')).toBeInTheDocument()
    expect(screen.getByText('25,2 km/h')).toBeInTheDocument()

    await user.click(screen.getByLabelText(/Uždaryti/i))
    await user.click(screen.getByRole('button', { name: '22' }))

    await waitFor(() => {
      expect(screen.getByText(/8,1 .*C/i)).toBeInTheDocument()
    })

    expect(screen.getByText(/15,4 .*C/i)).toBeInTheDocument()
    expect(screen.getByText('0,2 mm')).toBeInTheDocument()
    expect(screen.getByText('12,6 km/h')).toBeInTheDocument()
    expect(screen.queryByText(/Šaltinis: atsarginė/i)).not.toBeInTheDocument()
  })
})
