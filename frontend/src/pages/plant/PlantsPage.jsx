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
import { formatPlantType } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'
import { useDebouncedValue } from '../../lib/hooks/useDebouncedValue.js'

const VIEW_OPTIONS = [
  { id: 'existing', label: 'Pasodinti augalai' },
  { id: 'catalog', label: 'Augalų katalogas' },
]

function careStatusLabel(plant) {
  return plant.has_plant_care ? 'Susieta' : 'Trūksta'
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
    const label = activeView === 'catalog' ? 'katalogo augalą' : 'augalą'
    if (!window.confirm(`Ar pašalinti ${label} „${entry.name}“? Šio veiksmo atšaukti nepavyks.`)) {
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
    return <LoadingState title={activeView === 'catalog' ? 'Įkeliamas augalų katalogas...' : 'Įkeliami augalai...'} />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  const resultCount = activeView === 'catalog'
    ? pageState.data.catalogPlants.length
    : pageState.data.plants.length

  const managedPlantColumns = [
    { key: 'name', label: 'Pavadinimas', render: (plant) => plant.name },
    { key: 'type', label: 'Tipas', render: (plant) => formatPlantType(plant.plant_type) },
    { key: 'plot', label: 'Sklypas', render: (plant) => plant.plot?.name ?? 'Nežinomas sklypas' },
    { key: 'zone', label: 'Zona', render: (plant) => plant.plant_zone?.name ?? plant.plantZone?.name ?? 'Nežinoma zona' },
    { key: 'catalog', label: 'Katalogas', render: (plant) => plant.catalog_plant?.name ?? plant.catalogPlant?.name ?? 'Rankinis įrašas' },
    {
      key: 'care',
      label: 'Priežiūra',
      render: (plant) => <PlantStatusBadge status={careStatusLabel(plant)} careLinked={plant.has_plant_care} />,
    },
    {
      key: 'actions',
      label: '',
      cellClassName: 'table-actions-cell',
      render: (plant) => renderManagedPlantVeiksmai(plant),
    },
  ]

  function getCanEditManagedPlant(plant) {
    const accessRole = accessByPlotId[String(plant.plot?.id ?? plant.fk_plot_id)] ?? null

    return ['owner', 'editor'].includes(accessRole)
  }

  function renderCatalogPlantVeiksmai(catalogPlant) {
    return (
      <div className="resource-action-row">
        <Link to={`/plants/catalog/${catalogPlant.id}`}>
          <Button variant="ghost" size="sm">Peržiūrėti</Button>
        </Link>
        <Link to={`/plants/catalog/${catalogPlant.id}/edit`}>
          <Button variant="secondary" size="sm">Redaguoti</Button>
        </Link>
        <Button
          variant="danger"
          size="sm"
          onClick={() => handleDelete(catalogPlant)}
          disabled={busyId === catalogPlant.id}
        >
          {busyId === catalogPlant.id ? 'Šalinama...' : 'Šalinti'}
        </Button>
      </div>
    )
  }

  function renderManagedPlantVeiksmai(plant) {
    const canEdit = getCanEditManagedPlant(plant)

    return (
      <div className="resource-action-row">
        <Link to={`/plants/${plant.id}`}>
          <Button variant="ghost" size="sm">Peržiūrėti</Button>
        </Link>
        {canEdit ? (
          <Link to={`/plants/${plant.id}/edit`}>
            <Button variant="secondary" size="sm">Redaguoti</Button>
          </Link>
        ) : null}
        {canEdit ? (
          <Button
            variant="danger"
            size="sm"
            onClick={() => handleDelete(plant)}
            disabled={busyId === plant.id}
          >
            {busyId === plant.id ? 'Šalinama...' : 'Šalinti'}
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
            <Badge tone="neutral">{formatPlantType(catalogPlant.plant_type)}</Badge>
          ) : null}
          <Badge tone={catalogPlant.has_plant_care ? 'success' : 'warning'}>
            {catalogPlant.has_plant_care ? 'Priežiūra susieta' : 'Priežiūros nėra'}
          </Badge>
          {catalogPlant.usage_count > 0 ? (
            <Badge tone="soft">{catalogPlant.usage_count} naudojimų</Badge>
          ) : null}
        </ResourceCardMeta>
        <ResourceCardFooter>
          {renderCatalogPlantVeiksmai(catalogPlant)}
        </ResourceCardFooter>
      </ResourceCard>
    )
  }

  function renderManagedPlantCard(plant) {
    return (
      <ResourceCard>
        <ResourceCardHeader
          title={plant.name}
          subtitle={plant.plot?.name ?? 'Nežinomas sklypas'}
          badge={<PlantStatusBadge status={careStatusLabel(plant)} careLinked={plant.has_plant_care} />}
        />
        <ResourceCardMeta>
          <Badge tone="neutral">{formatPlantType(plant.plant_type)}</Badge>
          <Badge tone="soft">{plant.plant_zone?.name ?? plant.plantZone?.name ?? 'Nežinoma zona'}</Badge>
          <Badge tone={plant.has_plant_care ? 'success' : 'warning'}>
            {plant.catalog_plant?.name ?? plant.catalogPlant?.name ?? 'Rankinis įrašas'}
          </Badge>
        </ResourceCardMeta>
        <ResourceCardBody>
          <dl className="resource-detail-grid">
            <div>
              <dt>Sklypas</dt>
              <dd>{plant.plot?.name ?? 'Nežinomas sklypas'}</dd>
            </div>
            <div>
              <dt>Zona</dt>
              <dd>{plant.plant_zone?.name ?? plant.plantZone?.name ?? 'Nežinoma zona'}</dd>
            </div>
          </dl>
        </ResourceCardBody>
        <ResourceCardFooter>
          {renderManagedPlantVeiksmai(plant)}
        </ResourceCardFooter>
      </ResourceCard>
    )
  }

  return (
    <div className="page-stack">
      <PageHeader
        title="Augalai"
        eyebrow="Augalų planavimas"
        description="Valdykite pasodintus augalus ir daugkartinį katalogą su priežiūros profiliais."
        meta={(
          <>
            <Badge tone="soft">{activeView === 'catalog' ? 'Katalogo darbo sritis' : 'Pasodinti augalai'}</Badge>
            <Badge tone="neutral">Rodoma: {resultCount}</Badge>
          </>
        )}
        actions={(
          <Link to={activeView === 'catalog' ? '/plants/catalog/new' : '/plants/new'}>
            <Button>{activeView === 'catalog' ? 'Pridėti katalogo augalą' : 'Pridėti augalą'}</Button>
          </Link>
        )}
      />

      <section className="panel page-stack plants-workspace-panel">
        <div className="plants-view-switch" role="tablist" aria-label="Augalų darbo srities vaizdai">
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
              {activeView === 'catalog' ? 'Ieškoti katalogo augalų' : 'Ieškoti augalų'}
            </label>
            <input
              id="plants-workspace-search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder={activeView === 'catalog'
                ? 'Pavadinimas, kanoninis vardas, šeima arba mokslinis pavadinimas'
                : 'Ieškoti pagal augalą, sklypą, zoną arba priežiūros pavadinimą'}
            />
          </div>
          <div className="resource-filter-summary" aria-live="polite">
            <span>{resultCount} rezultatai</span>
            {search ? (
              <Button variant="ghost" size="sm" onClick={() => setSearch('')}>
                Valyti
              </Button>
            ) : null}
          </div>
          <div className="plants-context-strip">
            <MeasurementBadge label="Vaizdas" value={activeView === 'catalog' ? 'Katalogas' : 'Pasodinti'} tone="leaf" />
            <MeasurementBadge label="Rodoma" value={resultCount} tone="earth" />
            <MeasurementBadge label="Priežiūros šaltinis" value="Perenual" tone="amber" />
          </div>
          <div className="inline-note">
            {activeView === 'catalog'
              ? 'Katalogo augalai saugo daugkartinę tapatybę ir bendrinamą priežiūrą. Kurkite juos rankiniu būdu arba importuokite iš Perenual.'
              : 'Čia rodomi pasodinti augalai prieinamuose sklypuose ir zonose. Susiekite juos su katalogu, kai reikia bendrinamos priežiūros.'}
          </div>
        </div>

        {actionError ? <span className="field-error">{actionError}</span> : null}

        {activeView === 'catalog' ? (
          pageState.data.catalogPlants.length === 0 ? (
            <EmptyState
              title="Katalogo augalų nerasta"
              description={debouncedSearch
                ? 'Pagal dabartinę paiešką katalogo augalų nerasta.'
                : 'Sukurkite pirmą katalogo augalą, kad galėtumėte bendrinti tapatybės ir priežiūros duomenis.'}
              action={(
                <Link to="/plants/catalog/new">
                  <Button>Kurti katalogo augalą</Button>
                </Link>
              )}
            />
          ) : (
            <ResponsiveList className="catalog-card-grid" ariaLabel="Katalogo augalų sąrašas">
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
              title="Augalų nerasta"
              description={debouncedSearch
                ? 'Pagal dabartinę paiešką augalų nerasta.'
                : 'Sukurkite pirmą pasodinto augalo įrašą, kad galėtumėte stebėti augalus sklypuose ir zonose.'}
              action={(
                <Link to="/plants/new">
                  <Button>Kurti augalą</Button>
                </Link>
              )}
            />
          ) : (
            <ResponsiveTable
              columns={managedPlantColumns}
              items={pageState.data.plants}
              getKey={(plant) => plant.id}
              renderCard={renderManagedPlantCard}
              tableLabel="Pasodintų augalų lentelė"
              cardListLabel="Pasodintų augalų sąrašas"
            />
          )
        )}
      </section>
    </div>
  )
}
