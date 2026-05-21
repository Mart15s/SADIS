import { Link } from 'react-router-dom'
import { GardenTimeline, MeasurementBadge, PlantStatusBadge } from '../../components/garden/GardenControls.jsx'
import PageHeader from '../../components/layout/PageHeader.jsx'
import PlotBoundaryMiniMap from '../../components/plot/PlotBoundaryMiniMap.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import ActionRow from '../../components/ui/ActionRow.jsx'
import Button from '../../components/ui/Button.jsx'
import SectionCard from '../../components/ui/SectionCard.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { useAuth } from '../../context/AuthContext.jsx'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'
import {
  formatAccessRole,
  formatDate,
  formatInventoryType,
  formatQuantity,
  formatSquareMetersValue,
} from '../../lib/constants.js'

export default function DashboardPage() {
  const { isAuthenticated } = useAuth()
  const { data, loading, error, reload } = useAsyncData(
    async () => {
      if (!isAuthenticated) {
        return { plots: [], inventory: [], plants: [] }
      }

      const [plots, inventory, plants] = await Promise.all([
        api.listPlots(),
        api.listInventory(),
        api.listManagedPlants(),
      ])

      return { plots, inventory, plants }
    },
    [isAuthenticated],
    { plots: [], inventory: [], plants: [] },
  )

  if (!isAuthenticated) {
    return (
      <div className="page-stack">
        <PageHeader
          eyebrow="Sveiki"
          title="Daržo darbai vienoje darbo srityje"
          description="Planuokite sklypus, stebėkite augalų būklę ir valdykite daržo darbus aiškioje naudotojo sąsajoje."
          actions={(
            <>
              <Link to="/login">
                <Button variant="secondary">Prisijungti</Button>
              </Link>
              <Link to="/register">
                <Button>Registruotis</Button>
              </Link>
            </>
          )}
        />

        <SectionCard title="Kas pasiekiama prisijungus" description="Sistema padeda sprendimus priimti remiantis sklypų, augalų ir inventoriaus duomenimis.">
          <ul>
            <li>Kurkite sklypus ir vizualiai tvarkykite augalų zonas.</li>
            <li>Sekite augalus, būklės istoriją, rotaciją ir kalendoriaus darbus.</li>
            <li>Valdykite inventorių, dalykitės sklypais pagal roles, registruokite derlių ir eksportuokite PDF ataskaitas.</li>
          </ul>
          <ActionRow>
            <Link to="/forgot-password">
              <Button variant="secondary">Atkurti slaptažodį</Button>
            </Link>
          </ActionRow>
        </SectionCard>
      </div>
    )
  }

  if (loading) {
    return <LoadingState title="Įkeliama daržo apžvalga..." />
  }

  if (error) {
    return <ErrorState error={error} onRetry={reload} />
  }

  const totalZones = data.plots.reduce((sum, plot) => sum + Number(plot.plant_zones_count ?? 0), 0)
  const totalPlants = data.plots.reduce((sum, plot) => sum + Number(plot.plants_count ?? 0), 0)
  const lowInventory = data.inventory.filter((item) => Number(item.quantity ?? 0) <= 0)
  const visiblePlants = data.plants.slice(0, 5)
  const timelineItems = [
    ...data.plots.slice(0, 3).map((plot) => ({
      id: `plot-${plot.id}`,
      label: plot.name,
      meta: `${plot.city || 'Miestas nenurodytas'} - sukurta ${formatDate(plot.creation_date)}`,
      tone: 'leaf',
    })),
    ...lowInventory.slice(0, 2).map((item) => ({
      id: `inventory-${item.id}`,
      label: `${item.name} reikia papildyti`,
      meta: `${formatInventoryType(item.type)} - liko ${formatQuantity(item.quantity, item.unit)}`,
      tone: 'amber',
    })),
  ]

  return (
    <div className="page-stack dashboard-workbench">
      <PageHeader
        eyebrow="Daržo valdymas"
        title="Planavimo lenta"
        description="Darbo sritis apima sklypus, zonas, augalų būklę, nuo oro priklausančius darbus ir inventoriaus pasirengimą."
        meta={(
          <>
            <StatusBadge kind="connection">Daržo duomenys sinchronizuoti</StatusBadge>
            <StatusBadge kind="ownership">{data.plots.length > 0 ? `${data.plots.length} aktyvūs sklypai` : 'Sklypų dar nėra'}</StatusBadge>
          </>
        )}
        actions={(
          <>
            <Link to="/account">
              <Button variant="secondary">Paskyra</Button>
            </Link>
            <Link to="/plots">
              <Button>Atidaryti sklypus</Button>
            </Link>
            <Link to="/inventory">
              <Button variant="secondary">Inventorius</Button>
            </Link>
          </>
        )}
      />

      <section className="dashboard-map-band">
        <div className="dashboard-map-copy">
          <span className="workspace-section-eyebrow">Aktyvus daržo modelis</span>
          <h2>Sklypai, zonos, augalai ir darbai vienoje valdymo apžvalgoje.</h2>
          <p>
            Ši apžvalga remiasi realiais planavimo objektais: sklypų planais, pažymėtomis zonomis,
            kalendoriaus darbais ir priežiūrai reikalingomis atsargomis.
          </p>
        </div>
        <div className="dashboard-measurement-strip" aria-label="Daržo suvestinė">
          <MeasurementBadge label="Sklypai" value={data.plots.length} tone="earth" />
          <MeasurementBadge label="Zonos" value={totalZones} tone="field" />
          <MeasurementBadge label="Augalai" value={totalPlants} tone="leaf" />
          <MeasurementBadge label="Inventorius" value={data.inventory.length} tone="amber" />
        </div>
      </section>

      <section className="dashboard-context-grid">
        <SectionCard
          title="Aktyvūs sklypai"
          description=""
          actions={(
            <Link to="/plots">
              <Button variant="ghost">Rodyti visus sklypus</Button>
            </Link>
          )}
          className="dashboard-active-plots"
        >
          {data.plots.length > 0 ? (
            <section className="dashboard-plot-list">
              {data.plots.slice(0, 3).map((plot) => (
                <article key={plot.id} className="dashboard-plot-row">
                  <PlotBoundaryMiniMap
                    className="dashboard-plot-preview"
                    plotName={plot.name}
                    plotGeometry={plot.geometry}
                  />
                  <div className="dashboard-plot-row-copy">
                    <div className="list-head">
                      <strong>{plot.name}</strong>
                      <StatusBadge kind="ownership">{formatAccessRole(plot.access_role ?? 'viewer')}</StatusBadge>
                    </div>
                    <span className="muted">
                      {plot.city || 'Miestas nenurodytas'} / {formatSquareMetersValue(plot.plot_size, 2)}
                    </span>
                    <div className="dashboard-mini-metrics">
                      <MeasurementBadge label="Zonos" value={plot.plant_zones_count ?? 0} tone="field" />
                      <MeasurementBadge label="Augalai" value={plot.plants_count ?? 0} tone="leaf" />
                    </div>
                    <ActionRow>
                      <Link to={`/plots/${plot.id}`}>
                        <Button variant="ghost">Atidaryti planą</Button>
                      </Link>
                      <Link to={`/plots/${plot.id}/calendar`}>
                        <Button variant="secondary">Kalendorius</Button>
                      </Link>
                    </ActionRow>
                  </div>
                </article>
              ))}
            </section>
          ) : (
            <EmptyState
              title="Sklypų dar nėra"
              description="Sukurkite pirmą sklypą, kad galėtumėte naudoti zonas, augalus, kalendorius ir analitiką."
              action={(
                <Link to="/plots">
                  <Button>Sukurti pirmą sklypą</Button>
                </Link>
              )}
            />
          )}
        </SectionCard>

        <div className="dashboard-side-stack">
          <SectionCard title="Šiandienos daržo darbai" description="">
            <div className="dashboard-task-lane">
              {data.plots.slice(0, 3).map((plot) => (
                <Link key={`task-${plot.id}`} to={`/plots/${plot.id}/calendar`} className="dashboard-task-row">
                  <span className="dashboard-task-date">Šiandien</span>
                  <strong>{plot.name}</strong>
                  <span>Atidaryti pažymėtų zonų kalendoriaus darbus</span>
                </Link>
              ))}
              {data.plots.length === 0 ? (
                <p className="muted">Sukurkite sklypą ir sugeneruokite kalendorių, kad čia matytumėte suplanuotus darbus.</p>
              ) : null}
            </div>
          </SectionCard>

          <SectionCard title="Oro įtaka" description="">
            <div className="dashboard-weather-stack">
              <span className="weather-rule-chip">Lietus gali praleisti laistymą</span>
              <span className="weather-rule-chip">Šalna prideda apsaugos darbus</span>
              <span className="weather-rule-chip">Karštis didina laistymo poreikį</span>
              <span className="weather-rule-chip">Vėjas gali sukelti apsaugos užduotis</span>
            </div>
          </SectionCard>
        </div>
      </section>

      <section className="dashboard-context-grid dashboard-context-grid-secondary">
        <SectionCard title="Augalų būsenos" description="">
          {visiblePlants.length > 0 ? (
            <div className="dashboard-plant-list">
              {visiblePlants.map((plant) => (
                <Link key={plant.id} to={`/plants/${plant.id}`} className="dashboard-plant-row">
                  <div>
                    <strong>{plant.name}</strong>
                    <span>{plant.plot?.name ?? 'Nežinomas sklypas'} - {plant.plant_zone?.name ?? plant.plantZone?.name ?? 'Zona nenurodyta'}</span>
                  </div>
                  <PlantStatusBadge status={plant.condition ?? plant.lifecycle_phase} careLinked={plant.has_plant_care} />
                </Link>
              ))}
            </div>
          ) : (
            <EmptyState title="Augalų įrašų nėra" description="Pridėkite pasodintus augalus augalų darbo srityje arba tiesiai iš sklypo zonų." />
          )}
        </SectionCard>

        <SectionCard title="Planavimo istorijos suvestinė" description="">
          <GardenTimeline
            items={timelineItems}
            emptyText="Sukurkite sklypą, nubraižykite zonas arba pakoreguokite inventorių, kad pradėtumėte kaupti planavimo istoriją."
          />
        </SectionCard>
      </section>
    </div>
  )
}
