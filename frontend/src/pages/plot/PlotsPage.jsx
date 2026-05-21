import { useDeferredValue, useState } from 'react'
import { Link } from 'react-router-dom'
import { MeasurementBadge, MapLayerControl } from '../../components/garden/GardenControls.jsx'
import PageHeader from '../../components/layout/PageHeader.jsx'
import PlotBoundaryMiniMap from '../../components/plot/PlotBoundaryMiniMap.jsx'
import {
  EmptyState,
  ErrorState,
  LoadingState,
} from '../../components/shared/StatusView.jsx'
import ActionRow from '../../components/ui/ActionRow.jsx'
import Button from '../../components/ui/Button.jsx'
import FilterBar from '../../components/ui/FilterBar.jsx'
import FormField from '../../components/ui/FormField.jsx'
import ResourceCard, {
  ResourceCardBody,
  ResourceCardFooter,
  ResourceCardHeader,
  ResourceCardMeta,
} from '../../components/ui/ResourceCard.jsx'
import ResponsiveList from '../../components/ui/ResponsiveList.jsx'
import SectionCard from '../../components/ui/SectionCard.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { api } from '../../lib/api.js'
import { formatAccessRole, formatDate, formatSquareMetersValue } from '../../lib/constants.js'
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

export default function PlotsPage() {
  const plotsState = useAsyncData(() => api.listPlots(), [], [])
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)

  const filteredPlots = plotsState.data.filter((plot) => {
    const needle = deferredSearch.trim().toLowerCase()
    if (!needle) return true
    return [plot.name, plot.city, plot.description, plot.access_role]
      .filter(Boolean)
      .some((value) => value.toLowerCase().includes(needle))
  })

  if (plotsState.loading) return <LoadingState title="Įkeliami sklypai..." />
  if (plotsState.error) return <ErrorState error={plotsState.error} onRetry={plotsState.reload} />

  return (
    <div className="page-stack">
      <PageHeader
        eyebrow="Sklypų registras"
        title="Sklypų planai"
        description="Peržiūrėkite daržo darbo sritis kaip suplanuotus objektus su plotu, zonomis, augalais ir greitais redaktoriaus veiksmais."
        meta={(
          <>
            <StatusBadge kind="ownership">{plotsState.data.length} sklypai iš viso</StatusBadge>
            <StatusBadge kind="selection" tone="neutral">{filteredPlots.length} atitinka filtrus</StatusBadge>
          </>
        )}
      />

      <div className="plots-layout">
        <SectionCard
          title="Peržiūrėti sklypus"
          description="Ieškokite pagal sklypo pavadinimą, miestą, aprašą arba prieigos teisę."
        >
          <FilterBar
            resultCount={filteredPlots.length}
            onClear={search ? () => setSearch('') : null}
          >
            <FormField id="plot-search" label="Ieškoti sklypo" className="plots-search-field">
              <div className="search-input-wrap">
                <span className="search-icon"><SearchIcon /></span>
                <input
                  id="plot-search"
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder="Pavadinimas, miestas, aprašas arba prieigos teisė"
                />
              </div>
            </FormField>
          </FilterBar>

          {filteredPlots.length === 0 ? (
            <EmptyState
              title="Sklypų nerasta"
              description="Sukurkite pirmą sklypą arba pakeiskite paiešką, kad matytumėte daugiau rezultatų."
            />
          ) : (
            <ResponsiveList className="plot-grid plot-browser-grid" ariaLabel="Sklypų sąrašas">
              {filteredPlots.map((plot) => (
                <ResourceCard key={plot.id} className="plot-browser-card">
                  <PlotBoundaryMiniMap
                    className="plot-browser-preview"
                    plotName={plot.name}
                    plotGeometry={plot.geometry}
                  />
                  <ResourceCardHeader
                    title={plot.name}
                    subtitle={plot.city || 'Miestas nenurodytas'}
                    badge={<StatusBadge kind="ownership">{formatAccessRole(plot.access_role ?? 'viewer')}</StatusBadge>}
                  />

                  <ResourceCardMeta>
                    <MapLayerControl
                      title={plot.city || 'Miestas nenurodytas'}
                      items={[
                        { id: 'boundary', label: formatSquareMetersValue(plot.plot_size, 2), active: true, color: '#47633b' },
                        { id: 'zones', label: `${plot.plant_zones_count ?? 0} zonos`, active: Number(plot.plant_zones_count ?? 0) > 0, color: '#b9683f' },
                        { id: 'plants', label: `${plot.plants_count ?? 0} augalai`, active: Number(plot.plants_count ?? 0) > 0, color: '#237d52' },
                      ]}
                    />
                  </ResourceCardMeta>

                  <ResourceCardBody>
                    <p className="muted plot-browser-copy">
                      {plot.description || 'Aprašo dar nėra.'}
                    </p>

                    <div className="plot-browser-metrics">
                      <MeasurementBadge label="Sukurta" value={formatDate(plot.creation_date)} tone="earth" />
                      <MeasurementBadge label="Plotas" value={formatSquareMetersValue(plot.plot_size, 2)} tone="field" />
                    </div>
                  </ResourceCardBody>

                  <ResourceCardFooter>
                    <ActionRow className="resource-action-row">
                      <Link to={`/plots/${plot.id}`}>
                        <Button variant="ghost" size="sm"><ArrowIcon /> Atidaryti</Button>
                      </Link>
                      <Link to={`/plots/${plot.id}/calendar`}>
                        <Button variant="secondary" size="sm"><CalendarIcon /> Kalendorius</Button>
                      </Link>
                      <Link to={`/plots/${plot.id}/analytics`}>
                        <Button variant="secondary" size="sm"><BarChartIcon /> Analitika</Button>
                      </Link>
                      <Link to={`/plots/${plot.id}/edit`}>
                        <Button variant="secondary" size="sm"><PencilIcon /> Redaguoti</Button>
                      </Link>
                    </ActionRow>
                  </ResourceCardFooter>
                </ResourceCard>
              ))}
            </ResponsiveList>
          )}
        </SectionCard>

        <div className="plots-form-panel">
          <SectionCard
            title="Sukurti sklypą"
            description="Pažymėkite sklypo ribą žemėlapyje, nubraižykite vidines zonas ir išsaugokite planą."
          >
            <div className="page-stack">
              <div className="plot-create-entry-steps">
                <span>1. Riba</span>
                <span>2. Zonos</span>
                <span>3. Suvestinė</span>
              </div>
              <ActionRow>
                <Link to="/plots/new">
                  <Button><PlusIcon /> Sukurti sklypą</Button>
                </Link>
              </ActionRow>
            </div>
          </SectionCard>
        </div>
      </div>
    </div>
  )
}
