import { startTransition, useEffect, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import Badge from '../../components/ui/Badge.jsx'
import PageHeader from '../../components/layout/PageHeader.jsx'
import {
  EmptyState,
  ErrorState,
  LoadingState,
  ProcessingState,
  SuccessToast,
} from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'
import { formatDate, formatInventoryUnit, safeNumber } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

function flattenCalendarTasks(calendar) {
  return Object.values(calendar?.tasks_by_date ?? {}).flat()
}

function uniqueOptions(calendar, keyId, keyName) {
  return flattenCalendarTasks(calendar)
    .filter((task) => task[keyId] && task[keyName])
    .reduce((options, task) => {
      if (options.some((entry) => String(entry.id) === String(task[keyId]))) {
        return options
      }
      return [...options, { id: task[keyId], name: task[keyName] }]
    }, [])
    .sort((left, right) => left.name.localeCompare(right.name))
}

function priorityTone(priority) {
  if (priority === 'high') return 'danger'
  if (priority === 'medium') return 'warning'
  return 'neutral'
}

function statusTone(status) {
  if (status === 'completed') return 'success'
  if (status === 'canceled' || status === 'cancelled') return 'danger'
  return 'warning'
}

function buildCalendarReturnPath(plotId, calendarId, date) {
  const params = new URLSearchParams()

  if (calendarId) {
    params.set('calendarId', String(calendarId))
  }

  if (date) {
    params.set('date', date)
  }

  const query = params.toString()

  return query ? `/plots/${plotId}/calendar?${query}` : `/plots/${plotId}/calendar`
}

function buildInventoryLink(task, context = {}) {
  const shortages = (task.required_resources ?? [])
    .filter((resource) => resource.is_shortage)
    .map((resource) => ({
      id: resource.id,
      name: resource.name,
      type: resource.type,
      unit: resource.unit,
      required_quantity: resource.required_quantity,
      available_quantity: resource.available_quantity,
      shortage_quantity: resource.shortage_quantity,
      consumption_mode: resource.consumption_mode,
    }))

  const params = new URLSearchParams()
  params.set('taskId', String(task.id))
  params.set('taskName', task.name)
  params.set('missing', JSON.stringify(shortages))

  if (context.plotId) {
    params.set('returnTo', buildCalendarReturnPath(context.plotId, context.calendarId, context.date))
    params.set('returnLabel', context.date ? `Back to ${formatDate(context.date)}` : 'Back to calendar')
  }

  return `/inventory?${params.toString()}`
}

function weatherSourceLabel(source) {
  if (source === 'api') return 'Live Meteo.lt'
  if (source === 'stored_city_date') return 'Stored forecast (same city/date)'
  if (source === 'stored_other_city_date') return 'Stored forecast (same date fallback)'
  if (source === 'seasonal') return 'Seasonal fallback'
  if (source === 'legacy_unknown') return 'Legacy forecast data'
  return 'Fallback forecast'
}

// ── Monthly calendar helpers ──────────────────────────────────────

function getMonthDays(yearMonth) {
  const [year, month] = yearMonth.split('-').map(Number)
  const firstDay = new Date(year, month - 1, 1)
  const lastDay = new Date(year, month, 0)
  let startPad = firstDay.getDay() - 1
  if (startPad < 0) startPad = 6
  const days = []
  for (let i = 0; i < startPad; i++) days.push(null)
  for (let d = 1; d <= lastDay.getDate(); d++) {
    days.push(`${year}-${String(month).padStart(2, '0')}-${String(d).padStart(2, '0')}`)
  }
  return days
}

function shiftMonth(yearMonth, delta) {
  const [y, m] = yearMonth.split('-').map(Number)
  const date = new Date(y, m - 1 + delta, 1)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

function formatMonthTitle(yearMonth) {
  const [year, month] = yearMonth.split('-').map(Number)
  return new Date(year, month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' })
}

const TODAY = new Date().toISOString().slice(0, 10)
const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

// ─────────────────────────────────────────────────────────────────

export default function PlotCalendarPage() {
  const { plotId } = useParams()
  const [searchParams] = useSearchParams()
  const [selectedCalendarId, setSelectedCalendarId] = useState(() => searchParams.get('calendarId'))
  const [selectedDate, setSelectedDate] = useState(() => searchParams.get('date') ?? '')
  const [filters, setFilters] = useState({ plant_id: '', zone_id: '' })
  const [generateForm, setGenerateForm] = useState({
    start_date: TODAY,
    end_date: '',
  })
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [toastMessage, setToastMessage] = useState('')
  const [currentMonth, setCurrentMonth] = useState(() => (searchParams.get('date') ?? TODAY).slice(0, 7))
  const [dayModalOpen, setDayModalOpen] = useState(false)

  const pageState = useAsyncData(
    async () => {
      const plots = await api.listPlots()
      const accessRole = plots.find((entry) => String(entry.id) === String(plotId))?.access_role ?? null
      const [plot, calendars] = await Promise.all([
        api.getPlot(plotId),
        api.listCalendars(plotId),
      ])
      return { plot, calendars, accessRole }
    },
    [plotId],
    { plot: null, calendars: [], accessRole: null },
  )

  useEffect(() => {
    if (!selectedCalendarId && pageState.data.calendars.length > 0) {
      setSelectedCalendarId(pageState.data.calendars[0].id)
    }
  }, [pageState.data.calendars, selectedCalendarId])

  const detailState = useAsyncData(
    async () => {
      if (!selectedCalendarId) return null
      return api.getCalendar(plotId, selectedCalendarId)
    },
    [plotId, selectedCalendarId],
    null,
  )

  const availableDates = detailState.data?.available_dates ?? []

  useEffect(() => {
    if (!detailState.data) return
    if (!selectedDate || !availableDates.includes(selectedDate)) {
      setSelectedDate(availableDates[0] ?? detailState.data.start_date ?? '')
    }
  }, [availableDates, detailState.data, selectedDate])

  useEffect(() => {
    setFilters({ plant_id: '', zone_id: '' })
  }, [selectedCalendarId])

  const tasksState = useAsyncData(
    async () => {
      if (!selectedCalendarId || !selectedDate) return []
      return api.listCalendarTasks(selectedCalendarId, {
        date: selectedDate,
        plant_id: filters.plant_id || undefined,
        zone_id: filters.zone_id || undefined,
      })
    },
    [selectedCalendarId, selectedDate, filters.plant_id, filters.zone_id],
    [],
  )

  const canEdit = ['owner', 'editor'].includes(pageState.data.accessRole)

  const plantOptions = uniqueOptions(detailState.data, 'plant_id', 'plant_name')
  const zoneOptions = uniqueOptions(detailState.data, 'zone_id', 'zone_name')
  const selectedForecast = detailState.data?.weather?.find((f) => f.date === selectedDate) ?? null
  const selectedDaySummary = detailState.data?.day_resource_summary?.[selectedDate] ?? null
  const weatherSources = [...new Set((detailState.data?.weather ?? []).map((forecast) => forecast.source).filter(Boolean))]
  const usingWeatherFallback = weatherSources.some((source) => source !== 'api')

  async function handleGenerate(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    try {
      const created = await api.generateCalendar(plotId, generateForm)
      await pageState.reload()
      startTransition(() => { setSelectedCalendarId(created.id) })
      setToastMessage('Calendar generated successfully.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  async function handleTaskAction(taskId, action) {
    setSubmitting(true)
    setError('')
    try {
      if (action === 'complete') {
        await api.completeTask(taskId)
        setToastMessage('Task completed.')
      } else {
        await api.rejectTask(taskId)
        setToastMessage('Task rejected.')
      }
      await Promise.all([tasksState.reload(), detailState.reload()])
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  function handleDayClick(day) {
    if (!day) return
    startTransition(() => { setSelectedDate(day) })
    setDayModalOpen(true)
  }

  function closeDayModal() {
    setDayModalOpen(false)
  }

  if (pageState.loading) return <LoadingState title="Loading calendars..." />
  if (pageState.error) return <ErrorState error={pageState.error} onRetry={pageState.reload} />

  const monthDays = getMonthDays(currentMonth)

  return (
    <div className="page-stack">
      <PageHeader
        eyebrow="Recommendation calendar"
        title={`${pageState.data.plot?.name ?? 'Plot'} calendar`}
        description="Generate a recommendation calendar, then click any day on the grid to view actions, weather, and mark tasks."
        actions={(
          <Link to={`/plots/${plotId}`}>
            <Button variant="secondary">Back to plot</Button>
          </Link>
        )}
      />

      <SuccessToast message={toastMessage} onDismiss={() => setToastMessage('')} />

      <div className="calendar-layout">
        {/* ── Left sidebar ── */}
        <aside className="page-stack">
          {canEdit ? (
            <form className="panel input-grid" onSubmit={handleGenerate}>
              <h3 style={{ margin: 0 }}>Generate calendar</h3>
              <div className="field">
                <label htmlFor="calendar-start">Start date</label>
                <input
                  id="calendar-start"
                  type="date"
                  value={generateForm.start_date}
                  onChange={(e) => setGenerateForm((c) => ({ ...c, start_date: e.target.value }))}
                  required
                />
              </div>
              <div className="field">
                <label htmlFor="calendar-end">End date</label>
                <input
                  id="calendar-end"
                  type="date"
                  value={generateForm.end_date}
                  onChange={(e) => setGenerateForm((c) => ({ ...c, end_date: e.target.value }))}
                  required
                />
              </div>
              {error ? <span className="field-error">{error}</span> : null}
              {submitting ? (
                <ProcessingState
                  title="Generating calendar"
                  description="The calendar engine is combining weather, plant care, and current plot data into scheduled tasks."
                  steps={['Preparing plot data', 'Checking weather rules', 'Generating tasks']}
                  compact
                />
              ) : null}
              <Button type="submit" loading={submitting}>
                {submitting ? 'Generating calendar' : 'Generate'}
              </Button>
            </form>
          ) : null}

          <section className="panel page-stack">
            <h3 style={{ margin: 0 }}>Calendars</h3>
            {pageState.data.calendars.length === 0 ? (
              <EmptyState title="No calendars yet" description="Generate the first recommendation calendar for this plot." />
            ) : pageState.data.calendars.map((calendar) => (
              <button
                key={calendar.id}
                type="button"
                className={`card calendar-card ${selectedCalendarId === calendar.id ? 'is-selected' : ''}`}
                onClick={() => { startTransition(() => { setSelectedCalendarId(calendar.id) }) }}
              >
                <h3 style={{ margin: 0 }}>Calendar #{calendar.id}</h3>
                <span className="muted">
                  {formatDate(calendar.start_date)} – {formatDate(calendar.end_date)}
                </span>
                <span>{calendar.tasks_count ?? 0} tasks</span>
              </button>
            ))}
          </section>

          {/* Filters */}
          {detailState.data ? (
            <section className="panel page-stack">
              <h3 style={{ margin: 0 }}>Filters</h3>
              <div className="field">
                <label htmlFor="calendar-plant-filter">Plant</label>
                <select
                  id="calendar-plant-filter"
                  value={filters.plant_id}
                  onChange={(e) => setFilters((c) => ({ ...c, plant_id: e.target.value }))}
                >
                  <option value="">All plants</option>
                  {plantOptions.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
              </div>
              <div className="field">
                <label htmlFor="calendar-zone-filter">Zone</label>
                <select
                  id="calendar-zone-filter"
                  value={filters.zone_id}
                  onChange={(e) => setFilters((c) => ({ ...c, zone_id: e.target.value }))}
                >
                  <option value="">All zones</option>
                  {zoneOptions.map((z) => <option key={z.id} value={z.id}>{z.name}</option>)}
                </select>
              </div>
            </section>
          ) : null}
        </aside>

        {/* ── Right main — monthly grid ── */}
        <section className="page-stack">
          {detailState.loading ? <LoadingState title="Loading calendar..." /> : null}
          {detailState.error ? <ErrorState error={detailState.error} onRetry={detailState.reload} /> : null}

          {!detailState.loading && !detailState.data ? (
            <EmptyState title="Pick a calendar" description="Select a calendar on the left to view the monthly grid." />
          ) : null}

          {!detailState.loading && detailState.data ? (
            <section className="panel page-stack">
              {usingWeatherFallback ? (
                <div className="inline-note">
                  Weather forecast includes fallback data: {weatherSources.map(weatherSourceLabel).join(', ')}.
                </div>
              ) : null}

              {/* Month navigation */}
              <div className="month-nav">
                <Button variant="ghost" onClick={() => setCurrentMonth((m) => shiftMonth(m, -1))}>‹</Button>
                <span className="month-title">{formatMonthTitle(currentMonth)}</span>
                <Button variant="ghost" onClick={() => setCurrentMonth((m) => shiftMonth(m, 1))}>›</Button>
              </div>

              {/* Weekday headers */}
              <div className="month-weekdays">
                {WEEKDAYS.map((d) => (
                  <span key={d} className="month-day-label">{d}</span>
                ))}
              </div>

              {/* Day cells */}
              <div className="month-days">
                {monthDays.map((day, i) => {
                  if (!day) {
                    return <div key={`pad-${i}`} className="month-day month-day-empty" />
                  }
                  const hasTasks = availableDates.includes(day)
                  const hasShortage = detailState.data?.day_resource_summary?.[day]?.status === 'shortage'
                  const isSelected = day === selectedDate
                  const isToday = day === TODAY
                  return (
                    <button
                      key={day}
                      type="button"
                      className={`month-day ${isSelected ? 'is-selected' : ''} ${isToday ? 'is-today' : ''} ${hasShortage ? 'has-shortage' : ''}`}
                      onClick={() => handleDayClick(day)}
                      title={hasTasks ? `${day} — has tasks` : day}
                    >
                      <span className="month-day-num">{day.slice(8)}</span>
                      {hasTasks ? (
                        <span className="day-dots">
                          <span className="day-dot" />
                          {hasShortage ? <span className="day-dot" style={{ background: 'var(--danger)' }} /> : null}
                        </span>
                      ) : null}
                    </button>
                  )
                })}
              </div>

              <p className="muted" style={{ fontSize: '0.82rem', margin: 0 }}>
                Orange dots mark days with scheduled tasks. Click any day to view details.
              </p>
            </section>
          ) : null}
        </section>
      </div>

      {/* ── Day detail modal ── */}
      {dayModalOpen ? (
        <div className="day-modal-overlay" onClick={closeDayModal}>
          <div className="day-modal-panel" onClick={(e) => e.stopPropagation()}>
            {/* Header */}
            <div className="day-modal-header">
              <div>
                <p className="day-modal-title">{selectedDate ? formatDate(selectedDate) : '—'}</p>
                <span className="muted" style={{ fontSize: '0.85rem' }}>
                  {tasksState.loading ? 'Loading...' : `${tasksState.data.length} action${tasksState.data.length !== 1 ? 's' : ''}`}
                </span>
              </div>
              <button type="button" className="day-modal-close" onClick={closeDayModal} aria-label="Close">✕</button>
            </div>

            {/* Weather */}
            {selectedForecast ? (
              <div>
                <p style={{ margin: '0 0 0.6rem', fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-faint)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                  Weather
                </p>
                {selectedForecast.source && selectedForecast.source !== 'api' ? (
                  <div className="inline-note" style={{ marginBottom: '0.75rem' }}>
                    Source: {weatherSourceLabel(selectedForecast.source)}
                    {selectedForecast.source_date ? ` · based on ${formatDate(selectedForecast.source_date)}` : ''}
                    {selectedForecast.source_city ? ` · ${selectedForecast.source_city}` : ''}
                  </div>
                ) : null}
                <div className="day-modal-weather">
                  <div className="weather-mini-stat">
                    <span>Low</span>
                    <span>{safeNumber(selectedForecast.temp_min ?? selectedForecast.temperature, 1)} °C</span>
                  </div>
                  <div className="weather-mini-stat">
                    <span>High</span>
                    <span>{safeNumber(selectedForecast.temp_max ?? selectedForecast.temperature, 1)} °C</span>
                  </div>
                  <div className="weather-mini-stat">
                    <span>Rain</span>
                    <span>{safeNumber(selectedForecast.precipitation, 1)} mm</span>
                  </div>
                  <div className="weather-mini-stat">
                    <span>Wind</span>
                    <span>{safeNumber(selectedForecast.wind_kmh ?? 0, 1)} km/h</span>
                  </div>
                </div>
              </div>
            ) : null}

            {selectedDaySummary ? (
              <div className="page-stack">
                <p style={{ margin: '0 0 0.4rem', fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-faint)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                  Day resources
                </p>
                <div className="inline-note" style={selectedDaySummary.status === 'shortage' ? { color: 'var(--danger)' } : undefined}>
                  {selectedDaySummary.status === 'shortage'
                    ? `Resources are short for ${selectedDaySummary.shortage_count} grouped requirement${selectedDaySummary.shortage_count !== 1 ? 's' : ''}.`
                    : selectedDaySummary.resource_count > 0
                      ? 'All grouped resources for this day are currently covered.'
                      : 'No inventory-backed resources are required for this day.'}
                </div>
                {(selectedDaySummary.resources ?? []).map((resource) => (
                  <div key={`${selectedDate}-${resource.resource_key}`} className="meta-cluster" style={{ justifyContent: 'space-between', alignItems: 'flex-start' }}>
                    <span>
                      {resource.resource_name} · need {safeNumber(resource.required_quantity, resource.inventory_item_type === 'tool' ? 0 : 2)} {formatInventoryUnit(resource.unit)}
                    </span>
                    <span style={resource.shortage_quantity > 0 ? { color: 'var(--danger)' } : undefined}>
                      {resource.consumption_mode === 'consumable' ? 'Consumable' : 'Reusable'}
                      {' · '}have {safeNumber(resource.available_quantity, resource.inventory_item_type === 'tool' ? 0 : 2)}
                      {resource.shortage_quantity > 0
                        ? ` · shortage ${safeNumber(resource.shortage_quantity, resource.inventory_item_type === 'tool' ? 0 : 2)}`
                        : ''}
                    </span>
                  </div>
                ))}
                {(selectedDaySummary.buy_tasks ?? []).length > 0 ? (
                  <div className="page-stack" style={{ gap: '0.35rem' }}>
                    <span className="muted">Generated replenishment tasks:</span>
                    {(selectedDaySummary.buy_tasks ?? []).map((task) => (
                      <span key={`buy-summary-${task.id}`} className="muted">
                        {task.name} · {safeNumber(task.item_quantity, 2)} {task.item ?? ''}
                      </span>
                    ))}
                  </div>
                ) : null}
              </div>
            ) : null}

            {/* Tasks */}
            {tasksState.loading ? <LoadingState title="Loading actions..." /> : null}
            {tasksState.error ? <ErrorState error={tasksState.error} onRetry={tasksState.reload} /> : null}

            {!tasksState.loading && !tasksState.error && tasksState.data.length === 0 ? (
              <EmptyState title="No actions for this day" description="Try another date or clear the current filters." />
            ) : null}

            {!tasksState.loading && !tasksState.error ? (
              <div className="task-groups">
                {error ? <span className="field-error">{error}</span> : null}
                {tasksState.data.map((task) => (
                  (() => {
                    const missingResources = (task.required_resources ?? []).filter((resource) => resource.is_shortage)
                    const hasInventoryShortage = task.status === 'pending' && missingResources.length > 0

                    return (
                      <article key={task.id} className="task-item panel" style={{ gap: '0.75rem' }}>
                    <div className="task-item-head">
                      <div className="stack">
                        <strong>{task.name}</strong>
                        <span className="muted">
                          {task.plant_name || 'Plot-level'} · {task.zone_name || 'No zone'}
                        </span>
                      </div>
                      <div className="meta-cluster">
                        <Badge tone={priorityTone(task.priority)}>{task.priority || 'medium'}</Badge>
                        <Badge tone={statusTone(task.status)}>{task.status}</Badge>
                      </div>
                    </div>

                    <div className="meta-cluster">
                      <span>{task.type || 'General'}</span>
                      {task.item ? <span>{task.item}</span> : null}
                      {task.item_quantity ? <span>× {safeNumber(task.item_quantity, task.type === 'buy' ? 2 : 2)}</span> : null}
                      {task.simulated_state?.state_label ? <span>{task.simulated_state.state_label}</span> : null}
                    </div>

                    {task.reason ? <div className="inline-note">{task.reason}</div> : null}
                    {task.comment ? <span className="muted">{task.comment}</span> : null}

                    {task.inventory_context ? (
                      <div className="meta-cluster" style={{ fontSize: '0.85rem' }}>
                        <span>Inventory {task.inventory_context.status}</span>
                        {task.inventory_context.shortage_count > 0
                          ? <span style={{ color: 'var(--danger)' }}>Shortages {task.inventory_context.shortage_count}</span>
                          : null}
                        {(task.inventory_context.buy_task_ids ?? []).length > 0
                          ? <span>Replenishment task linked</span>
                          : null}
                        {(task.inventory_context.open_buy_task_ids ?? []).length > 0
                          ? <span>Open purchase task already exists</span>
                          : null}
                      </div>
                    ) : null}

                    {(task.required_resources ?? []).length > 0 ? (
                      <div className="page-stack">
                        <div className="inline-note" style={{ fontSize: '0.85rem' }}>
                          Completing this task will deduct consumables and only verify reusable resources without deducting them.
                        </div>
                        {(task.required_resources ?? []).map((resource) => (
                          <div key={`${task.id}-${resource.id}`} className="meta-cluster" style={{ justifyContent: 'space-between' }}>
                            <span>
                              {resource.name} · need {safeNumber(resource.required_quantity, resource.type === 'tool' ? 0 : 2)} {formatInventoryUnit(resource.unit)}
                            </span>
                            <span>
                              {resource.consumption_mode === 'consumable' ? 'Consumable' : 'Reusable'}
                              {resource.available_quantity !== null && resource.available_quantity !== undefined
                                ? ` · have ${safeNumber(resource.available_quantity, resource.type === 'tool' ? 0 : 2)}`
                                : ''}
                              {resource.is_shortage
                                ? ` · shortage ${safeNumber(resource.shortage_quantity, resource.type === 'tool' ? 0 : 2)}`
                                : ''}
                            </span>
                          </div>
                        ))}
                      </div>
                    ) : null}

                    {hasInventoryShortage ? (
                      <div className="page-stack">
                        <div className="inline-note" style={{ color: 'var(--danger)' }}>
                          Task completion is blocked until the missing inventory is replenished.
                        </div>
                        <div className="page-stack" style={{ gap: '0.35rem' }}>
                          {missingResources.map((resource) => (
                            <span key={`${task.id}-missing-${resource.id}`} className="muted">
                              Missing {resource.name}: {safeNumber(resource.shortage_quantity, resource.type === 'tool' ? 0 : 2)} {formatInventoryUnit(resource.unit)}
                            </span>
                          ))}
                        </div>
                        <Link
                          to={buildInventoryLink(task, {
                            plotId,
                            calendarId: selectedCalendarId,
                            date: selectedDate,
                          })}
                        >
                          <Button variant="secondary">Go to inventory</Button>
                        </Link>
                      </div>
                    ) : null}

                    {canEdit && task.status === 'pending' && task.type === 'buy' ? (
                      <div className="inline-note" style={{ fontSize: '0.85rem' }}>
                        This is a replenishment reminder. After purchase, update the inventory entry and then mark this task as completed.
                      </div>
                    ) : null}

                    {canEdit && task.status === 'pending' && task.type === 'harvest' && task.plant_id ? (
                      <Link to={`/plots/${plotId}/harvests?plantId=${task.plant_id}&taskId=${task.id}&date=${task.date || ''}`}>
                        <Button variant="secondary">Register harvest</Button>
                      </Link>
                    ) : null}

                    {canEdit && task.status === 'pending' ? (
                      <div className="row-actions">
                        <Button
                          onClick={() => handleTaskAction(task.id, 'complete')}
                          disabled={submitting || hasInventoryShortage || task.can_complete === false}
                        >
                          Complete
                        </Button>
                        <Button variant="danger" onClick={() => handleTaskAction(task.id, 'reject')} disabled={submitting}>
                          Reject
                        </Button>
                      </div>
                    ) : null}
                      </article>
                    )
                  })()
                ))}
              </div>
            ) : null}
          </div>
        </div>
      ) : null}
    </div>
  )
}
