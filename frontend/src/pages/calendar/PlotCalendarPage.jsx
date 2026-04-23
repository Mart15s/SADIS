import { startTransition, useEffect, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import {
  EmptyState,
  ErrorState,
  LoadingState,
  ProcessingState,
  SuccessToast,
} from '../../components/shared/StatusView.jsx'
import ActionRow from '../../components/ui/ActionRow.jsx'
import Button from '../../components/ui/Button.jsx'
import FormSection from '../../components/ui/FormSection.jsx'
import SectionCard from '../../components/ui/SectionCard.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
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

function formatStatusLabel(status) {
  if (!status) return 'pending'
  return status.replace(/_/g, ' ')
}

function formatPriorityLabel(priority) {
  if (!priority) return 'medium'
  return priority.replace(/_/g, ' ')
}

function getDayTone(taskCount, dayStatus) {
  if (dayStatus === 'blocked') return 'blocked'
  if (dayStatus === 'partially_blocked') return 'warning'
  if (taskCount >= 4) return 'busy'
  if (taskCount >= 1) return 'active'
  return 'empty'
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
  const shortages = (task.inventory_shortages ?? task.required_resources ?? [])
    .filter((resource) => resource.is_shortage ?? resource.shortage_quantity > 0)
    .map((resource) => ({
      id: resource.id ?? resource.requirement_id ?? resource.resource_key ?? resource.name,
      name: resource.name ?? resource.resource_name,
      type: resource.type,
      unit: resource.unit,
      required_quantity: resource.required_quantity,
      available_quantity: resource.available_quantity,
      shortage_quantity: resource.shortage_quantity,
      consumption_mode: resource.resource_mode ?? resource.consumption_mode,
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

function taskInventoryLabel(mode) {
  if (mode === 'available') return 'Inventory available'
  if (mode === 'shortage') return 'Inventory shortage'
  if (mode === 'replenishment') return 'Replenishment reminder'
  return 'Inventory not required'
}

function summarizeInventoryContext(task, isReplenishmentTask) {
  if (!task.inventory_context) {
    return null
  }

  if (task.inventory_mode === 'shortage' && task.inventory_context.shortage_count > 0) {
    return `${task.inventory_context.shortage_count} shortage${task.inventory_context.shortage_count === 1 ? '' : 's'} detected`
  }

  if (!isReplenishmentTask && (task.inventory_context.buy_task_ids ?? []).length > 0) {
    return 'Linked replenishment task exists'
  }

  if ((task.inventory_context.open_buy_task_ids ?? []).length > 0) {
    return 'Open purchase task already exists'
  }

  if (task.inventory_mode === 'available') {
    return 'Required inventory is available'
  }

  return taskInventoryLabel(task.inventory_mode)
}

function describeTaskFocus(task, missingResources, isReplenishmentTask) {
  const firstMissing = missingResources[0]
  const firstMissingLabel = firstMissing
    ? `${firstMissing.name ?? firstMissing.resource_name}: ${safeNumber(firstMissing.shortage_quantity, firstMissing.type === 'tool' ? 0 : 2)} ${formatInventoryUnit(firstMissing.unit)} short`
    : null

  if (task.status === 'completed') {
    return {
      tone: 'success',
      label: 'Completed',
      detail: task.comment || 'This action has already been completed.',
    }
  }

  if (task.status === 'canceled' || task.status === 'cancelled') {
    return {
      tone: 'danger',
      label: 'Cancelled',
      detail: task.comment || 'This action was cancelled and no longer needs work.',
    }
  }

  if (isReplenishmentTask) {
    return {
      tone: firstMissing ? 'warning' : 'soft',
      label: 'Restock reminder',
      detail: firstMissingLabel
        ? `This replenishment reminder does not consume inventory. ${firstMissingLabel}. Update stock, then mark this reminder complete.`
        : 'This replenishment reminder does not consume inventory. Update stock, then mark this reminder complete.',
    }
  }

  if (task.status === 'pending' && firstMissing) {
    return {
      tone: 'danger',
      label: 'Blocked by shortage',
      detail: `Task completion is blocked until inventory is replenished. ${firstMissingLabel}`,
    }
  }

  if (task.workflow_context?.kind === 'lifecycle_review' && task.plant_id) {
    return {
      tone: 'warning',
      label: 'Needs plant review',
      detail: 'Open the plant record to confirm the lifecycle state before completing this task.',
    }
  }

  if (task.type === 'harvest' && task.plant_id) {
    return {
      tone: 'warning',
      label: 'Harvest recording required',
      detail: 'Register the harvested output from the linked plant workflow.',
    }
  }

  if (task.actual_condition && task.actual_condition !== 'healthy') {
    return {
      tone: 'warning',
      label: `Condition: ${task.actual_condition}`,
      detail: task.reason || 'This task is reacting to the plant condition detected for this day.',
    }
  }

  if (task.reason) {
    return {
      tone: 'soft',
      label: 'Scheduled by rule',
      detail: task.reason,
    }
  }

  if (task.comment) {
    return {
      tone: 'neutral',
      label: 'Operator note',
      detail: task.comment,
    }
  }

  return {
    tone: task.inventory_mode === 'available' ? 'success' : 'neutral',
    label: taskInventoryLabel(task.inventory_mode),
    detail: summarizeInventoryContext(task, isReplenishmentTask) || 'Ready for action.',
  }
}

function resourceTypeLabel(resource) {
  if (resource.resource_type_label) return resource.resource_type_label
  return (resource.resource_mode ?? resource.consumption_mode) === 'consumable' ? 'Consumable' : 'Reusable'
}

function weatherSourceLabel(source) {
  if (source === 'api') return 'Live Meteo.lt'
  if (source === 'stored_city_date') return 'Stored forecast (same city/date)'
  if (source === 'stored_other_city_date') return 'Stored forecast (same date fallback)'
  if (source === 'seasonal') return 'Seasonal fallback'
  if (source === 'legacy_unknown') return 'Legacy forecast data'
  return 'Fallback forecast'
}

function getMonthDays(yearMonth) {
  const [year, month] = yearMonth.split('-').map(Number)
  const firstDay = new Date(year, month - 1, 1)
  const lastDay = new Date(year, month, 0)
  let startPad = firstDay.getDay() - 1
  if (startPad < 0) startPad = 6
  const days = []
  for (let i = 0; i < startPad; i += 1) days.push(null)
  for (let day = 1; day <= lastDay.getDate(); day += 1) {
    days.push(`${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`)
  }
  return days
}

function shiftMonth(yearMonth, delta) {
  const [year, month] = yearMonth.split('-').map(Number)
  const date = new Date(year, month - 1 + delta, 1)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

function formatMonthTitle(yearMonth) {
  const [year, month] = yearMonth.split('-').map(Number)
  return new Date(year, month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' })
}

const TODAY = new Date().toISOString().slice(0, 10)
const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

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
  const selectedForecast = detailState.data?.weather?.find((forecast) => forecast.date === selectedDate) ?? null
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
        description="Plan a window, generate a recommendation calendar, and open any day for a cleaner operational task view."
        meta={selectedCalendarId ? <StatusBadge kind="selection">Calendar #{selectedCalendarId}</StatusBadge> : null}
        actions={(
          <Link to={`/plots/${plotId}`}>
            <Button variant="secondary">Back to plot</Button>
          </Link>
        )}
      />

      <SuccessToast message={toastMessage} onDismiss={() => setToastMessage('')} />

      <div className="calendar-layout">
        <aside className="page-stack calendar-sidebar">
          {canEdit ? (
            <form onSubmit={handleGenerate}>
              <FormSection
                title="Generate calendar"
                description="Set the planning window for this plot. Weather, plant care, and inventory coverage will be combined server-side into daily work."
                className="calendar-rail-card calendar-generator-card"
              >
                <div className="calendar-generator-highlights">
                  <span className="calendar-generator-highlight">Meteo.lt forecast rules</span>
                  <span className="calendar-generator-highlight">Plant care intervals</span>
                  <span className="calendar-generator-highlight">Inventory coverage check</span>
                </div>

                <div className="calendar-generator-fields">
                  <div className="field">
                    <label htmlFor="calendar-start">Start date</label>
                    <input
                      id="calendar-start"
                      type="date"
                      value={generateForm.start_date}
                      onChange={(event) => setGenerateForm((current) => ({ ...current, start_date: event.target.value }))}
                      required
                    />
                  </div>
                  <div className="field">
                    <label htmlFor="calendar-end">End date</label>
                    <input
                      id="calendar-end"
                      type="date"
                      value={generateForm.end_date}
                      onChange={(event) => setGenerateForm((current) => ({ ...current, end_date: event.target.value }))}
                      required
                    />
                  </div>
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

                <ActionRow>
                  <Button type="submit" loading={submitting}>
                    {submitting ? 'Generating calendar' : 'Generate'}
                  </Button>
                </ActionRow>
              </FormSection>
            </form>
          ) : null}

          <SectionCard
            title="Generated calendars"
            description="Switch between recommendation runs without losing your current month view."
            className="calendar-rail-card calendar-list-card"
            actions={<StatusBadge kind="selection" tone="neutral">{pageState.data.calendars.length}</StatusBadge>}
          >
            {pageState.data.calendars.length === 0 ? (
              <div className="calendar-list-empty">
                <strong>No calendars yet</strong>
                <p className="muted">Generate the first recommendation calendar to unlock the month grid and daily drawer.</p>
              </div>
            ) : (
              <div className="stack stack-sm">
                {pageState.data.calendars.map((calendar) => (
                  <button
                    key={calendar.id}
                    type="button"
                    className={`calendar-choice-card ${String(selectedCalendarId) === String(calendar.id) ? 'is-selected' : ''}`.trim()}
                    onClick={() => { startTransition(() => { setSelectedCalendarId(calendar.id) }) }}
                  >
                    <div className="calendar-choice-copy">
                      <h3>Calendar #{calendar.id}</h3>
                      <span className="muted">{formatDate(calendar.start_date)} to {formatDate(calendar.end_date)}</span>
                    </div>
                    <StatusBadge kind="selection" tone="neutral">{calendar.tasks_count ?? 0} tasks</StatusBadge>
                  </button>
                ))}
              </div>
            )}
          </SectionCard>

          {detailState.data ? (
            <SectionCard
              title="Task filters"
              description="Focus the drawer on one plant or zone when you want a tighter daily review."
              className="calendar-rail-card"
              compact
            >
              <div className="field">
                <label htmlFor="calendar-plant-filter">Plant</label>
                <select
                  id="calendar-plant-filter"
                  value={filters.plant_id}
                  onChange={(event) => setFilters((current) => ({ ...current, plant_id: event.target.value }))}
                >
                  <option value="">All plants</option>
                  {plantOptions.map((plant) => <option key={plant.id} value={plant.id}>{plant.name}</option>)}
                </select>
              </div>
              <div className="field">
                <label htmlFor="calendar-zone-filter">Zone</label>
                <select
                  id="calendar-zone-filter"
                  value={filters.zone_id}
                  onChange={(event) => setFilters((current) => ({ ...current, zone_id: event.target.value }))}
                >
                  <option value="">All zones</option>
                  {zoneOptions.map((zone) => <option key={zone.id} value={zone.id}>{zone.name}</option>)}
                </select>
              </div>
            </SectionCard>
          ) : null}
        </aside>

        <section className="page-stack">
          {detailState.loading ? <LoadingState title="Loading calendar..." /> : null}
          {detailState.error ? <ErrorState error={detailState.error} onRetry={detailState.reload} /> : null}

          {!detailState.loading && !detailState.data ? (
            <SectionCard
              title="Planning workspace"
              description="The month grid is ready as soon as a calendar exists, and the daily drawer stays focused on weather, shortages, and actions."
              className="calendar-empty-workspace"
            >
              <div className="calendar-empty-guide">
                <article className="calendar-empty-step">
                  <span className="calendar-empty-step-index">1</span>
                  <div className="calendar-empty-step-copy">
                    <strong>Choose the planning window</strong>
                    <p>Pick the date range from the planning rail so the generator uses the right weather period.</p>
                  </div>
                </article>
                <article className="calendar-empty-step">
                  <span className="calendar-empty-step-index">2</span>
                  <div className="calendar-empty-step-copy">
                    <strong>Generate the recommendation run</strong>
                    <p>The backend schedules work from plant care, forecast data, and current plot state.</p>
                  </div>
                </article>
                <article className="calendar-empty-step">
                  <span className="calendar-empty-step-index">3</span>
                  <div className="calendar-empty-step-copy">
                    <strong>Open a day for operational detail</strong>
                    <p>Review the most important blocker or condition first, then complete, reject, or route to inventory.</p>
                  </div>
                </article>
              </div>

              <div className="calendar-empty-preview">
                <span className="calendar-empty-preview-label">What appears after generation</span>
                <div className="calendar-empty-preview-bars">
                  <span className="calendar-empty-preview-bar calendar-empty-preview-bar-soft" />
                  <span className="calendar-empty-preview-bar calendar-empty-preview-bar-brand" />
                  <span className="calendar-empty-preview-bar calendar-empty-preview-bar-warning" />
                </div>
                <p className="muted">Workload bars stay visible in each day cell so busy and blocked dates are immediately obvious.</p>
              </div>
            </SectionCard>
          ) : null}

          {!detailState.loading && detailState.data ? (
            <SectionCard
              title="Month view"
              description="Day cells keep the improved workload bar and status clarity, while the surrounding planning experience stays quieter and more focused."
            >
              {usingWeatherFallback ? (
                <div className="inline-note">
                  Weather forecast includes fallback data: {weatherSources.map(weatherSourceLabel).join(', ')}.
                </div>
              ) : null}

              <div className="month-nav">
                <Button variant="ghost" size="sm" onClick={() => setCurrentMonth((month) => shiftMonth(month, -1))}>Prev</Button>
                <span className="month-title">{formatMonthTitle(currentMonth)}</span>
                <Button variant="ghost" size="sm" onClick={() => setCurrentMonth((month) => shiftMonth(month, 1))}>Next</Button>
              </div>

              <div className="month-weekdays">
                {WEEKDAYS.map((weekday) => (
                  <span key={weekday} className="month-day-label">{weekday}</span>
                ))}
              </div>

              <div className="month-days">
                {monthDays.map((day, index) => {
                  if (!day) {
                    return <div key={`pad-${index}`} className="month-day month-day-empty" />
                  }

                  const dayTasks = detailState.data?.tasks_by_date?.[day] ?? []
                  const taskCount = dayTasks.length
                  const dayStatus = detailState.data?.day_resource_summary?.[day]?.day_inventory_status
                    ?? detailState.data?.day_resource_summary?.[day]?.status
                    ?? null
                  const hasTasks = availableDates.includes(day)
                  const isSelected = day === selectedDate
                  const isToday = day === TODAY
                  const tone = getDayTone(taskCount, dayStatus)
                  const workloadLabel = dayStatus === 'blocked'
                    ? 'Blocked'
                    : dayStatus === 'partially_blocked'
                      ? 'Shortage'
                      : taskCount >= 4
                        ? 'Busy'
                        : taskCount >= 1
                          ? 'Planned'
                          : 'Open'

                  return (
                    <button
                      key={day}
                      type="button"
                      aria-label={day.slice(8)}
                      className={`month-day month-day-${tone} ${isSelected ? 'is-selected' : ''} ${isToday ? 'is-today' : ''}`.trim()}
                      onClick={() => handleDayClick(day)}
                      title={hasTasks ? `${day} has ${taskCount} tasks` : day}
                    >
                      <span className="month-day-num">{day.slice(8)}</span>
                      <span className="month-day-state">{workloadLabel}</span>
                      <span className="month-day-tasks">{taskCount ? `${taskCount} task${taskCount === 1 ? '' : 's'}` : 'No tasks'}</span>
                      <span className="month-day-load" aria-hidden="true">
                        <span
                          className={`month-day-load-bar month-day-load-${tone}`.trim()}
                          style={{ width: `${taskCount ? Math.min(100, Math.max(24, taskCount * 22)) : 18}%` }}
                        />
                      </span>
                    </button>
                  )
                })}
              </div>

              <p className="muted calendar-footnote">
                Selected day state, workload, and shortage pressure stay visible directly in the month grid.
              </p>
            </SectionCard>
          ) : null}
        </section>
      </div>

      {dayModalOpen ? (
        <div className="day-modal-overlay" onClick={closeDayModal}>
          <div className="day-modal-panel" onClick={(event) => event.stopPropagation()}>
            <div className="day-modal-header">
              <div>
                <p className="day-modal-title">{selectedDate ? formatDate(selectedDate) : '--'}</p>
                <span className="muted day-modal-subtitle">
                  {tasksState.loading ? 'Loading...' : `${tasksState.data.length} action${tasksState.data.length !== 1 ? 's' : ''}`}
                </span>
              </div>
              <button type="button" className="day-modal-close" onClick={closeDayModal} aria-label="Close">x</button>
            </div>

            {selectedForecast ? (
              <section className="day-drawer-section">
                <p className="day-drawer-label">Weather</p>
                {selectedForecast.source && selectedForecast.source !== 'api' ? (
                  <div className="inline-note day-drawer-note">
                    Source: {weatherSourceLabel(selectedForecast.source)}
                    {selectedForecast.source_date ? ` - based on ${formatDate(selectedForecast.source_date)}` : ''}
                    {selectedForecast.source_city ? ` - ${selectedForecast.source_city}` : ''}
                  </div>
                ) : null}
                <div className="day-modal-weather">
                  <div className="weather-mini-stat">
                    <span>Low</span>
                    <span>{safeNumber(selectedForecast.temp_min ?? selectedForecast.temperature, 1)} C</span>
                  </div>
                  <div className="weather-mini-stat">
                    <span>High</span>
                    <span>{safeNumber(selectedForecast.temp_max ?? selectedForecast.temperature, 1)} C</span>
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
              </section>
            ) : null}

            {selectedDaySummary ? (
              <section className="day-drawer-section page-stack">
                <p className="day-drawer-label">Day resources</p>
                <div
                  className="inline-note"
                  style={['partially_blocked', 'blocked'].includes(selectedDaySummary.day_inventory_status) ? { color: 'var(--danger)' } : undefined}
                >
                  {selectedDaySummary.summary_text
                    ?? (selectedDaySummary.day_inventory_status === 'fully_covered'
                      ? 'Inventory is fully covered for planned work on this day.'
                      : 'Planned work is blocked by inventory shortages.')}
                </div>
                {(selectedDaySummary.grouped_resource_summary ?? selectedDaySummary.resources ?? []).map((resource) => (
                  <div key={`${selectedDate}-${resource.resource_key}`} className="meta-cluster resource-summary-row">
                    <span>
                      {resource.resource_name} - need {safeNumber(resource.required_quantity, resource.inventory_item_type === 'tool' ? 0 : 2)} {formatInventoryUnit(resource.unit)}
                    </span>
                    <span style={resource.shortage_quantity > 0 ? { color: 'var(--danger)' } : undefined}>
                      {resourceTypeLabel(resource)}
                      {' - '}have {safeNumber(resource.available_quantity, resource.inventory_item_type === 'tool' ? 0 : 2)}
                      {resource.shortage_quantity > 0
                        ? ` - shortage ${safeNumber(resource.shortage_quantity, resource.inventory_item_type === 'tool' ? 0 : 2)}`
                        : ''}
                    </span>
                  </div>
                ))}
                {(selectedDaySummary.replenishment_tasks ?? selectedDaySummary.buy_tasks ?? []).length > 0 ? (
                  <div className="stack stack-sm">
                    <span className="muted">Generated replenishment tasks:</span>
                    {(selectedDaySummary.replenishment_tasks ?? selectedDaySummary.buy_tasks ?? []).map((task) => (
                      <span key={`buy-summary-${task.id}`} className="muted">
                        {task.name} - {safeNumber(task.item_quantity, 2)} {task.item ?? ''}
                      </span>
                    ))}
                  </div>
                ) : null}
              </section>
            ) : null}

            {tasksState.loading ? <LoadingState title="Loading actions..." /> : null}
            {tasksState.error ? <ErrorState error={tasksState.error} onRetry={tasksState.reload} /> : null}

            {!tasksState.loading && !tasksState.error && tasksState.data.length === 0 ? (
              <EmptyState title="No actions for this day" description="Try another date or clear the current filters." />
            ) : null}

            {!tasksState.loading && !tasksState.error ? (
              <div className="task-groups">
                {error ? <span className="field-error">{error}</span> : null}
                {tasksState.data.map((task) => {
                  const missingResources = (task.inventory_shortages ?? task.required_resources ?? [])
                    .filter((resource) => resource.is_shortage ?? resource.shortage_quantity > 0)
                  const isReplenishmentTask = task.is_replenishment_task || task.inventory_mode === 'replenishment' || task.type === 'buy'
                  const hasInventoryShortage = task.status === 'pending' && !isReplenishmentTask && missingResources.length > 0
                  const taskFocus = describeTaskFocus(task, missingResources, isReplenishmentTask)
                  const inventorySummary = summarizeInventoryContext(task, isReplenishmentTask)
                  const resourceRequirements = task.resource_requirements ?? task.required_resources ?? []
                  const quickFacts = [
                    task.actual_condition ? `Actual ${task.actual_condition}` : null,
                    task.simulated_phase ? `Expected ${task.simulated_phase}` : null,
                    task.lifecycle_transition?.is_transition_day
                      ? `${task.lifecycle_transition.from} -> ${task.lifecycle_transition.to}`
                      : null,
                    ...(
                      isReplenishmentTask
                        ? missingResources.map((resource) => `Missing ${safeNumber(resource.shortage_quantity, 2)} ${formatInventoryUnit(resource.unit)} of ${resource.name ?? resource.resource_name}${resource.blocked_task_count ? ` for ${resource.blocked_task_count} blocked task${resource.blocked_task_count === 1 ? '' : 's'}` : ''}`)
                        : []
                    ),
                  ].filter(Boolean)
                  const taskDetails = [
                    task.reason && taskFocus.detail !== task.reason ? { label: 'Scheduling rule', value: task.reason } : null,
                    task.comment && taskFocus.detail !== task.comment ? { label: 'Operator note', value: task.comment } : null,
                    task.type ? { label: 'Task type', value: task.type } : null,
                    !isReplenishmentTask && task.item
                      ? { label: 'Material', value: task.item_quantity ? `${task.item} x ${safeNumber(task.item_quantity, 2)}` : task.item }
                      : null,
                    inventorySummary ? { label: 'Inventory context', value: inventorySummary } : null,
                  ].filter(Boolean)

                  return (
                    <article key={task.id} className="task-item day-task-card">
                      <div className="day-task-card-head">
                        <div className="day-task-card-title-block">
                          <strong className="day-task-card-title">{task.name}</strong>
                          <span className="day-task-card-context">
                            {task.plant_name || 'Plot-level'} - {task.zone_name || 'No zone'}
                          </span>
                        </div>
                        <div className="day-task-card-badges">
                          <StatusBadge kind="status" tone={statusTone(task.status)}>{formatStatusLabel(task.status)}</StatusBadge>
                          <StatusBadge kind="severity" tone={priorityTone(task.priority)}>{formatPriorityLabel(task.priority)}</StatusBadge>
                        </div>
                      </div>

                      <div className={`task-focus-banner task-focus-banner-${taskFocus.tone}`.trim()}>
                        <span className="task-focus-label">{taskFocus.label}</span>
                        <p>{taskFocus.detail}</p>
                      </div>

                      {quickFacts.length > 0 ? (
                        <div className="day-task-card-facts">
                          {quickFacts.map((fact) => (
                            <span key={`${task.id}-${fact}`} className="day-task-card-fact">{fact}</span>
                          ))}
                        </div>
                      ) : null}

                      <div className="day-task-card-actions">
                        {hasInventoryShortage ? (
                          <Link
                            to={buildInventoryLink(task, {
                              plotId,
                              calendarId: selectedCalendarId,
                              date: selectedDate,
                            })}
                          >
                            <Button variant="secondary">Go to inventory</Button>
                          </Link>
                        ) : null}

                        {canEdit && task.status === 'pending' && task.workflow_context?.kind === 'lifecycle_review' && task.plant_id ? (
                          <Link
                            to={`/plots/${plotId}/plants/${task.plant_id}`}
                            state={{
                              pendingReviewTask: task,
                              backTo: buildCalendarReturnPath(plotId, selectedCalendarId, selectedDate),
                              backLabel: selectedDate ? `Back to ${formatDate(selectedDate)}` : 'Back to calendar',
                            }}
                          >
                            <Button variant="secondary">Open plant review</Button>
                          </Link>
                        ) : null}

                        {canEdit && task.status === 'pending' && task.type === 'harvest' && task.plant_id ? (
                          <Link to={`/plots/${plotId}/harvests?plantId=${task.plant_id}&taskId=${task.id}&date=${task.date || ''}`}>
                            <Button variant="secondary">Register harvest</Button>
                          </Link>
                        ) : null}

                        {canEdit && task.status === 'pending' && task.workflow_context?.kind !== 'lifecycle_review' && task.type !== 'harvest' ? (
                          <ActionRow>
                            <Button
                              onClick={() => handleTaskAction(task.id, 'complete')}
                              disabled={submitting || hasInventoryShortage || task.can_complete === false}
                            >
                              Complete
                            </Button>
                            <Button variant="danger" onClick={() => handleTaskAction(task.id, 'reject')} disabled={submitting}>
                              Reject
                            </Button>
                          </ActionRow>
                        ) : null}
                      </div>

                      {(taskDetails.length > 0 || resourceRequirements.length > 0 || missingResources.length > 0) ? (
                        <details className="task-card-details">
                          <summary>Details</summary>
                          <div className="task-card-detail-stack">
                            {taskDetails.length > 0 ? (
                              <div className="task-card-detail-list">
                                {taskDetails.map((detail) => (
                                  <div key={`${task.id}-${detail.label}`} className="task-card-detail-row">
                                    <span>{detail.label}</span>
                                    <strong>{detail.value}</strong>
                                  </div>
                                ))}
                              </div>
                            ) : null}

                            {resourceRequirements.length > 0 ? (
                              <div className="task-card-detail-block">
                                <strong>Resource requirements</strong>
                                <div className="task-card-resource-list">
                                  {resourceRequirements.map((resource) => (
                                    <div key={`${task.id}-${resource.id ?? resource.name}`} className="task-card-resource-row">
                                      <span>{resource.name ?? resource.resource_name}</span>
                                      <span>
                                        Need {safeNumber(resource.required_quantity, resource.type === 'tool' ? 0 : 2)} {formatInventoryUnit(resource.unit)}
                                        {resource.available_quantity !== null && resource.available_quantity !== undefined
                                          ? ` - have ${safeNumber(resource.available_quantity, resource.type === 'tool' ? 0 : 2)}`
                                          : ''}
                                        {resource.is_shortage || resource.shortage_quantity > 0
                                          ? ` - shortage ${safeNumber(resource.shortage_quantity, resource.type === 'tool' ? 0 : 2)}`
                                          : ''}
                                      </span>
                                    </div>
                                  ))}
                                </div>
                              </div>
                            ) : null}

                            {missingResources.length > 0 ? (
                              <div className="task-card-detail-block">
                                <strong>Shortages</strong>
                                <div className="task-card-resource-list">
                                  {missingResources.map((resource) => (
                                    <div key={`${task.id}-missing-${resource.id ?? resource.resource_name}`} className="task-card-resource-row">
                                      <span>{resource.name ?? resource.resource_name}</span>
                                      <span>{safeNumber(resource.shortage_quantity, resource.type === 'tool' ? 0 : 2)} {formatInventoryUnit(resource.unit)} short</span>
                                    </div>
                                  ))}
                                </div>
                              </div>
                            ) : null}
                          </div>
                        </details>
                      ) : null}
                    </article>
                  )
                })}
              </div>
            ) : null}
          </div>
        </div>
      ) : null}
    </div>
  )
}
