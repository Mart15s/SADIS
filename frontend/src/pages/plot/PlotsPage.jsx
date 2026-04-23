import { useDeferredValue, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
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
import { formatDate, safeNumber } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

function SearchIcon() {
  return (
    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="8.5" cy="8.5" r="5" />
      <path d="M17 17l-3.5-3.5" />
    </svg>
  )
}

function PlusIcon() {
  return (
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" style={{ width: '0.9rem', height: '0.9rem' }}>
      <path d="M8 2v12M2 8h12" />
    </svg>
  )
}

function ArrowIcon() {
  return (
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" style={{ width: '0.8rem', height: '0.8rem' }}>
      <path d="M3 8h10M9 4l4 4-4 4" />
    </svg>
  )
}

function CalendarIcon() {
  return (
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" style={{ width: '0.85rem', height: '0.85rem' }}>
      <rect x="2" y="3" width="12" height="11" rx="2" />
      <path d="M5 1v3M11 1v3M2 7h12" />
    </svg>
  )
}

function BarChartIcon() {
  return (
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" style={{ width: '0.85rem', height: '0.85rem' }}>
      <path d="M2 12V7M6 12V4M10 12V9M14 12V6" />
    </svg>
  )
}

function PencilIcon() {
  return (
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" style={{ width: '0.85rem', height: '0.85rem' }}>
      <path d="M11.5 2.5l2 2-8 8H3.5v-2l8-8z" />
    </svg>
  )
}

const emptyPlotForm = {
  name: '',
  city: '',
  plot_size: '',
  creation_date: new Date().toISOString().slice(0, 10),
  description: '',
  share: false,
}

export default function PlotsPage() {
  const plotsState = useAsyncData(() => api.listPlots(), [], [])
  const [form, setForm] = useState(emptyPlotForm)
  const [search, setSearch] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [toastMessage, setToastMessage] = useState('')
  const deferredSearch = useDeferredValue(search)

  useEffect(() => {
    if (!toastMessage) {
      return undefined
    }

    const timeoutId = window.setTimeout(() => setToastMessage(''), 2400)
    return () => window.clearTimeout(timeoutId)
  }, [toastMessage])

  const filteredPlots = plotsState.data.filter((plot) => {
    const needle = deferredSearch.trim().toLowerCase()
    if (!needle) return true
    return [plot.name, plot.city, plot.description, plot.access_role]
      .filter(Boolean)
      .some((value) => value.toLowerCase().includes(needle))
  })

  function handleChange(event) {
    const { name, type, checked, value } = event.target
    setForm((current) => ({
      ...current,
      [name]: type === 'checkbox' ? checked : value,
    }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    try {
      const created = await api.createPlot({
        ...form,
        plot_size: Number(form.plot_size),
      })
      plotsState.setData((current) => [{
        ...created,
        access_role: 'owner',
        plant_zones_count: 0,
        plants_count: 0,
      }, ...current])
      setForm(emptyPlotForm)
      setToastMessage(`Created ${created.name}.`)
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  if (plotsState.loading) return <LoadingState title="Loading plots..." />
  if (plotsState.error) return <ErrorState error={plotsState.error} onRetry={plotsState.reload} />

  return (
    <div className="page-stack">
      <PageHeader
        eyebrow="Garden portfolio"
        title="Plots"
        description="Browse plots, review ownership, and create new workspaces without burying the main list in oversized empty panels."
        meta={(
          <>
            <StatusBadge kind="ownership">{plotsState.data.length} total plots</StatusBadge>
            <StatusBadge kind="selection" tone="neutral">{filteredPlots.length} matching current filters</StatusBadge>
          </>
        )}
      />

      <SuccessToast message={toastMessage} onDismiss={() => setToastMessage('')} />

      <div className="plots-layout">
        <SectionCard
          title="Browse plots"
          description="Search by plot name, city, description, or access role. The list scales to the available content instead of floating in an oversized content well."
        >
          <div className="field plots-search-field">
            <label htmlFor="plot-search">Search plots</label>
            <div className="search-input-wrap">
              <span className="search-icon"><SearchIcon /></span>
              <input
                id="plot-search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Name, city, description, or access role"
              />
            </div>
          </div>

          {filteredPlots.length === 0 ? (
            <EmptyState
              title="No plots found"
              description="Create your first plot or change the current search to reveal more results."
            />
          ) : (
            <div className="plot-grid plot-browser-grid">
              {filteredPlots.map((plot) => (
                <article key={plot.id} className="plot-browser-card">
                  <div className="list-head">
                    <h3>{plot.name}</h3>
                    <StatusBadge kind="ownership">{plot.access_role ?? 'viewer'}</StatusBadge>
                  </div>

                  <span className="muted">
                    {plot.city} | {safeNumber(plot.plot_size, 2)} m2
                  </span>

                  <p className="muted plot-browser-copy">
                    {plot.description || 'No description yet.'}
                  </p>

                  <div className="meta-cluster">
                    <span>{plot.plant_zones_count ?? 0} zones</span>
                    <span>{plot.plants_count ?? 0} plants</span>
                    <span>Created {formatDate(plot.creation_date)}</span>
                  </div>

                  <ActionRow>
                    <Link to={`/plots/${plot.id}`}>
                      <Button variant="ghost"><ArrowIcon /> Open</Button>
                    </Link>
                    <Link to={`/plots/${plot.id}/calendar`}>
                      <Button variant="secondary"><CalendarIcon /> Calendar</Button>
                    </Link>
                    <Link to={`/plots/${plot.id}/analytics`}>
                      <Button variant="secondary"><BarChartIcon /> Analytics</Button>
                    </Link>
                    <Link to={`/plots/${plot.id}/edit`}>
                      <Button variant="secondary"><PencilIcon /> Edit</Button>
                    </Link>
                  </ActionRow>
                </article>
              ))}
            </div>
          )}
        </SectionCard>

        <div className="plots-form-panel">
          <form onSubmit={handleSubmit}>
            <FormSection
              title="Create plot"
              description="Capture the essentials here, then refine geometry and zones inside the plot workspace."
            >
              <div className="input-grid">
                <div className="field">
                  <label htmlFor="plot-name">Name</label>
                  <input id="plot-name" name="name" value={form.name} onChange={handleChange} required />
                </div>

                <div className="field">
                  <label htmlFor="plot-city">City</label>
                  <input id="plot-city" name="city" value={form.city} onChange={handleChange} required />
                </div>

                <div className="field">
                  <label htmlFor="plot-size">Plot size (m2)</label>
                  <input
                    id="plot-size"
                    name="plot_size"
                    type="number"
                    min="0.01"
                    step="0.01"
                    value={form.plot_size}
                    onChange={handleChange}
                    required
                  />
                </div>

                <div className="field">
                  <label htmlFor="creation-date">Creation date</label>
                  <input
                    id="creation-date"
                    name="creation_date"
                    type="date"
                    value={form.creation_date}
                    onChange={handleChange}
                    required
                  />
                </div>

                <div className="field field-span-2">
                  <label htmlFor="plot-description">Description</label>
                  <textarea id="plot-description" name="description" value={form.description} onChange={handleChange} />
                </div>

                <label className="field field-span-2">
                  <span>Community sharing</span>
                  <select
                    name="share"
                    value={String(form.share)}
                    onChange={(event) => {
                      setForm((current) => ({
                        ...current,
                        share: event.target.value === 'true',
                      }))
                    }}
                  >
                    <option value="false">Private</option>
                    <option value="true">Shared</option>
                  </select>
                </label>
              </div>

              {error ? <span className="field-error">{error}</span> : null}

              {submitting ? (
                <ProcessingState
                  title="Creating plot"
                  description="Saving metadata and preparing the new workspace."
                  steps={['Validating form', 'Creating plot', 'Refreshing list']}
                  compact
                />
              ) : null}

              <ActionRow>
                <Button type="submit" loading={submitting}>
                  {submitting ? 'Creating plot' : <><PlusIcon /> Create plot</>}
                </Button>
              </ActionRow>
            </FormSection>
          </form>
        </div>
      </div>
    </div>
  )
}
