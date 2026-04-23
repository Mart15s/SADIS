import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Badge from '../../components/ui/Badge.jsx'
import Button from '../../components/ui/Button.jsx'
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

  return (
    <div className="page-stack">
      <PageHeader
        title="Plants"
        description="Manage planted records and the reusable plant catalog in one workspace."
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

        <div className="search-row">
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
            <div className="catalog-card-grid">
              {pageState.data.catalogPlants.map((catalogPlant) => (
                <div key={catalogPlant.id} className="catalog-plant-card">
                  <div className="catalog-plant-identity">
                    <h3 className="catalog-plant-name">{catalogPlant.name}</h3>
                    {catalogPlant.canonical_name ? (
                      <span className="catalog-plant-canonical">{catalogPlant.canonical_name}</span>
                    ) : null}
                  </div>

                  <div className="catalog-plant-meta">
                    {catalogPlant.plant_type ? (
                      <Badge tone="neutral">{catalogPlant.plant_type}</Badge>
                    ) : null}
                    <Badge tone={catalogPlant.has_plant_care ? 'success' : 'warning'}>
                      {catalogPlant.has_plant_care ? 'Care linked' : 'No care'}
                    </Badge>
                    {catalogPlant.usage_count > 0 ? (
                      <Badge tone="soft">{catalogPlant.usage_count} uses</Badge>
                    ) : null}
                  </div>

                  <div className="catalog-plant-actions">
                    <Link to={`/plants/catalog/${catalogPlant.id}`}>
                      <Button variant="ghost">View</Button>
                    </Link>
                    <Link to={`/plants/catalog/${catalogPlant.id}/edit`}>
                      <Button variant="secondary">Edit</Button>
                    </Link>
                    <Button
                      variant="danger"
                      onClick={() => handleDelete(catalogPlant)}
                      disabled={busyId === catalogPlant.id}
                    >
                      {busyId === catalogPlant.id ? 'Deleting...' : 'Delete'}
                    </Button>
                  </div>
                </div>
              ))}
            </div>
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
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Plot</th>
                    <th>Zone</th>
                    <th>Catalog</th>
                    <th>Plant care</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {pageState.data.plants.map((plant) => {
                    const accessRole = accessByPlotId[String(plant.plot?.id ?? plant.fk_plot_id)] ?? null
                    const canEdit = ['owner', 'editor'].includes(accessRole)

                    return (
                      <tr key={plant.id}>
                        <td>{plant.name}</td>
                        <td>{plant.plant_type}</td>
                        <td>{plant.plot?.name ?? 'Unknown plot'}</td>
                        <td>{plant.plant_zone?.name ?? plant.plantZone?.name ?? 'Unknown zone'}</td>
                        <td>{plant.catalog_plant?.name ?? plant.catalogPlant?.name ?? 'Manual record'}</td>
                        <td>{careStatusLabel(plant)}</td>
                        <td>
                          <div className="row-actions">
                            <Link to={`/plants/${plant.id}`}>
                              <Button variant="ghost">View</Button>
                            </Link>
                            {canEdit ? (
                              <Link to={`/plants/${plant.id}/edit`}>
                                <Button variant="secondary">Edit</Button>
                              </Link>
                            ) : null}
                            {canEdit ? (
                              <Button
                                variant="danger"
                                onClick={() => handleDelete(plant)}
                                disabled={busyId === plant.id}
                              >
                                {busyId === plant.id ? 'Deleting...' : 'Delete'}
                              </Button>
                            ) : null}
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )
        )}
      </section>
    </div>
  )
}
