import { startTransition, useEffect, useState } from 'react'
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
import { DefinitionList, StatRow } from '../../components/ui/DefinitionList.jsx'
import { DialogBody, DialogHeader, Drawer } from '../../components/ui/Dialog.jsx'
import FormField from '../../components/ui/FormField.jsx'
import FormSection from '../../components/ui/FormSection.jsx'
import SectionCard from '../../components/ui/SectionCard.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { api } from '../../lib/api.js'
import {
  formatDate,
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
    params.set('returnLabel', context.date ? `Grįžti į ${formatDate(context.date)}` : 'Grįžti į kalendorių')
  }

  return `/inventory?${params.toString()}`
}

function taskInventoryLabel(mode) {
  if (mode === 'available') return 'Inventorius paruoštas'
  if (mode === 'shortage') return 'Inventoriaus trūkumas'
  if (mode === 'replenishment') return 'Papildymo priminimas'
  return 'Inventorius nereikalingas'
}

const VISIBLE_TEXT_TRANSLATIONS = {
  'Inventory is fully covered for planned work on this day.': 'Inventoriaus pakanka visiems šios dienos suplanuotiems darbams.',
  'Inventory is fully covered.': 'Inventoriaus pakanka.',
  'Feed flowering tomatoes': 'Patręšti žydinčius pomidorus',
  'Apply lightly and water in after feeding.': 'Tręškite saikingai ir po tręšimo palaistykite.',
  "Tomato 'Sungold'": 'Pomidoras „Sungold“',
  'Tomato': 'Pomidoras',
  'Basil': 'Bazilikas',
  'Cucumber': 'Agurkas',
  'Carrot': 'Morka',
  'Beetroot': 'Burokėlis',
  'Lettuce': 'Salota',
  'Spinach': 'Špinatas',
  'Cabbage': 'Kopūstas',
  'Pepper': 'Paprika',
  'Strawberry': 'Braškė',
  'Bean': 'Pupelė',
  'Pea': 'Žirnis',
  'Corn': 'Kukurūzas',
  'Tomato and Basil Bed': 'Pomidorų ir bazilikų lysvė',
  'Root Vegetable Bed': 'Šakniavaisių lysvė',
  'Leafy Greens Bed': 'Lapinių daržovių lysvė',
  'Raspberry Canes': 'Aviečių zona',
  'Young Apple Guild': 'Jaunos obels zona',
  'Contained Mint Box': 'Mėtų dėžė',
  'Fertilizer': 'Trąšos',
  'Compost': 'Kompostas',
  'Tomato organic fertilizer': 'Organinės pomidorų trąšos',
  'Straw mulch': 'Šiaudų mulčias',
  'Neem oil spray': 'Nimbamedžio aliejaus purškalas',
  'Copper-free biofungicide': 'Biofungicidas be vario',
  'Row cover': 'Apsauginė danga',
  'Plant ties': 'Augalų raiščiai',
  'Plant support': 'Augalų atramos',
  'Protective cover': 'Apsauginė danga',
}

function translateVisibleText(value) {
  if (value === null || value === undefined) return value

  const directTranslation = VISIBLE_TEXT_TRANSLATIONS[String(value)]

  if (directTranslation) {
    return directTranslation
  }

  return String(value)
    .replace(/\bRestocked:/g, 'Papildyta:')
    .replace(/\bBuy\s+/g, 'Nupirkti: ')
    .replace(/\bPlant support\b/g, 'Augalų atramos')
    .replace(/\bProtective cover\b/g, 'Apsauginė danga')
    .replace(/\bFertilizer\b/g, 'Trąšos')
    .replace(/(\d+)\.(\d{2})\s+unit\b/g, '$1,$2 vnt.')
    .replace(/(\d+)\.(\d{2})(?=\s+(?:vnt\.|kg|g|l|ml)\b)/g, '$1,$2')
    .replace(/\bunit\b/g, 'vnt.')
}

