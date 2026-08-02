import { startTransition, useEffect, useMemo, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { MapLayerControl, TaskPriorityBadge } from '../../components/garden/GardenControls.jsx'
import PlotSectionNav from '../../components/plot/PlotSectionNav.jsx'
import {
  EmptyState,
  ErrorState,
  LoadingState,
  ProcessingState,
  SuccessToast,
} from '../../components/shared/StatusView.jsx'
import ActionRow from '../../components/ui/ActionRow.jsx'
import Button from '../../components/ui/Button.jsx'
import DestructiveButton from '../../components/ui/DestructiveButton.jsx'
import { DefinitionList, StatRow } from '../../components/ui/DefinitionList.jsx'
import { DialogBody, DialogHeader, Drawer } from '../../components/ui/Dialog.jsx'
import FormField from '../../components/ui/FormField.jsx'
import FormSection from '../../components/ui/FormSection.jsx'
import SectionCard from '../../components/ui/SectionCard.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { api } from '../../lib/api.js'
import {
  formatDate,
  formatDateTime,
  formatInventoryUnit,
  formatMonthYear,
  formatNumberWithUnit,
  formatPlantCondition,
  formatTemperatureC,
  formatTaskStatus,
  formatTaskType,
  safeNumber,
} from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

function flattenCalendarTasks(calendar) {
  return Object.values(calendar?.tasks_by_date ?? {}).flat()
}

function normalizeCalendar(calendar) {
  if (!calendar || typeof calendar !== 'object' || Array.isArray(calendar)) return null

  return {
    ...calendar,
    available_dates: Array.isArray(calendar.available_dates) ? calendar.available_dates : [],
    weather: Array.isArray(calendar.weather) ? calendar.weather : [],
    day_resource_summary: calendar.day_resource_summary && typeof calendar.day_resource_summary === 'object'
      ? calendar.day_resource_summary
      : {},
    tasks_by_date: calendar.tasks_by_date && typeof calendar.tasks_by_date === 'object'
      ? calendar.tasks_by_date
      : {},
  }
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

function statusTone(status) {
  if (status === 'completed') return 'success'
  if (status === 'canceled' || status === 'cancelled') return 'danger'
  return 'warning'
}

function formatStatusLabel(status) {
  return formatTaskStatus(status ?? 'pending')
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
  if (mode === 'available') return 'Inventory ready'
  if (mode === 'shortage') return 'Inventory shortage'
  if (mode === 'replenishment') return 'Replenishment reminder'
  return 'No inventory needed'
}

const VISIBLE_TEXT_TRANSLATIONS = {
  'Steb\u0117ti \u012fsitvirtinim\u0105': 'Monitor establishment',
  'Jaunus augalus \u012fsitvirtinimo metu reikia steb\u0117ti atid\u017eiau.': 'Young plants should be monitored more closely while they become established.',
  'Faktin\u0117 b\u016bkl\u0117': 'Current status',
  'Tik\u0117tina b\u016bkl\u0117': 'Expected status',
  'Detal\u0117s': 'Details',
  'Atlikti': 'Complete',
  'Atnaujinta:': 'Updated:',
  'Orai': 'Weather',
  'Dienos resursai': 'Daily resources',
  'Veiksmai': 'Actions',
}

function translateVisibleText(value) {
  if (value === null || value === undefined) return value

  const directTranslation = VISIBLE_TEXT_TRANSLATIONS[String(value)]

  if (directTranslation) {
    return directTranslation
  }

  return String(value)
    .replace(/\bPapildyta:/g, 'Restocked:')
    .replace(/\bNupirkti:\s*/g, 'Buy ')
    .replace(/\bTr\u0105\u0161os\b/g, 'Fertilizer')
    .replace(/\bApsaugin\u0117 danga\b/g, 'Protective cover')
    .replace(/\bAugal\u0173 atramos\b/g, 'Plant support')
    .replace(/\bvnt\./g, 'unit')
}

function summarizeInventoryContext(task, isReplenishmentTask) {
  if (!task.inventory_context) {
    return null
  }

  if (task.inventory_mode === 'shortage' && task.inventory_context.shortage_count > 0) {
    return `Shortages found: ${task.inventory_context.shortage_count}`
  }

  if (!isReplenishmentTask && (task.inventory_context.buy_task_ids ?? []).length > 0) {
    return 'A linked replenishment task already exists'
  }

  if ((task.inventory_context.open_buy_task_ids ?? []).length > 0) {
    return 'An open purchase task already exists'
  }

  if (task.inventory_mode === 'available') {
    return 'Required inventory is ready'
  }

  return taskInventoryLabel(task.inventory_mode)
}

function describeTaskFocus(task, missingResources, isReplenishmentTask, linkedReplenishmentTask = null) {
  const firstMissing = missingResources[0]
  const firstMissingLabel = firstMissing
    ? `${translateVisibleText(firstMissing.name ?? firstMissing.resource_name)}: missing ${safeNumber(firstMissing.shortage_quantity, firstMissing.type === 'tool' ? 0 : 2)} ${formatInventoryUnit(firstMissing.unit)}`
    : null

  if (task.status === 'completed') {
    return {
      tone: 'success',
      label: 'Completed',
      detail: translateVisibleText(task.comment) || 'This action is already completed.',
    }
  }

  if (task.status === 'canceled' || task.status === 'cancelled') {
    return {
      tone: 'danger',
      label: 'Canceled',
      detail: translateVisibleText(task.comment) || 'This action was canceled and no longer requires work.',
    }
  }

  if (isReplenishmentTask) {
    const blockedTaskCount = firstMissing?.blocked_task_count ?? task.inventory_context?.replenishment?.blocked_task_count ?? 0
    return {
      tone: firstMissing ? 'warning' : 'soft',
      label: 'Replenishment task',
      detail: firstMissingLabel
        ? `Completing this task replenishes inventory. ${firstMissingLabel}${blockedTaskCount ? `, unblocked tasks: ${blockedTaskCount}.` : '.'}`
        : 'Completing this task replenishes inventory and unblocks linked tasks.',
    }
  }

  if (task.status === 'pending' && firstMissing) {
    const dependencyLabel = linkedReplenishmentTask
      ? `"${translateVisibleText(linkedReplenishmentTask.name)}"`
      : 'the linked replenishment task'
    return {
      tone: 'danger',
      label: 'Blocked by shortage',
      detail: `This task is blocked until ${dependencyLabel} is completed. Inventory is replenished there, not here. ${firstMissingLabel}`,
    }
  }

  if (task.workflow_context?.kind === 'lifecycle_review' && task.plant_id) {
    return {
      tone: 'warning',
      label: 'Plant review required',
      detail: 'Open the plant record and confirm the status before completing this task.',
    }
  }

  if (task.type === 'harvest' && task.plant_id) {
    return {
      tone: 'warning',
      label: 'Harvest record required',
      detail: 'Record the harvest in the linked plant workflow.',
    }
  }

  if (task.actual_condition && task.actual_condition !== 'healthy') {
    return {
      tone: 'warning',
      label: `Status: ${formatPlantCondition(task.actual_condition)}`,
      detail: translateVisibleText(task.reason) || 'This task responds to the plant status recorded for this day.',
    }
  }

  if (task.reason) {
    return {
      tone: 'soft',
      label: 'Planned by rule',
      detail: translateVisibleText(task.reason),
    }
  }

  if (task.comment) {
    return {
      tone: 'neutral',
      label: 'User note',
      detail: translateVisibleText(task.comment),
    }
  }

  return {
    tone: task.inventory_mode === 'available' ? 'success' : 'neutral',
    label: taskInventoryLabel(task.inventory_mode),
    detail: summarizeInventoryContext(task, isReplenishmentTask) || 'Ready for action.',
  }
}

function getLinkedReplenishmentTask(task, tasks) {
  const linkedIds = task.inventory_context?.buy_task_ids ?? []

  if (!linkedIds.length) {
    return null
  }

  return tasks.find((candidate) => linkedIds.some((id) => String(id) === String(candidate.id))) ?? null
}

function resourceTypeLabel(resource) {
  if (resource.resource_type_label) return resource.resource_type_label
  return (resource.resource_mode ?? resource.consumption_mode) === 'consumable' ? 'Consumable' : 'Reusable'
}

function weatherSourceLabel(source) {
  if (source === 'api') return 'Direct Meteo.lt forecast'
  if (source === 'stored_city_date') return 'Stored forecast by city and date'
  if (source === 'stored_other_city_date') return 'Stored forecast by date'
  if (source === 'seasonal') return 'Seasonal fallback forecast'
  if (source === 'legacy_unknown') return 'Legacy forecast data'
  return 'Fallback forecast'
}

function weatherSourceNote(forecast) {
  const source = forecast?.source
  const sourceDate = forecast?.source_date ? formatDate(forecast.source_date) : null
  const sourceCity = forecast?.source_city ?? forecast?.city

  if (!source || source === 'api') return ''

  if (source === 'stored_city_date' || source === 'stored_other_city_date') {
    if (sourceDate && sourceCity) {
      return `Source: fallback ${sourceCity} forecast using ${sourceDate} data`
    }

    if (sourceDate) {
      return `Source: fallback forecast using ${sourceDate} data`
    }

    return 'Source: fallback forecast'
  }

  if (source === 'seasonal') {
    return 'Source: seasonal fallback forecast'
  }

  if (source === 'legacy_unknown') {
    return 'Source: legacy forecast data'
  }

  return `Source: ${weatherSourceLabel(source).toLowerCase()}`
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
  return formatMonthYear(new Date(year, month - 1, 1))
}

const TODAY = new Date().toISOString().slice(0, 10)
const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

export default function PlotCalendarPage() {
  const { plotId } = useParams()
  const [searchParams] = useSearchParams()
  const [selectedCalendarId, setselectedCalendarId] = useState(() => searchParams.get('calendarId'))
  const [selectedDate, setSelectedDate] = useState(() => searchParams.get('date') ?? '')
  const [filters, setFilters] = useState({ plant_id: '', zone_id: '' })
  const [generateForm, setGenerateForm] = useState({
    start_date: TODAY,
    end_date: '',
  })
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [refreshingWeather, setRefreshingWeather] = useState(false)
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
      return {
        plot: plot && typeof plot === 'object' ? plot : null,
        calendars: Array.isArray(calendars) ? calendars : [],
        accessRole,
      }
    },
    [plotId],
    { plot: null, calendars: [], accessRole: null },
  )

  useEffect(() => {
    if (!selectedCalendarId && pageState.data.calendars.length > 0) {
      setselectedCalendarId(pageState.data.calendars[0].id)
    }
  }, [pageState.data.calendars, selectedCalendarId])

  const detailState = useAsyncData(
    async () => {
      if (!selectedCalendarId) return null
      return normalizeCalendar(await api.getCalendar(plotId, selectedCalendarId))
    },
    [plotId, selectedCalendarId],
    null,
  )

  const availableDates = useMemo(() => detailState.data?.available_dates ?? [], [detailState.data?.available_dates])

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
      const tasks = await api.listCalendarTasks(selectedCalendarId, {
        date: selectedDate,
        plant_id: filters.plant_id || undefined,
        zone_id: filters.zone_id || undefined,
      })
      return Array.isArray(tasks) ? tasks : []
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
  const weatherFetchedTimes = (detailState.data?.weather ?? [])
    .map((forecast) => forecast.fetched_at)
    .filter(Boolean)
    .sort()
  const latestWeatherFetchedAt = weatherFetchedTimes[weatherFetchedTimes.length - 1]

  async function handleGenerate(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    try {
      const created = await api.generateCalendar(plotId, generateForm)
      await pageState.reload()
      const nextCalendarId = created?.id ?? pageState.data.calendars[0]?.id
      if (nextCalendarId) {
        startTransition(() => { setselectedCalendarId(nextCalendarId) })
      }
      setToastMessage('Calendar generated successfully.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  async function handleTaskAction(taskId, action) {
    if (submitting) return
    if (action === 'reject' && !window.confirm('Remove this calendar action?')) return
    setSubmitting(true)
    setError('')
    try {
      if (action === 'complete') {
        await api.completeTask(taskId)
        setToastMessage('Action completed.')
      } else {
        await api.rejectTask(taskId)
        setToastMessage('Action removed.')
      }
      await Promise.all([tasksState.reload(), detailState.reload()])
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  async function handleRefreshWeather() {
    if (!selectedCalendarId) return

    setRefreshingWeather(true)
    setError('')

    try {
      const response = await api.refreshCalendarWeather(plotId, selectedCalendarId)
      if (response?.calendar) {
        detailState.setData(response.calendar)
      } else {
        await detailState.reload()
      }
      setToastMessage(response?.message ?? 'Weather forecast refreshed.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setRefreshingWeather(false)
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
      <PlotSectionNav
        plotId={plotId}
        plotName={pageState.data.plot?.name ?? 'Plot'}
        sectionKey="calendar"
        isOwner={pageState.data.accessRole === 'owner'}
        description="Choose a period, generate a recommendation calendar, and open daily tasks."
        meta={selectedCalendarId ? <StatusBadge kind="selection">Calendar #{selectedCalendarId}</StatusBadge> : null}
      />

      <SuccessToast message={toastMessage} onDismiss={() => setToastMessage('')} />

      <div className="calendar-layout">
        <aside className="page-stack calendar-sidebar">
          {canEdit ? (
            <form onSubmit={handleGenerate}>
              <FormSection
        title="Generate calendar"
        description="Set the planning period. Weather, plant care, and inventory are combined on the server."
                className="calendar-rail-card calendar-generator-card"
              >
                <div className="calendar-generator-highlights">
                  <span className="calendar-generator-highlight">Meteo.lt forecast rules</span>
                  <span className="calendar-generator-highlight">Plant care intervals</span>
                  <span className="calendar-generator-highlight">Inventory check</span>
                </div>

                <div className="calendar-generator-fields">
                  <FormField id="calendar-start" label="Start date">
                    <input
                      id="calendar-start"
                      type="date"
                      value={generateForm.start_date}
                      onChange={(event) => setGenerateForm((current) => ({ ...current, start_date: event.target.value }))}
                      required
                    />
                  </FormField>
                  <FormField id="calendar-end" label="End date">
                    <input
                      id="calendar-end"
                      type="date"
                      value={generateForm.end_date}
                      onChange={(event) => setGenerateForm((current) => ({ ...current, end_date: event.target.value }))}
                      required
                    />
                  </FormField>
                </div>

                {error ? <span className="field-error">{error}</span> : null}
                {submitting ? (
                  <ProcessingState
                    title="Generating calendar"
                    description="The system combines weather forecasts, plant care, and plot data into planned tasks."
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
            description="Switch between generated recommendation results without losing the monthly view."
            className="calendar-rail-card calendar-list-card"
            actions={<StatusBadge kind="selection" tone="neutral">{pageState.data.calendars.length}</StatusBadge>}
          >
            {pageState.data.calendars.length === 0 ? (
              <div className="calendar-list-empty">
                <strong>No calendars yet</strong>
                <p className="muted">Generate your first recommendation calendar to see the monthly grid and daily tasks.</p>
              </div>
            ) : (
              <div className="stack stack-sm">
                {pageState.data.calendars.map((calendar) => (
                  <button
                    key={calendar.id}
                    type="button"
                    className={`calendar-choice-card ${String(selectedCalendarId) === String(calendar.id) ? 'is-selected' : ''}`.trim()}
                    onClick={() => { startTransition(() => { setselectedCalendarId(calendar.id) }) }}
                  >
                    <div className="calendar-choice-copy">
                      <h3>Calendar #{calendar.id}</h3>
                      <span className="muted">{formatDate(calendar.start_date)} - {formatDate(calendar.end_date)}</span>
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
              description="Focus on one plant or zone when you want a narrower daily view."
              className="calendar-rail-card"
              compact
            >
              <FormField id="calendar-plant-filter" label="Plant">
                <select
                  id="calendar-plant-filter"
                  value={filters.plant_id}
                  onChange={(event) => setFilters((current) => ({ ...current, plant_id: event.target.value }))}
                >
                  <option value="">All plants</option>
                  {plantOptions.map((plant) => <option key={plant.id} value={plant.id}>{translateVisibleText(plant.name)}</option>)}
                </select>
              </FormField>
              <FormField id="calendar-zone-filter" label="Zona">
                <select
                  id="calendar-zone-filter"
                  value={filters.zone_id}
                  onChange={(event) => setFilters((current) => ({ ...current, zone_id: event.target.value }))}
                >
                  <option value="">All zones</option>
                  {zoneOptions.map((zone) => <option key={zone.id} value={zone.id}>{translateVisibleText(zone.name)}</option>)}
                </select>
              </FormField>
            </SectionCard>
          ) : null}
        </aside>

        <section className="page-stack calendar-main-panel">
          {detailState.loading ? <LoadingState title="Loading calendar..." /> : null}
          {detailState.error ? <ErrorState error={detailState.error} onRetry={detailState.reload} /> : null}

          {!detailState.loading && !detailState.data ? (
            <SectionCard
              title="Planning workspace"
              description="The monthly grid appears after generating a calendar. Daily details show weather, shortages, and actions."
              className="calendar-empty-workspace"
            >
              <div className="calendar-empty-guide">
                <article className="calendar-empty-step">
                  <span className="calendar-empty-step-index">1</span>
                  <div className="calendar-empty-step-copy">
                    <strong>Choose a planning period</strong>
                    <p>Select a date range so the generator uses the right weather forecast period.</p>
                  </div>
                </article>
                <article className="calendar-empty-step">
                  <span className="calendar-empty-step-index">2</span>
                  <div className="calendar-empty-step-copy">
                    <strong>Generate recommendations</strong>
                    <p>The backend schedules work from plant care, forecast, and current plot status.</p>
                  </div>
                </article>
                <article className="calendar-empty-step">
                  <span className="calendar-empty-step-index">3</span>
                  <div className="calendar-empty-step-copy">
                    <strong>Open daily details</strong>
                    <p>Review blockers or plant status first, then complete, delete, or go to inventory.</p>
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
                <p className="muted">Daily workload bars help you quickly spot busy or blocked dates.</p>
              </div>
            </SectionCard>
          ) : null}

          {!detailState.loading && detailState.data ? (
            <SectionCard
          title="Monthly view"
              description="Day cells clearly show workload, status, and shortage impact."
            >
              {usingWeatherFallback ? (
                <div className="inline-note">
                  Weather forecast uses fallback data: {weatherSources.map(weatherSourceLabel).join(', ')}.
                </div>
              ) : null}

              <div className="calendar-weather-refresh-row">
                <span className="muted">
                  Forecast updated: {latestWeatherFetchedAt ? formatDateTime(latestWeatherFetchedAt) : 'no data'}
                </span>
                <Button
                  variant="secondary"
                  onClick={handleRefreshWeather}
                  disabled={!canEdit || refreshingWeather || !selectedCalendarId}
                >
                  {refreshingWeather ? 'Refreshing...' : 'Refresh forecast'}
                </Button>
              </div>

              <MapLayerControl
                title="Calendar layers"
                items={[
                  { id: 'tasks', label: 'Tasks', active: true, color: '#49683f' },
                  { id: 'weather', label: usingWeatherFallback ? 'Fallback forecast' : 'Meteo.lt forecast', active: true, color: '#b76d17' },
                  { id: 'inventory', label: 'Inventory coverage', active: true, color: '#ef6d22' },
                  { id: 'priority', label: 'Priority load', active: true, color: '#c44934' },
                ]}
                className="calendar-layer-control"
              />

              <div className="month-nav">
                <Button variant="ghost" size="sm" onClick={() => setCurrentMonth((month) => shiftMonth(month, -1))}>Previous</Button>
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
                      ? 'Missing'
                      : taskCount >= 4
                        ? 'Busy'
                        : taskCount >= 1
                          ? 'Planned'
                          : 'Free'

                  return (
                    <button
                      key={day}
                      type="button"
                      aria-label={day.slice(8)}
                      className={`month-day month-day-${tone} ${isSelected ? 'is-selected' : ''} ${isToday ? 'is-today' : ''}`.trim()}
                      onClick={() => handleDayClick(day)}
                      title={hasTasks ? `${day}: ${taskCount} tasks` : day}
                    >
                      <span className="month-day-num">{day.slice(8)}</span>
                      <span className="month-day-state">{workloadLabel}</span>
                      <span className="month-day-tasks">{taskCount ? `${taskCount} tasks` : 'No tasks'}</span>
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
                The selected day's status, workload, and shortages are visible directly in the monthly grid.
              </p>
            </SectionCard>
          ) : null}
        </section>
      </div>

      <Drawer
        open={dayModalOpen}
        onClose={closeDayModal}
        labelledBy="calendar-day-title"
        describedBy="calendar-day-subtitle"
        size="sm"
        className="day-modal-panel"
      >
        <DialogHeader
          title={selectedDate ? formatDate(selectedDate) : '--'}
          subtitle={tasksState.loading ? 'Loading actions...' : `${tasksState.data.length} actions for the selected day`}
          titleId="calendar-day-title"
          subtitleId="calendar-day-subtitle"
          onClose={closeDayModal}
          closeLabel="Close day details"
        />
        <DialogBody className="day-modal-body page-stack">

            {selectedForecast ? (
              <section className="dialog-section day-drawer-section">
                <p className="dialog-section-title day-drawer-label">Weather</p>
                {selectedForecast.source && selectedForecast.source !== 'api' ? (
                  <div className="inline-note day-drawer-note">
                    {weatherSourceNote(selectedForecast)}
                  </div>
                ) : null}
                <div className="inline-note day-drawer-note">
                  Updated: {selectedForecast.fetched_at ? formatDateTime(selectedForecast.fetched_at) : 'no data'}
                </div>
                <div className="day-modal-weather">
                  <StatRow label="Min." value={formatTemperatureC(selectedForecast.temp_min ?? selectedForecast.temperature)} />
                  <StatRow label="Max." value={formatTemperatureC(selectedForecast.temp_max ?? selectedForecast.temperature)} />
                  <StatRow label="Rain" value={formatNumberWithUnit(selectedForecast.precipitation, 'mm', 1)} />
                  <StatRow label="Wind" value={formatNumberWithUnit(selectedForecast.wind_kmh ?? 0, 'km/h', 1)} />
                </div>
              </section>
            ) : null}

            {selectedDaySummary ? (
              <section className="dialog-section day-drawer-section page-stack">
                <p className="dialog-section-title day-drawer-label">Daily resources</p>
                <div
                  className="inline-note"
                  style={['partially_blocked', 'blocked'].includes(selectedDaySummary.day_inventory_status) ? { color: 'var(--danger)' } : undefined}
                >
                  {translateVisibleText(selectedDaySummary.summary_text)
                    ?? (selectedDaySummary.day_inventory_status === 'fully_covered'
                      ? 'Inventory is sufficient for this day.'
                      : 'Planned work is blocked because inventory is missing.')}
                </div>
                {(selectedDaySummary.grouped_resource_summary ?? selectedDaySummary.resources ?? []).map((resource) => (
                  <div key={`${selectedDate}-${resource.resource_key}`} className="resource-summary-row">
                    <StatRow
                      label={translateVisibleText(resource.resource_name)}
                      value={`Required ${safeNumber(resource.required_quantity, resource.inventory_item_type === 'tool' ? 0 : 2)} ${formatInventoryUnit(resource.unit)}`}
                    />
                    <StatRow
                      label={resourceTypeLabel(resource)}
                      className={resource.shortage_quantity > 0 ? 'stat-row-danger' : ''}
                      value={`Available ${safeNumber(resource.available_quantity, resource.inventory_item_type === 'tool' ? 0 : 2)}${resource.shortage_quantity > 0
                        ? ` / missing ${safeNumber(resource.shortage_quantity, resource.inventory_item_type === 'tool' ? 0 : 2)}`
                        : ''}`}
                    />
                  </div>
                ))}
                {(selectedDaySummary.replenishment_tasks ?? selectedDaySummary.buy_tasks ?? []).length > 0 ? (
                  <div className="stack stack-sm">
                    <span className="muted">Generated replenishment tasks:</span>
                    {(selectedDaySummary.replenishment_tasks ?? selectedDaySummary.buy_tasks ?? []).map((task) => (
                      <span key={`buy-summary-${task.id}`} className="muted">
                        {translateVisibleText(task.name)} - {safeNumber(task.item_quantity, 2)} {translateVisibleText(task.item) ?? ''}
                      </span>
                    ))}
                  </div>
                ) : null}
              </section>
            ) : null}

            {tasksState.loading ? <LoadingState title="Loading actions..." /> : null}
            {tasksState.error ? <ErrorState error={tasksState.error} onRetry={tasksState.reload} /> : null}

            {!tasksState.loading && !tasksState.error && tasksState.data.length === 0 ? (
              <EmptyState title="No actions for this day" description="Choose another date or clear filters." />
            ) : null}

            {!tasksState.loading && !tasksState.error ? (
              <section className="dialog-section day-actions-section">
                <div className="day-actions-header">
                  <p className="dialog-section-title">Actions</p>
                  <span className="muted">Planned: {tasksState.data.length}</span>
                </div>
                <div className="task-groups day-task-groups">
                {error ? <span className="field-error">{error}</span> : null}
                {tasksState.data.map((task) => {
                  const missingResources = (task.inventory_shortages ?? task.required_resources ?? [])
                    .filter((resource) => resource.is_shortage ?? resource.shortage_quantity > 0)
                  const isReplenishmentTask = task.is_replenishment_task || task.inventory_mode === 'replenishment' || task.type === 'buy'
                  const linkedReplenishmentTask = isReplenishmentTask ? null : getLinkedReplenishmentTask(task, tasksState.data)
                  const hasInventoryShortage = task.status === 'pending' && !isReplenishmentTask && missingResources.length > 0
                  const taskFocus = describeTaskFocus(task, missingResources, isReplenishmentTask, linkedReplenishmentTask)
                  const inventorySummary = summarizeInventoryContext(task, isReplenishmentTask)
                  const resourceRequirements = task.resource_requirements ?? task.required_resources ?? []
                  const quickFacts = [
                    task.actual_condition ? `Current status: ${formatPlantCondition(task.actual_condition)}` : null,
                    task.simulated_phase ? `Expected status: ${formatPlantCondition(task.simulated_phase)}` : null,
                    task.lifecycle_transition?.is_transition_day
                      ? `Expected transition: ${formatPlantCondition(task.lifecycle_transition.from)} → ${formatPlantCondition(task.lifecycle_transition.to)}`
                      : null,
                    ...(
                      isReplenishmentTask
                        ? missingResources.map((resource) => `Missing ${safeNumber(resource.shortage_quantity, 2)} ${formatInventoryUnit(resource.unit)}: ${translateVisibleText(resource.name ?? resource.resource_name)}${resource.blocked_task_count ? `, blocked tasks: ${resource.blocked_task_count}` : ''}`)
                        : []
                    ),
                  ].filter(Boolean)
                  const presentationQuickFacts = quickFacts.map((fact, index) => {
                    if (index === 0 && task.actual_condition) return `Current status: ${formatPlantCondition(task.actual_condition)}`
                    if (index === 1 && task.simulated_phase) return `Expected status: ${formatPlantCondition(task.simulated_phase)}`
                    return fact
                  })
                  const taskDetails = [
                    task.reason && taskFocus.detail !== translateVisibleText(task.reason) ? { label: 'Planning rule', value: translateVisibleText(task.reason) } : null,
                    task.comment && taskFocus.detail !== translateVisibleText(task.comment) ? { label: 'User note', value: translateVisibleText(task.comment) } : null,
                    task.type ? { label: 'Task type', value: formatTaskType(task.type) } : null,
                    !isReplenishmentTask && task.item
                      ? { label: 'Material', value: task.item_quantity ? `${translateVisibleText(task.item)} x ${safeNumber(task.item_quantity, 2)}` : translateVisibleText(task.item) }
                      : null,
                    linkedReplenishmentTask
                      ? { label: 'Dependency', value: `Complete "${translateVisibleText(linkedReplenishmentTask.name)}" first` }
                      : null,
                    inventorySummary ? { label: 'Inventory context', value: inventorySummary } : null,
                  ].filter(Boolean)

                  return (
                    <article key={task.id} className="task-item day-task-card" id={`day-task-${task.id}`}>
                      <div className="day-task-card-head">
                        <div className="day-task-card-title-block">
                          <strong className="day-task-card-title">{translateVisibleText(task.name)}</strong>
                          <span className="day-task-card-context">
                            {translateVisibleText(task.plant_name) || 'Plot level'} - {translateVisibleText(task.zone_name) || 'Zone not specified'}
                          </span>
                        </div>
                        <div className="day-task-card-badges">
                          <StatusBadge kind="status" tone={statusTone(task.status)}>{formatStatusLabel(task.status)}</StatusBadge>
                          <TaskPriorityBadge priority={task.priority} />
                        </div>
                      </div>

                      <div className={`task-focus-banner task-focus-banner-${taskFocus.tone}`.trim()}>
                        <span className="task-focus-label">{taskFocus.label}</span>
                        <p>{taskFocus.detail}</p>
                      </div>

                      {linkedReplenishmentTask ? (
                        <div className="day-task-card-dependency">
                          <span className="day-task-card-dependency-label">Depends on</span>
                          <strong>{translateVisibleText(linkedReplenishmentTask.name)}</strong>
                        </div>
                      ) : null}

                      {quickFacts.length > 0 ? (
                        <div className="day-task-card-facts">
                          {presentationQuickFacts.map((fact) => (
                            <span key={`${task.id}-${fact}`} className="day-task-card-fact">{fact}</span>
                          ))}
                        </div>
                      ) : null}

                      <div className="day-task-card-actions">
                        {linkedReplenishmentTask ? (
                          <Button
                            variant="ghost"
                            onClick={() => {
                              document.getElementById(`day-task-${linkedReplenishmentTask.id}`)?.scrollIntoView({
                                behavior: 'smooth',
                                block: 'nearest',
                              })
                            }}
                          >
                            Go to replenishment task
                          </Button>
                        ) : null}

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
                            <Button variant="secondary">Record harvest</Button>
                          </Link>
                        ) : null}

                        {canEdit && task.status === 'pending' && task.workflow_context?.kind !== 'lifecycle_review' && task.type !== 'harvest' ? (
                          <ActionRow>
                            <Button
                              onClick={() => handleTaskAction(task.id, 'complete')}
                              disabled={submitting || hasInventoryShortage || task.can_complete === false}
                            >
              {isReplenishmentTask ? 'Complete replenishment' : 'Complete'}
                            </Button>
                            <DestructiveButton
                              label="Delete calendar action"
                              onClick={() => handleTaskAction(task.id, 'reject')}
                              disabled={submitting}
                            >
                              Delete
                            </DestructiveButton>
                          </ActionRow>
                        ) : null}
                      </div>

                      {(taskDetails.length > 0 || resourceRequirements.length > 0 || missingResources.length > 0) ? (
                        <details className="task-card-details">
                          <summary>Details</summary>
                          <div className="task-card-detail-stack">
                            {taskDetails.length > 0 ? (
                              <DefinitionList className="task-card-detail-list" items={taskDetails} />
                            ) : null}

                            {resourceRequirements.length > 0 ? (
                              <div className="task-card-detail-block">
                                <strong>Resource requirements</strong>
                                <div className="task-card-resource-list">
                                  {resourceRequirements.map((resource) => (
                                    <StatRow
                                      key={`${task.id}-${resource.id ?? resource.name}`}
                                      className="task-card-resource-row"
                                      label={translateVisibleText(resource.name ?? resource.resource_name)}
                                      value={`Required ${safeNumber(resource.required_quantity, resource.type === 'tool' ? 0 : 2)} ${formatInventoryUnit(resource.unit)}${resource.available_quantity !== null && resource.available_quantity !== undefined
                                        ? ` / available ${safeNumber(resource.available_quantity, resource.type === 'tool' ? 0 : 2)}`
                                        : ''}${resource.is_shortage || resource.shortage_quantity > 0
                                        ? ` / missing ${safeNumber(resource.shortage_quantity, resource.type === 'tool' ? 0 : 2)}`
                                        : ''}`}
                                    />
                                  ))}
                                </div>
                              </div>
                            ) : null}

                            {missingResources.length > 0 ? (
                              <div className="task-card-detail-block">
                                <strong>Shortages</strong>
                                <div className="task-card-resource-list">
                                  {missingResources.map((resource) => (
                                    <StatRow
                                      key={`${task.id}-missing-${resource.id ?? resource.resource_name}`}
                                      className="task-card-resource-row stat-row-danger"
                                      label={translateVisibleText(resource.name ?? resource.resource_name)}
                                      value={`Missing ${safeNumber(resource.shortage_quantity, resource.type === 'tool' ? 0 : 2)} ${formatInventoryUnit(resource.unit)}`}
                                    />
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
              </section>
            ) : null}
        </DialogBody>
      </Drawer>
    </div>
  )
}
