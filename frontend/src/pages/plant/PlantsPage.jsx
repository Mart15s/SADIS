import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { MeasurementBadge, PlantStatusBadge } from '../../components/garden/GardenControls.jsx'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Badge from '../../components/ui/Badge.jsx'
import Button from '../../components/ui/Button.jsx'
import ResourceCard, {
  ResourceCardBody,
  ResourceCardFooter,
  ResourceCardHeader,
  ResourceCardMeta,
} from '../../components/ui/ResourceCard.jsx'
import ResponsiveList from '../../components/ui/ResponsiveList.jsx'
import ResponsiveTable from '../../components/ui/ResponsiveTable.jsx'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'
import { useDebouncedValue } from '../../lib/hooks/useDebouncedValue.js'

const VIEW_OPTIONS = [
  { id: 'existing', label: 'Existing Plants' },
  { id: 'catalog', label: 'Plant Catalog' },
]

function careStatusLabel(plant) {
  return plant.has_plant_care ? 'Linked' : 'Missing'
}

export default function PlantsPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const activeView = searchParams.get('view') === 'catalog' ? 'catalog' : 'existing'
  const [search, setSearch] = useState('')
  const [busyId, setBusyId] = useState(null)
  const [actionError, setActionError] = useState('')
  const debouncedSearch = useDebouncedValue(search)

  const pageState = useAsyncData(
    async () => {
      if (activeView === 'catalog') {
        return {
          plants: [],
          plots: [],
          catalogPlants: await api.listCatalogPlants(debouncedSearch ? { q: debouncedSearch } : {}),
        }
      }

      const [plants, plots] = await Promise.all([
        api.listManagedPlants(debouncedSearch ? { q: debouncedSearch } : {}),
        api.listPlots(),
      ])

      return {
        plants,
        plots,
        catalogPlants: [],
      }
    },
    [activeView, debouncedSearch],
    { plants: [], plots: [], catalogPlants: [] },
  )

  const accessByPlotId = useMemo(
    () => Object.fromEntries(pageState.data.plots.map((plot) => [String(plot.id), plot.access_role])),
    [pageState.data.plots],
  )

  useEffect(() => {
    setSearch('')
    setBusyId(null)
    setActionError('')
  }, [activeView])

  function setActiveView(nextView) {
    const nextParams = new URLSearchParams(searchParams)
    nextParams.set('view', nextView)
    setSearchParams(nextParams, { replace: true })
  }

  async function handleDelete(entry) {
    const label = activeView === 'catalog' ? 'catalog plant' : 'plant'
    if (!window.confirm(`Delete ${label} "${entry.name}"? This cannot be undone.`)) {
      return
    }

    setBusyId(entry.id)
    setActionError('')

    try {
      if (activeView === 'catalog') {
        await api.deleteCatalogPlant(entry.id)
        pageState.setData((current) => ({
          ...current,
          catalogPlants: current.catalogPlants.filter((catalogPlant) => catalogPlant.id !== entry.id),
        }))
      } else {
        await api.deleteManagedPlant(entry.id)
        pageState.setData((current) => ({
          ...current,
          plants: current.plants.filter((plant) => plant.id !== entry.id),
        }))
      }
    } catch (requestError) {
      setActionError(requestError.message)
    } finally {
      setBusyId(null)
    }
  }

  if (pageState.loading) {
    return <LoadingState title={activeView === 'catalog' ? 'Loading plant catalog...' : 'Loading plants...'} />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  const resultCount = activeView === 'catalog'
    ? pageState.data.catalogPlants.length
    : pageState.data.plants.length

  const managedPlantColumns = [
    { key: 'name', label: 'Name', render: (plant) => plant.name },
    { key: 'type', label: 'Type', render: (plant) => plant.plant_type },
    { key: 'plot', label: 'Plot', render: (plant) => plant.plot?.name ?? 'Unknown plot' },
    { key: 'zone', label: 'Zone', render: (plant) => plant.plant_zone?.name ?? plant.plantZone?.name ?? 'Unknown zone' },
    { key: 'catalog', label: 'Catalog', render: (plant) => plant.catalog_plant?.name ?? plant.catalogPlant?.name ?? 'Manual record' },
    {
      key: 'care',
      label: 'Plant care',
      render: (plant) => <PlantStatusBadge status={careStatusLabel(plant)} careLinked={plant.has_plant_care} />,
    },
    {
      key: 'actions',
      label: '',
      cellClassName: 'table-actions-cell',
      render: (plant) => renderManagedPlantActions(plant),
    },
  ]

  function getCanEditManagedPlant(plant) {
    const accessRole = accessByPlotId[String(plant.plot?.id ?? plant.fk_plot_id)] ?? null

    return ['owner', 'editor'].includes(accessRole)
  }

  function renderCatalogPlantActions(catalogPlant) {
    return (
      <div className="resource-action-row">
        <Link to={`/plants/catalog/${catalogPlant.id}`}>
          <Button variant="ghost" size="sm">View</Button>
        </Link>
        <Link to={`/plants/catalog/${catalogPlant.id}/edit`}>
          <Button variant="secondary" size="sm">Edit</Button>
        </Link>
        <Button
          variant="danger"
          size="sm"
          onClick={() => handleDelete(catalogPlant)}
          disabled={busyId === catalogPlant.id}
        >
          {busyId === catalogPlant.id ? 'Deleting...' : 'Delete'}
        </Button>
      </div>
    )
  }

  function renderManagedPlantActions(plant) {
    const canEdit = getCanEditManagedPlant(plant)

    return (
      <div className="resource-action-row">
        <Link to={`/plants/${plant.id}`}>
          <Button variant="ghost" size="sm">View</Button>
        </Link>
        {canEdit ? (
          <Link to={`/plants/${plant.id}/edit`}>
            <Button variant="secondary" size="sm">Edit</Button>
          </Link>
        ) : null}
        {canEdit ? (
          <Button
            variant="danger"
            size="sm"
            onClick={() => handleDelete(plant)}
            disabled={busyId === plant.id}
          >
            {busyId === plant.id ? 'Deleting...' : 'Delete'}
          </Button>
        ) : null}
      </div>
    )
  }

  function renderCatalogPlantCard(catalogPlant) {
    return (
      <ResourceCard className="catalog-plant-card">
        <ResourceCardHeader
          title={catalogPlant.name}
          subtitle={catalogPlant.canonical_name}
        />
        <ResourceCardMeta>
          {catalogPlant.plant_type ? (
            <Badge tone="neutral">{catalogPlant.plant_type}</Badge>
          ) : null}
          <Badge tone={catalogPlant.has_plant_care ? 'success' : 'warning'}>
            {catalogPlant.has_plant_care ? 'Care linked' : 'No care'}
          </Badge>
          {catalogPlant.usage_count > 0 ? (
            <Badge tone="soft">{catalogPlant.usage_count} uses</Badge>
          ) : null}
        </ResourceCardMeta>
        <ResourceCardFooter>
          {renderCatalogPlantActions(catalogPlant)}
        </ResourceCardFooter>
      </ResourceCard>
    )
  }

  function renderManagedPlantCard(plant) {
    return (
      <ResourceCard>
        <ResourceCardHeader
          title={plant.name}
          subtitle={plant.plot?.name ?? 'Unknown plot'}
          badge={<PlantStatusBadge status={careStatusLabel(plant)} careLinked={plant.has_plant_care} />}
        />
        <ResourceCardMeta>
          <Badge tone="neutral">{plant.plant_type}</Badge>
          <Badge tone="soft">{plant.plant_zone?.name ?? plant.plantZone?.name ?? 'Unknown zone'}</Badge>
          <Badge tone={plant.has_plant_care ? 'success' : 'warning'}>
            {plant.catalog_plant?.name ?? plant.catalogPlant?.name ?? 'Manual record'}
          </Badge>
        </ResourceCardMeta>
        <ResourceCardBody>
          <dl className="resource-detail-grid">
            <div>
              <dt>Plot</dt>
              <dd>{plant.plot?.name ?? 'Unknown plot'}</dd>
            </div>
            <div>
              <dt>Zone</dt>
              <dd>{plant.plant_zone?.name ?? plant.plantZone?.name ?? 'Unknown zone'}</dd>
            </div>
          </dl>
        </ResourceCardBody>
        <ResourceCardFooter>
          {renderManagedPlantActions(plant)}
        </ResourceCardFooter>
      </ResourceCard>
    )
  }

  return (
    <div className="page-stack">
      <PageHeader
        title="Plants"
        eyebrow="Plant planning"
        description="Manage planted instances and the reusable catalog as care profiles connected to plot zones."
        meta={(
          <>
            <Badge tone="soft">{activeView === 'catalog' ? 'Catalog workspace' : 'Managed plants'}</Badge>
            <Badge tone="neutral">{resultCount} visible</Badge>
          </>
        )}
        actions={(
          <Link to={activeView === 'catalog' ? '/plants/catalog/new' : '/plants/new'}>
            <Button>{activeView === 'catalog' ? 'Add Catalog Plant' : 'Add New Plant'}</Button>
          </Link>
        )}
      />

      <section className="panel page-stack plants-workspace-panel">
        <div className="plants-view-switch" role="tablist" aria-label="Plant workspace views">
          {VIEW_OPTIONS.map((option) => (
            <button
              key={option.id}
              type="button"
              role="tab"
              aria-selected={activeView === option.id}
              className={`plants-view-switch-button ${activeView === option.id ? 'is-active' : ''}`.trim()}
              onClick={() => setActiveView(option.id)}
            >
              {option.label}
            </button>
          ))}
        </div>

        <div className="resource-filter-bar">
          <div className="field plants-search-field">
            <label htmlFor="plants-workspace-search">
              {activeView === 'catalog' ? 'Search catalog plants' : 'Search plants'}
            </label>
            <input
              id="plants-workspace-search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder={activeView === 'catalog'
                ? 'Name, canonical name, family, or scientific name'
                : 'Search by plant, plot, zone, or care name'}
            />
          </div>
          <div className="resource-filter-summary" aria-live="polite">
            <span>{resultCount} results</span>
            {search ? (
              <Button variant="ghost" size="sm" onClick={() => setSearch('')}>
                Clear
              </Button>
            ) : null}
          </div>
          <div className="plants-context-strip">
            <MeasurementBadge label="View" value={activeView === 'catalog' ? 'Catalog' : 'Planted'} tone="leaf" />
            <MeasurementBadge label="Visible" value={resultCount} tone="earth" />
            <MeasurementBadge label="Care source" value="Perenual cache" tone="amber" />
          </div>
          <div className="inline-note">
            {activeView === 'catalog'
              ? 'Catalog plants store reusable identity and shared care data. Create them manually or import them from Perenual, then reuse them across plots and zones.'
              : 'These are planted instances across accessible plots and zones. Link them to catalog plants when you want shared care and identity data.'}
          </div>
        </div>

        {actionError ? <span className="field-error">{actionError}</span> : null}

        {activeView === 'catalog' ? (
          pageState.data.catalogPlants.length === 0 ? (
            <EmptyState
              title="No catalog plants found"
              description={debouncedSearch
                ? 'No catalog plants matched the current search.'
                : 'Create the first reusable catalog plant to start sharing plant identity and care data.'}
              action={(
                <Link to="/plants/catalog/new">
                  <Button>Create Catalog Plant</Button>
                </Link>
              )}
            />
          ) : (
            <ResponsiveList className="catalog-card-grid" ariaLabel="Catalog plant list">
              {pageState.data.catalogPlants.map((catalogPlant) => (
                <div key={catalogPlant.id}>
                  {renderCatalogPlantCard(catalogPlant)}
                </div>
              ))}
            </ResponsiveList>
          )
        ) : (
          pageState.data.plants.length === 0 ? (
            <EmptyState
              title="No plants found"
              description={debouncedSearch
                ? 'No plants matched the current search.'
                : 'Create the first planted record to start tracking plants across your accessible plots and zones.'}
              action={(
                <Link to="/plants/new">
                  <Button>Create Plant</Button>
                </Link>
              )}
            />
          ) : (
            <ResponsiveTable
              columns={managedPlantColumns}
              items={pageState.data.plants}
              getKey={(plant) => plant.id}
              renderCard={renderManagedPlantCard}
              tableLabel="Managed plants table"
              cardListLabel="Managed plants list"
            />
          )
        )}
      </section>
    </div>
  )
}