function summarizeInventoryContext(task, isReplenishmentTask) {
  if (!task.inventory_context) {
    return null
  }

  if (task.inventory_mode === 'shortage' && task.inventory_context.shortage_count > 0) {
    return `Aptikta trūkumų: ${task.inventory_context.shortage_count}`
  }

  if (!isReplenishmentTask && (task.inventory_context.buy_task_ids ?? []).length > 0) {
    return 'Susieta papildymo užduotis jau yra'
  }

  if ((task.inventory_context.open_buy_task_ids ?? []).length > 0) {
    return 'Atvira pirkimo užduotis jau yra'
  }

  if (task.inventory_mode === 'available') {
    return 'Reikalingas inventorius yra paruoštas'
  }

  return taskInventoryLabel(task.inventory_mode)
}

function describeTaskFocus(task, missingResources, isReplenishmentTask, linkedReplenishmentTask = null) {
  const firstMissing = missingResources[0]
  const firstMissingLabel = firstMissing
    ? `${translateVisibleText(firstMissing.name ?? firstMissing.resource_name)}: trūksta ${safeNumber(firstMissing.shortage_quantity, firstMissing.type === 'tool' ? 0 : 2)} ${formatInventoryUnit(firstMissing.unit)}`
    : null

  if (task.status === 'completed') {
    return {
      tone: 'success',
      label: 'Atlikta',
      detail: translateVisibleText(task.comment) || 'Šis veiksmas jau atliktas.',
    }
  }

  if (task.status === 'canceled' || task.status === 'cancelled') {
    return {
      tone: 'danger',
      label: 'Atšaukta',
      detail: translateVisibleText(task.comment) || 'Šis veiksmas atšauktas ir nebereikalauja darbo.',
    }
  }

  if (isReplenishmentTask) {
    const blockedTaskCount = firstMissing?.blocked_task_count ?? task.inventory_context?.replenishment?.blocked_task_count ?? 0
    return {
      tone: firstMissing ? 'warning' : 'soft',
      label: 'Papildymo užduotis',
      detail: firstMissingLabel
        ? `Atlikus šią užduotį inventorius papildomas. ${firstMissingLabel}${blockedTaskCount ? `, atblokuojamų užduočių: ${blockedTaskCount}.` : '.'}`
        : 'Atlikus šią užduotį inventorius papildomas ir atblokuojamos susietos užduotys.',
    }
  }

  if (task.status === 'pending' && firstMissing) {
    const dependencyLabel = linkedReplenishmentTask
      ? `„${translateVisibleText(linkedReplenishmentTask.name)}“`
      : 'susieta papildymo užduotis'
    return {
      tone: 'danger',
      label: 'Blokuota dėl trūkumo',
      detail: `Užduotis bus blokuota, kol bus atlikta ${dependencyLabel}. Atsargos papildomos ten, ne čia. ${firstMissingLabel}`,
    }
  }

  if (task.workflow_context?.kind === 'lifecycle_review' && task.plant_id) {
    return {
      tone: 'warning',
      label: 'Reikia augalo peržiūros',
      detail: 'Atidarykite augalo įrašą ir patvirtinkite būklę prieš atlikdami užduotį.',
    }
  }

  if (task.type === 'harvest' && task.plant_id) {
    return {
      tone: 'warning',
      label: 'Reikia registruoti derlių',
      detail: 'Užregistruokite derlių susietame augalo procese.',
    }
  }

  if (task.actual_condition && task.actual_condition !== 'healthy') {
    return {
      tone: 'warning',
      label: `Būklė: ${formatPlantCondition(task.actual_condition)}`,
      detail: translateVisibleText(task.reason) || 'Užduotis reaguoja į šiai dienai nustatytą augalo būklę.',
    }
  }

  if (task.reason) {
    return {
      tone: 'soft',
      label: 'Suplanuota pagal taisyklę',
      detail: translateVisibleText(task.reason),
    }
  }

  if (task.comment) {
    return {
      tone: 'neutral',
      label: 'Naudotojo pastaba',
      detail: translateVisibleText(task.comment),
    }
  }

  return {
    tone: task.inventory_mode === 'available' ? 'success' : 'neutral',
    label: taskInventoryLabel(task.inventory_mode),
    detail: summarizeInventoryContext(task, isReplenishmentTask) || 'Paruošta veiksmui.',
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
  return (resource.resource_mode ?? resource.consumption_mode) === 'consumable' ? 'Sunaudojama' : 'Daugkartinė'
}

function weatherSourceLabel(source) {
  if (source === 'api') return 'Tiesioginė Meteo.lt prognozė'
  if (source === 'stored_city_date') return 'Išsaugota prognozė pagal miestą ir datą'
  if (source === 'stored_other_city_date') return 'Išsaugota prognozė pagal datą'
  if (source === 'seasonal') return 'Sezoninė atsarginė prognozė'
  if (source === 'legacy_unknown') return 'Ankstesni prognozės duomenys'
  return 'Atsarginė prognozė'
}

function weatherSourceNote(forecast) {
  const source = forecast?.source
  const sourceDate = forecast?.source_date ? formatDate(forecast.source_date) : null
  const sourceCity = forecast?.source_city ?? forecast?.city

  if (!source || source === 'api') return ''

  if (source === 'stored_city_date' || source === 'stored_other_city_date') {
    if (sourceDate && sourceCity) {
      return `Šaltinis: atsarginė ${sourceCity} prognozė pagal ${sourceDate} duomenis`
    }

    if (sourceDate) {
      return `Šaltinis: atsarginė prognozė pagal ${sourceDate} duomenis`
    }

    return 'Šaltinis: atsarginė prognozė'
  }

  if (source === 'seasonal') {
    return 'Šaltinis: sezoninė atsarginė prognozė'
  }

  if (source === 'legacy_unknown') {
    return 'Šaltinis: ankstesni prognozės duomenys'
  }

  return `Šaltinis: ${weatherSourceLabel(source).toLowerCase()}`
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
      setselectedCalendarId(pageState.data.calendars[0].id)
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
      startTransition(() => { setselectedCalendarId(created.id) })
      setToastMessage('Kalendorius sėkmingai sugeneruotas.')
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
        setToastMessage('Darbas pažymėtas kaip atliktas.')
      } else {
        await api.rejectTask(taskId)
        setToastMessage('Darbas atmestas.')
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

  if (pageState.loading) return <LoadingState title="Įkeliami kalendoriai..." />
  if (pageState.error) return <ErrorState error={pageState.error} onRetry={pageState.reload} />

  const monthDays = getMonthDays(currentMonth)

  return (
    <div className="page-stack">
      <PlotSectionNav
        plotId={plotId}
        plotName={pageState.data.plot?.name ?? 'Sklypas'}
        sectionKey="calendar"
        isOwner={pageState.data.accessRole === 'owner'}
        description="Pasirinkite laikotarpį, sugeneruokite rekomendacijų kalendorių ir atidarykite dienos užduotis."
        meta={selectedCalendarId ? <StatusBadge kind="selection">Kalendorius #{selectedCalendarId}</StatusBadge> : null}
      />

      <SuccessToast message={toastMessage} onDismiss={() => setToastMessage('')} />

      <div className="calendar-layout">
        <aside className="page-stack calendar-sidebar">
          {canEdit ? (
            <form onSubmit={handleGenerate}>
              <FormSection
                title="Generuoti kalendorių"
                description="Nustatykite planavimo laikotarpį. Orai, augalų priežiūra ir inventorius bus sujungti serverio pusėje."
                className="calendar-rail-card calendar-generator-card"
              >
                <div className="calendar-generator-highlights">
                  <span className="calendar-generator-highlight">Meteo.lt prognozės taisyklės</span>
                  <span className="calendar-generator-highlight">Augalų priežiūros intervalai</span>
                  <span className="calendar-generator-highlight">Inventoriaus patikra</span>
                </div>

                <div className="calendar-generator-fields">
                  <FormField id="calendar-start" label="Pradžios data">
                    <input
                      id="calendar-start"
                      type="date"
                      value={generateForm.start_date}
                      onChange={(event) => setGenerateForm((current) => ({ ...current, start_date: event.target.value }))}
                      required
                    />
                  </FormField>
                  <FormField id="calendar-end" label="Pabaigos data">
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
                    title="Generuojamas kalendorius"
                    description="Sistema jungia orų prognozę, augalų priežiūrą ir sklypo duomenis į suplanuotas užduotis."
                    steps={['Ruošiami sklypo duomenys', 'Tikrinamos orų taisyklės', 'Generuojamos užduotys']}
                    compact
                  />
                ) : null}

                <ActionRow>
                  <Button type="submit" loading={submitting}>
                    {submitting ? 'Generuojamas kalendorius' : 'Generuoti'}
                  </Button>
                </ActionRow>
              </FormSection>
            </form>
          ) : null}

          <SectionCard
            title="Sugeneruoti kalendoriai"
            description="Perjunkite rekomendacijų generavimo rezultatus neprarasdami mėnesio vaizdo."
            className="calendar-rail-card calendar-list-card"
            actions={<StatusBadge kind="selection" tone="neutral">{pageState.data.calendars.length}</StatusBadge>}
          >
            {pageState.data.calendars.length === 0 ? (
              <div className="calendar-list-empty">
                <strong>Kalendorių dar nėra</strong>
                <p className="muted">Sugeneruokite pirmą rekomendacijų kalendorių, kad matytumėte mėnesio tinklelį ir dienos užduotis.</p>
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
                      <h3>Kalendorius #{calendar.id}</h3>
                      <span className="muted">{formatDate(calendar.start_date)} - {formatDate(calendar.end_date)}</span>
                    </div>
                    <StatusBadge kind="selection" tone="neutral">{calendar.tasks_count ?? 0} užduočių</StatusBadge>
                  </button>
                ))}
              </div>
            )}
          </SectionCard>

          {detailState.data ? (
            <SectionCard
              title="Užduočių filtrai"
              description="Susitelkite į vieną augalą arba zoną, kai norite siauresnės dienos peržiūros."
              className="calendar-rail-card"
              compact
            >
              <FormField id="calendar-plant-filter" label="Augalas">
                <select
                  id="calendar-plant-filter"
                  value={filters.plant_id}
                  onChange={(event) => setFilters((current) => ({ ...current, plant_id: event.target.value }))}
                >
                  <option value="">Visi augalai</option>
                  {plantOptions.map((plant) => <option key={plant.id} value={plant.id}>{translateVisibleText(plant.name)}</option>)}
                </select>
              </FormField>
              <FormField id="calendar-zone-filter" label="Zona">
                <select
                  id="calendar-zone-filter"
                  value={filters.zone_id}
                  onChange={(event) => setFilters((current) => ({ ...current, zone_id: event.target.value }))}
                >
                  <option value="">Visos zonos</option>
                  {zoneOptions.map((zone) => <option key={zone.id} value={zone.id}>{translateVisibleText(zone.name)}</option>)}
                </select>
              </FormField>
            </SectionCard>
          ) : null}
        </aside>

        <section className="page-stack calendar-main-panel">
          {detailState.loading ? <LoadingState title="Įkeliamas kalendorius..." /> : null}
          {detailState.error ? <ErrorState error={detailState.error} onRetry={detailState.reload} /> : null}

          {!detailState.loading && !detailState.data ? (
            <SectionCard
              title="Planavimo darbo sritis"
              description="Mėnesio tinklelis atsiranda sugeneravus kalendorių, o dienos peržiūroje rodomi orai, trūkumai ir veiksmai."
              className="calendar-empty-workspace"
            >
              <div className="calendar-empty-guide">
                <article className="calendar-empty-step">
                  <span className="calendar-empty-step-index">1</span>
                  <div className="calendar-empty-step-copy">
                    <strong>Pasirinkite planavimo laikotarpį</strong>
                    <p>Pasirinkite datų intervalą, kad generatorius naudotų tinkamą orų prognozės periodą.</p>
                  </div>
                </article>
                <article className="calendar-empty-step">
                  <span className="calendar-empty-step-index">2</span>
                  <div className="calendar-empty-step-copy">
                    <strong>Sugeneruokite rekomendacijas</strong>
                    <p>Backend dalis suplanuoja darbus pagal augalų priežiūrą, prognozę ir dabartinę sklypo būseną.</p>
                  </div>
                </article>
                <article className="calendar-empty-step">
                  <span className="calendar-empty-step-index">3</span>
                  <div className="calendar-empty-step-copy">
                    <strong>Atidarykite dienos detales</strong>
                    <p>Pirmiausia peržiūrėkite blokavimus arba augalų būklę, tada atlikite, atmeskite arba pereikite į inventorių.</p>
                  </div>
                </article>
              </div>

              <div className="calendar-empty-preview">
                <span className="calendar-empty-preview-label">Kas rodoma po generavimo</span>
                <div className="calendar-empty-preview-bars">
                  <span className="calendar-empty-preview-bar calendar-empty-preview-bar-soft" />
                  <span className="calendar-empty-preview-bar calendar-empty-preview-bar-brand" />
                  <span className="calendar-empty-preview-bar calendar-empty-preview-bar-warning" />
                </div>
                <p className="muted">Užimtumo juostos dienose padeda greitai pastebėti intensyvias arba blokuotas datas.</p>
              </div>
            </SectionCard>
          ) : null}

          {!detailState.loading && detailState.data ? (
            <SectionCard
              title="Mėnesio vaizdas"
              description="Dienos langeliuose aiškiai rodoma užimtumo juosta, būsena ir trūkumų įtaka."
            >
              {usingWeatherFallback ? (
                <div className="inline-note">
                  Orų prognozėje naudojami atsarginiai duomenys: {weatherSources.map(weatherSourceLabel).join(', ')}.
                </div>
              ) : null}

              <MapLayerControl
                title="Kalendoriaus sluoksniai"
                items={[
                  { id: 'tasks', label: 'Užduotys', active: true, color: '#49683f' },
                  { id: 'weather', label: usingWeatherFallback ? 'Atsarginė prognozė' : 'Meteo.lt prognozė', active: true, color: '#b76d17' },
                  { id: 'inventory', label: 'Inventoriaus padengimas', active: true, color: '#ef6d22' },
                  { id: 'priority', label: 'Prioritetų apkrova', active: true, color: '#c44934' },
                ]}
                className="calendar-layer-control"
              />

              <div className="month-nav">
                <Button variant="ghost" size="sm" onClick={() => setCurrentMonth((month) => shiftMonth(month, -1))}>Ankstesnis</Button>
                <span className="month-title">{formatMonthTitle(currentMonth)}</span>
                <Button variant="ghost" size="sm" onClick={() => setCurrentMonth((month) => shiftMonth(month, 1))}>Kitas</Button>
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
                    ? 'Blokuota'
                    : dayStatus === 'partially_blocked'
                      ? 'Trūksta'
                      : taskCount >= 4
                        ? 'Užimta'
                        : taskCount >= 1
                          ? 'Suplanuota'
                          : 'Laisva'

                  return (
                    <button
                      key={day}
                      type="button"
                      aria-label={day.slice(8)}
                      className={`month-day month-day-${tone} ${isSelected ? 'is-selected' : ''} ${isToday ? 'is-today' : ''}`.trim()}
                      onClick={() => handleDayClick(day)}
                      title={hasTasks ? `${day}: ${taskCount} užduočių` : day}
                    >
                      <span className="month-day-num">{day.slice(8)}</span>
                      <span className="month-day-state">{workloadLabel}</span>
                      <span className="month-day-tasks">{taskCount ? `${taskCount} užduočių` : 'Užduočių nėra'}</span>
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
                Pasirinktos dienos būsena, apkrova ir trūkumai matomi tiesiai mėnesio tinklelyje.
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
          subtitle={tasksState.loading ? 'Įkeliami veiksmai...' : `${tasksState.data.length} veiksmų pasirinktai dienai`}
          titleId="calendar-day-title"
          subtitleId="calendar-day-subtitle"
          onClose={closeDayModal}
          closeLabel="Uždaryti dienos detales"
        />
        <DialogBody className="day-modal-body page-stack">

            {selectedForecast ? (
              <section className="dialog-section day-drawer-section">
                <p className="dialog-section-title day-drawer-label">Orai</p>
                {selectedForecast.source && selectedForecast.source !== 'api' ? (
                  <div className="inline-note day-drawer-note">
                    {weatherSourceNote(selectedForecast)}
                  </div>
                ) : null}
                <div className="day-modal-weather">
                  <StatRow label="Min." value={formatTemperatureC(selectedForecast.temp_min ?? selectedForecast.temperature)} />
                  <StatRow label="Maks." value={formatTemperatureC(selectedForecast.temp_max ?? selectedForecast.temperature)} />
                  <StatRow label="Lietus" value={formatNumberWithUnit(selectedForecast.precipitation, 'mm', 1)} />
                  <StatRow label="Vėjas" value={formatNumberWithUnit(selectedForecast.wind_kmh ?? 0, 'km/h', 1)} />
                </div>
              </section>
            ) : null}

            {selectedDaySummary ? (
              <section className="dialog-section day-drawer-section page-stack">
                <p className="dialog-section-title day-drawer-label">Dienos resursai</p>
                <div
                  className="inline-note"
                  style={['partially_blocked', 'blocked'].includes(selectedDaySummary.day_inventory_status) ? { color: 'var(--danger)' } : undefined}
                >
                  {translateVisibleText(selectedDaySummary.summary_text)
                    ?? (selectedDaySummary.day_inventory_status === 'fully_covered'
                      ? 'Šios dienos darbams inventoriaus pakanka.'
                      : 'Suplanuoti darbai blokuojami dėl inventoriaus trūkumo.')}
                </div>
                {(selectedDaySummary.grouped_resource_summary ?? selectedDaySummary.resources ?? []).map((resource) => (
                  <div key={`${selectedDate}-${resource.resource_key}`} className="resource-summary-row">
                    <StatRow
                      label={translateVisibleText(resource.resource_name)}
                      value={`Reikia ${safeNumber(resource.required_quantity, resource.inventory_item_type === 'tool' ? 0 : 2)} ${formatInventoryUnit(resource.unit)}`}
                    />
                    <StatRow
                      label={resourceTypeLabel(resource)}
                      className={resource.shortage_quantity > 0 ? 'stat-row-danger' : ''}
                      value={`Turima ${safeNumber(resource.available_quantity, resource.inventory_item_type === 'tool' ? 0 : 2)}${resource.shortage_quantity > 0
                        ? ` / trūksta ${safeNumber(resource.shortage_quantity, resource.inventory_item_type === 'tool' ? 0 : 2)}`
                        : ''}`}
                    />
                  </div>
                ))}
                {(selectedDaySummary.replenishment_tasks ?? selectedDaySummary.buy_tasks ?? []).length > 0 ? (
                  <div className="stack stack-sm">
                    <span className="muted">Sugeneruotos papildymo užduotys:</span>
                    {(selectedDaySummary.replenishment_tasks ?? selectedDaySummary.buy_tasks ?? []).map((task) => (
                      <span key={`buy-summary-${task.id}`} className="muted">
                        {translateVisibleText(task.name)} - {safeNumber(task.item_quantity, 2)} {translateVisibleText(task.item) ?? ''}
                      </span>
                    ))}
                  </div>
                ) : null}
              </section>
            ) : null}

            {tasksState.loading ? <LoadingState title="Įkeliami veiksmai..." /> : null}
            {tasksState.error ? <ErrorState error={tasksState.error} onRetry={tasksState.reload} /> : null}

            {!tasksState.loading && !tasksState.error && tasksState.data.length === 0 ? (
              <EmptyState title="Šią dieną veiksmų nėra" description="Pasirinkite kitą datą arba išvalykite filtrus." />
            ) : null}

            {!tasksState.loading && !tasksState.error ? (
              <section className="dialog-section day-actions-section">
                <div className="day-actions-header">
                  <p className="dialog-section-title">Veiksmai</p>
                  <span className="muted">Suplanuota: {tasksState.data.length}</span>
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
                    task.actual_condition ? `Faktinė būklė: ${formatPlantCondition(task.actual_condition)}` : null,
                    task.simulated_phase ? `Tikėtina būklė: ${formatPlantCondition(task.simulated_phase)}` : null,
                    task.lifecycle_transition?.is_transition_day
                      ? `${task.lifecycle_transition.from} -> ${task.lifecycle_transition.to}`
                      : null,
                    ...(
                      isReplenishmentTask
                        ? missingResources.map((resource) => `Trūksta ${safeNumber(resource.shortage_quantity, 2)} ${formatInventoryUnit(resource.unit)}: ${translateVisibleText(resource.name ?? resource.resource_name)}${resource.blocked_task_count ? `, blokuotų užduočių: ${resource.blocked_task_count}` : ''}`)
                        : []
                    ),
                  ].filter(Boolean)
                  const taskDetails = [
                    task.reason && taskFocus.detail !== translateVisibleText(task.reason) ? { label: 'Planavimo taisyklė', value: translateVisibleText(task.reason) } : null,
                    task.comment && taskFocus.detail !== translateVisibleText(task.comment) ? { label: 'Naudotojo pastaba', value: translateVisibleText(task.comment) } : null,
                    task.type ? { label: 'Užduoties tipas', value: formatTaskType(task.type) } : null,
                    !isReplenishmentTask && task.item
                      ? { label: 'Medžiaga', value: task.item_quantity ? `${translateVisibleText(task.item)} x ${safeNumber(task.item_quantity, 2)}` : translateVisibleText(task.item) }
                      : null,
                    linkedReplenishmentTask
                      ? { label: 'Priklausomybė', value: `Pirma reikia atlikti „${translateVisibleText(linkedReplenishmentTask.name)}“` }
                      : null,
                    inventorySummary ? { label: 'Inventoriaus kontekstas', value: inventorySummary } : null,
                  ].filter(Boolean)

                  return (
                    <article key={task.id} className="task-item day-task-card" id={`day-task-${task.id}`}>
                      <div className="day-task-card-head">
                        <div className="day-task-card-title-block">
                          <strong className="day-task-card-title">{translateVisibleText(task.name)}</strong>
                          <span className="day-task-card-context">
                            {translateVisibleText(task.plant_name) || 'Sklypo lygis'} - {translateVisibleText(task.zone_name) || 'Zona nenurodyta'}
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
                          <span className="day-task-card-dependency-label">Priklauso nuo</span>
                          <strong>{linkedReplenishmentTask.name}</strong>
                        </div>
                      ) : null}

                      {quickFacts.length > 0 ? (
                        <div className="day-task-card-facts">
                          {quickFacts.map((fact) => (
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
                            Pereiti prie papildymo užduoties
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
                            <Button variant="secondary">Eiti į inventorių</Button>
                          </Link>
                        ) : null}

                        {canEdit && task.status === 'pending' && task.workflow_context?.kind === 'lifecycle_review' && task.plant_id ? (
                          <Link
                            to={`/plots/${plotId}/plants/${task.plant_id}`}
                            state={{
                              pendingReviewTask: task,
                              backTo: buildCalendarReturnPath(plotId, selectedCalendarId, selectedDate),
                              backLabel: selectedDate ? `Grįžti į ${formatDate(selectedDate)}` : 'Grįžti į kalendorių',
                            }}
                          >
                            <Button variant="secondary">Atidaryti augalo peržiūrą</Button>
                          </Link>
                        ) : null}

                        {canEdit && task.status === 'pending' && task.type === 'harvest' && task.plant_id ? (
                          <Link to={`/plots/${plotId}/harvests?plantId=${task.plant_id}&taskId=${task.id}&date=${task.date || ''}`}>
                            <Button variant="secondary">Registruoti derlių</Button>
                          </Link>
                        ) : null}

                        {canEdit && task.status === 'pending' && task.workflow_context?.kind !== 'lifecycle_review' && task.type !== 'harvest' ? (
                          <ActionRow>
                            <Button
                              onClick={() => handleTaskAction(task.id, 'complete')}
                              disabled={submitting || hasInventoryShortage || task.can_complete === false}
                            >
                              {isReplenishmentTask ? 'Atlikti papildymą' : 'Atlikti'}
                            </Button>
                            <Button variant="danger" onClick={() => handleTaskAction(task.id, 'reject')} disabled={submitting}>
                              Atmesti
                            </Button>
                          </ActionRow>
                        ) : null}
                      </div>

                      {(taskDetails.length > 0 || resourceRequirements.length > 0 || missingResources.length > 0) ? (
                        <details className="task-card-details">
                          <summary>Detalės</summary>
                          <div className="task-card-detail-stack">
                            {taskDetails.length > 0 ? (
                              <DefinitionList className="task-card-detail-list" items={taskDetails} />
                            ) : null}

                            {resourceRequirements.length > 0 ? (
                              <div className="task-card-detail-block">
                                <strong>Resursų poreikis</strong>
                                <div className="task-card-resource-list">
                                  {resourceRequirements.map((resource) => (
                                    <StatRow
                                      key={`${task.id}-${resource.id ?? resource.name}`}
                                      className="task-card-resource-row"
                                      label={translateVisibleText(resource.name ?? resource.resource_name)}
                                      value={`Reikia ${safeNumber(resource.required_quantity, resource.type === 'tool' ? 0 : 2)} ${formatInventoryUnit(resource.unit)}${resource.available_quantity !== null && resource.available_quantity !== undefined
                                        ? ` / turima ${safeNumber(resource.available_quantity, resource.type === 'tool' ? 0 : 2)}`
                                        : ''}${resource.is_shortage || resource.shortage_quantity > 0
                                        ? ` / trūksta ${safeNumber(resource.shortage_quantity, resource.type === 'tool' ? 0 : 2)}`
                                        : ''}`}
                                    />
                                  ))}
                                </div>
                              </div>
                            ) : null}

                            {missingResources.length > 0 ? (
                              <div className="task-card-detail-block">
                                <strong>Trūkumai</strong>
                                <div className="task-card-resource-list">
                                  {missingResources.map((resource) => (
                                    <StatRow
                                      key={`${task.id}-missing-${resource.id ?? resource.resource_name}`}
                                      className="task-card-resource-row stat-row-danger"
                                      label={translateVisibleText(resource.name ?? resource.resource_name)}
                                      value={`Trūksta ${safeNumber(resource.shortage_quantity, resource.type === 'tool' ? 0 : 2)} ${formatInventoryUnit(resource.unit)}`}
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
