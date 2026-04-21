import React from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PlotCalendarPage from './PlotCalendarPage.jsx'
import { api } from '../../lib/api.js'

const TODAY = new Date().toISOString().slice(0, 10)

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

describe('PlotCalendarPage inventory gating', () => {
  beforeEach(() => {
    vi.clearAllMocks()

    api.listPlots.mockResolvedValue([{ id: 5, access_role: 'owner' }])
    api.getPlot.mockResolvedValue({ id: 5, name: 'North Plot' })
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
          status: 'shortage',
          shortage_count: 1,
          resource_count: 1,
          resources: [
            {
              resource_key: 'material|kg|fertilizer',
              resource_name: 'Fertilizer',
              inventory_item_type: 'material',
              unit: 'kg',
              required_quantity: 2,
              available_quantity: 0,
              shortage_quantity: 2,
              consumption_mode: 'consumable',
            },
          ],
          buy_tasks: [
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
        inventory_context: {
          status: 'shortage',
          shortage_count: 1,
          is_actionable: false,
        },
        required_resources: [
          {
            id: 3,
            name: 'Fertilizer',
            type: 'material',
            unit: 'kg',
            required_quantity: 2,
            available_quantity: 0,
            shortage_quantity: 2,
            consumption_mode: 'consumable',
            is_shortage: true,
          },
        ],
      },
    ])
  })

  it('shows the inventory button and disables completion when resources are missing', async () => {
    const user = userEvent.setup()
    const { container } = render(
      <MemoryRouter initialEntries={['/plots/5/calendar']}>
        <Routes>
          <Route path="/plots/:plotId/calendar" element={<PlotCalendarPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await waitFor(() => {
      expect(api.listCalendarTasks).toHaveBeenCalled()
    })

    await user.click(container.querySelector('.month-day.is-selected'))

    await waitFor(() => {
      expect(screen.getByText(/Task completion is blocked/i)).toBeInTheDocument()
    })

    expect(screen.getByText(/Day resources/i)).toBeInTheDocument()
    expect(screen.getByText(/Resources are short/i)).toBeInTheDocument()
    expect(screen.getByText(/Generated replenishment tasks/i)).toBeInTheDocument()

    expect(screen.getByRole('link', { name: /Go to inventory/i })).toHaveAttribute(
      'href',
      expect.stringContaining(`returnTo=%2Fplots%2F5%2Fcalendar%3FcalendarId%3D9%26date%3D${TODAY}`),
    )
    expect(screen.getByRole('button', { name: /Complete/i })).toBeDisabled()
    expect(api.completeTask).not.toHaveBeenCalled()
  })

  it('restores the requested calendar day from the query string', async () => {
    const alternateDay = '2026-04-21'

    api.listCalendars.mockResolvedValue([
      { id: 9, start_date: '2026-04-20', end_date: alternateDay, tasks_count: 1 },
    ])
    api.getCalendar.mockResolvedValue({
      id: 9,
      start_date: '2026-04-20',
      end_date: alternateDay,
      available_dates: ['2026-04-20', alternateDay],
      tasks_by_date: {
        '2026-04-20': [],
        [alternateDay]: [
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

    render(
      <MemoryRouter initialEntries={[`/plots/5/calendar?calendarId=9&date=${alternateDay}`]}>
        <Routes>
          <Route path="/plots/:plotId/calendar" element={<PlotCalendarPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await waitFor(() => {
      expect(api.listCalendarTasks).toHaveBeenCalledWith('9', expect.objectContaining({ date: alternateDay }))
    })
  })

  it('shows the exact backend weather for the selected day and labels fallback sources', async () => {
    const user = userEvent.setup()
    const secondDay = '2026-04-22'

    api.listCalendars.mockResolvedValue([
      { id: 9, start_date: TODAY, end_date: secondDay, tasks_count: 2 },
    ])
    api.getCalendar.mockResolvedValue({
      id: 9,
      start_date: TODAY,
      end_date: secondDay,
      available_dates: [TODAY, secondDay],
      day_resource_summary: {},
      tasks_by_date: {
        [TODAY]: [],
        [secondDay]: [],
      },
      weather: [
        {
          date: TODAY,
          temp_min: 2.7,
          temp_max: 7.8,
          precipitation: 1.7,
          wind_kmh: 25.2,
          source: 'stored_city_date',
          source_date: TODAY,
          source_city: 'Kaunas',
          is_seasonal_fallback: false,
        },
        {
          date: secondDay,
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

    const { container } = render(
      <MemoryRouter initialEntries={['/plots/5/calendar']}>
        <Routes>
          <Route path="/plots/:plotId/calendar" element={<PlotCalendarPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await waitFor(() => {
      expect(screen.getByText(/Weather forecast includes fallback data/i)).toBeInTheDocument()
    })

    await user.click(container.querySelector(`[title^="${TODAY}"]`))

    await waitFor(() => {
      expect(screen.getByLabelText(/Close/i)).toBeInTheDocument()
    })

    await waitFor(() => {
      expect(screen.getByText(/Source: Stored forecast \(same city\/date\)/i)).toBeInTheDocument()
    })
    expect(screen.getByText('2.7 °C')).toBeInTheDocument()
    expect(screen.getByText('7.8 °C')).toBeInTheDocument()
    expect(screen.getByText('1.7 mm')).toBeInTheDocument()
    expect(screen.getByText('25.2 km/h')).toBeInTheDocument()

    await user.click(screen.getByLabelText(/Close/i))
    await user.click(screen.getByRole('button', { name: '22' }))

    await waitFor(() => {
      expect(screen.getByText('8.1 °C')).toBeInTheDocument()
    })
    expect(screen.getByText('15.4 °C')).toBeInTheDocument()
    expect(screen.getByText('0.2 mm')).toBeInTheDocument()
    expect(screen.getByText('12.6 km/h')).toBeInTheDocument()
    expect(screen.queryByText(/Source: Stored forecast/i)).not.toBeInTheDocument()
  })
})
