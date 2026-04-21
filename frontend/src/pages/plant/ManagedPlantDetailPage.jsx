import { Link, useLocation, useParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'
import { formatDate } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

function valueOrFallback(value, suffix = '') {
  if (value === null || value === undefined || value === '') {
    return 'Not set'
  }

  return `${value}${suffix}`
}

export default function ManagedPlantDetailPage() {
  const location = useLocation()
  const { plantId } = useParams()

  const pageState = useAsyncData(
    async () => {
      const [plant, plots] = await Promise.all([
        api.getManagedPlant(plantId),
        api.listPlots(),
      ])

      const accessRole = plots.find((entry) => String(entry.id) === String(plant.plot?.id ?? plant.fk_plot_id))?.access_role ?? null

      return { plant, accessRole }
    },
    [plantId],
    { plant: null, accessRole: null },
  )

  if (pageState.loading) {
    return <LoadingState title="Loading plant details..." />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  if (!pageState.data.plant) {
    return <EmptyState title="Plant not found" description="The requested plant could not be loaded." />
  }

  const plant = pageState.data.plant
  const care = plant.plantCare ?? plant.plant_care ?? null
  const catalogPlant = plant.catalog_plant ?? plant.catalogPlant ?? null
  const canEdit = ['owner', 'editor'].includes(pageState.data.accessRole)
  const notice = location.state?.notice

  return (
    <div className="page-stack">
      <PageHeader
        title={plant.name}
        description={`${plant.plant_type} planted instance in ${plant.plot?.name ?? 'Unknown plot'} / ${plant.plant_zone?.name ?? 'Unknown zone'}`}
        actions={(
          <>
            <Link to="/plants">
              <Button variant="secondary">Back</Button>
            </Link>
            {plant.plot?.id ? (
              <Link to={`/plots/${plant.plot.id}`}>
                <Button variant="ghost">Open Plot</Button>
              </Link>
            ) : null}
            {canEdit ? (
              <Link to={`/plants/${plant.id}/edit`}>
                <Button>Edit</Button>
              </Link>
            ) : null}
          </>
        )}
      />

      {notice ? <div className="inline-note">{notice}</div> : null}

      <div className="detail-grid">
        <section className="panel page-stack">
          <div>
            <h3 className="section-title">Basic Information</h3>
            <p className="section-copy">Core plant record data used by the list view and plot workspace.</p>
          </div>

          <div className="meta-cluster">
            <span>Type {plant.plant_type}</span>
            <span>Condition {plant.condition}</span>
            <span>Planted {formatDate(plant.plant_date)}</span>
            <span>Disease {plant.disease ? 'Yes' : 'No'}</span>
            <span>Plot {plant.plot?.name ?? 'Unknown'}</span>
            <span>Zone {plant.plant_zone?.name ?? plant.plantZone?.name ?? 'Unknown'}</span>
            <span>Catalog {catalogPlant?.name ?? 'Not linked'}</span>
          </div>

          <div className="form-grid plants-detail-grid">
            <div className="card">
              <strong>Growing time</strong>
              <span className="muted">{valueOrFallback(plant.growing_time_days, ' days')}</span>
            </div>
            <div className="card">
              <strong>Recommended temperature</strong>
              <span className="muted">{valueOrFallback(plant.recommended_temperature, ' °C')}</span>
            </div>
            <div className="card">
              <strong>Recommended humidity</strong>
              <span className="muted">{valueOrFallback(plant.recommended_humidity, '%')}</span>
            </div>
            <div className="card">
              <strong>Rest time</strong>
              <span className="muted">{valueOrFallback(plant.rest_time_days, ' days')}</span>
            </div>
            <div className="card">
              <strong>Plant size</strong>
              <span className="muted">{valueOrFallback(plant.plant_size)}</span>
            </div>
            <div className="card">
              <strong>Linked care profile</strong>
              <span className="muted">{plant.fk_plant_care_id ?? 'Not linked'}</span>
            </div>
          </div>

          {plant.disease_notes ? (
            <div className="inline-note">{plant.disease_notes}</div>
          ) : null}

          <section className="panel page-stack">
            <div>
              <h3 className="section-title">Catalog Context</h3>
              <p className="section-copy">The reusable plant definition this planted instance came from.</p>
            </div>
            {catalogPlant ? (
              <>
                <div className="meta-cluster">
                  <span>Name {catalogPlant.name}</span>
                  <span>Canonical {catalogPlant.canonical_name}</span>
                  <span>Type {catalogPlant.plant_type ?? 'Not set'}</span>
                </div>
                <div className="row-actions">
                  <Link to={`/plants/catalog/${catalogPlant.id}`}>
                    <Button variant="ghost">Open Catalog Plant</Button>
                  </Link>
                  <Link to={`/plants/catalog/${catalogPlant.id}/edit`}>
                    <Button variant="secondary">Edit Shared Care</Button>
                  </Link>
                </div>
              </>
            ) : (
              <EmptyState
                title="No catalog plant linked"
                description="This planted record predates the catalog split or was created manually without a reusable catalog entry."
              />
            )}
          </section>
        </section>

        <aside className="page-stack">
          <section className="panel page-stack">
            <div>
              <h3 className="section-title">Plant Care</h3>
              <p className="section-copy">The linked care guidance used by this plant record.</p>
            </div>

            {care ? (
              <>
                <div className="meta-cluster">
                  <span>Reusable {care.reusable ? 'Yes' : 'No'}</span>
                  <span>Provider {care.source_provider ?? 'Unknown'}</span>
                  <span>Quality {care.source_quality ?? 'Unknown'}</span>
                </div>

                <div className="page-stack">
                  <div className="card">
                    <strong>Description</strong>
                    <span className="muted">{care.description || 'Not set'}</span>
                  </div>
                  <div className="card">
                    <strong>Conditions</strong>
                    <span className="muted">{care.conditions || 'Not set'}</span>
                  </div>
                </div>

                <div className="form-grid plants-detail-grid">
                  <div className="card">
                    <strong>Watering interval</strong>
                    <span className="muted">{valueOrFallback(care.watering_interval_days, ' days')}</span>
                  </div>
                  <div className="card">
                    <strong>Fertilizing interval</strong>
                    <span className="muted">{valueOrFallback(care.fertilizing_interval_days, ' days')}</span>
                  </div>
                  <div className="card">
                    <strong>Pest check interval</strong>
                    <span className="muted">{valueOrFallback(care.pest_check_interval_days, ' days')}</span>
                  </div>
                  <div className="card">
                    <strong>Rain skip threshold</strong>
                    <span className="muted">{valueOrFallback(care.rain_skip_threshold_mm, ' mm')}</span>
                  </div>
                  <div className="card">
                    <strong>Frost threshold</strong>
                    <span className="muted">{valueOrFallback(care.frost_temp_threshold_c, ' °C')}</span>
                  </div>
                  <div className="card">
                    <strong>Heat threshold</strong>
                    <span className="muted">{valueOrFallback(care.heat_extra_water_temp_c, ' °C')}</span>
                  </div>
                  <div className="card">
                    <strong>Wind protection</strong>
                    <span className="muted">{valueOrFallback(care.wind_protection_kmh, ' km/h')}</span>
                  </div>
                  <div className="card">
                    <strong>Germinating duration</strong>
                    <span className="muted">{valueOrFallback(care.germinating_duration_days, ' days')}</span>
                  </div>
                  <div className="card">
                    <strong>Growing duration</strong>
                    <span className="muted">{valueOrFallback(care.growing_duration_days, ' days')}</span>
                  </div>
                  <div className="card">
                    <strong>Flowering duration</strong>
                    <span className="muted">{valueOrFallback(care.flowering_duration_days, ' days')}</span>
                  </div>
                  <div className="card">
                    <strong>Mature duration</strong>
                    <span className="muted">{valueOrFallback(care.mature_duration_days, ' days')}</span>
                  </div>
                  <div className="card">
                    <strong>Regenerating duration</strong>
                    <span className="muted">{valueOrFallback(care.regenerating_duration_days, ' days')}</span>
                  </div>
                </div>
              </>
            ) : (
              <EmptyState
                title="No plant care linked"
                description="This plant does not currently have a linked care profile."
              />
            )}
          </section>

          {care ? (
            <section className="panel page-stack">
              <div>
                <h3 className="section-title">Source Metadata</h3>
                <p className="section-copy">Secondary metadata kept with the linked care profile.</p>
              </div>
              <div className="meta-cluster">
                <span>Provider {care.source_provider ?? 'Not set'}</span>
                <span>Quality {care.source_quality ?? 'Not set'}</span>
                <span>Common name {care.source_common_name ?? 'Not set'}</span>
                <span>Scientific name {care.source_scientific_name ?? 'Not set'}</span>
                <span>Family {care.source_family ?? 'Not set'}</span>
              </div>
            </section>
          ) : null}
        </aside>
      </div>
    </div>
  )
}
