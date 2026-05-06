import { Link, useLocation, useParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import EmptyStatePanel from '../../components/ui/EmptyStatePanel.jsx'
import { DefinitionList, KeyValueGrid } from '../../components/ui/DefinitionList.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { api } from '../../lib/api.js'
import {
  formatDayCount,
  formatDisplayValue,
  formatNumberWithUnit,
  formatTemperatureC,
} from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

function CareMetric({ label, value }) {
  return (
    <KeyValueGrid className="catalog-care-metric" items={[{ label, value }]} />
  )
}

export default function CatalogPlantDetailPage() {
  const location = useLocation()
  const { catalogPlantId } = useParams()

  const pageState = useAsyncData(
    () => api.getCatalogPlant(catalogPlantId),
    [catalogPlantId],
    null,
  )

  if (pageState.loading) return <LoadingState title="Loading catalog plant..." />
  if (pageState.error) return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  if (!pageState.data) return <EmptyState title="Catalog plant not found" description="The requested catalog plant could not be loaded." />

  const catalogPlant = pageState.data
  const care = catalogPlant.plantCare ?? catalogPlant.plant_care ?? null
  const notice = location.state?.notice

  return (
    <div className="page-stack">
      <PageHeader
        eyebrow="Plant catalog"
        title={catalogPlant.name}
        description={`${catalogPlant.plant_type ?? 'Unknown type'} / ${catalogPlant.usage_count ?? 0} planted instances`}
        meta={(
          <>
            {catalogPlant.plant_type ? <StatusBadge kind="selection" tone="neutral">{catalogPlant.plant_type}</StatusBadge> : null}
            <StatusBadge kind="selection" tone={catalogPlant.usage_count > 0 ? 'success' : 'warning'}>
              {catalogPlant.usage_count > 0 ? `${catalogPlant.usage_count} active uses` : 'Not planted yet'}
            </StatusBadge>
          </>
        )}
        actions={(
          <>
            <Link to="/plants?view=catalog">
              <Button variant="secondary">Back</Button>
            </Link>
            <Link to={`/plants/catalog/${catalogPlant.id}/edit`}>
              <Button variant="ghost">Edit</Button>
            </Link>
            <Link to={`/plants/new?catalogPlantId=${catalogPlant.id}`}>
              <Button>Place in zone</Button>
            </Link>
          </>
        )}
      />

      {notice ? <div className="inline-note">{notice}</div> : null}

      <div className="catalog-plant-layout">
        <section className="panel page-stack catalog-plant-identity-panel">
          <div className="plot-page-section-head">
            <div>
              <h2 className="section-title">At a glance</h2>
              <p className="section-copy">Reusable identity and shared care stay compact here so you can assess the catalog entry quickly.</p>
            </div>
          </div>

          {catalogPlant.description ? (
            <p className="catalog-plant-description">{catalogPlant.description}</p>
          ) : (
            <EmptyStatePanel
              title="No description yet"
              description="Add a short summary on the edit form so this catalog plant is easier to identify across plots."
              tone="subtle"
            />
          )}

          <DefinitionList
            items={[
              { label: 'Canonical name', value: formatDisplayValue(catalogPlant.canonical_name) },
              { label: 'Scientific name', value: formatDisplayValue(catalogPlant.source_scientific_name) },
              { label: 'Family', value: formatDisplayValue(catalogPlant.source_family) },
              { label: 'Source provider', value: formatDisplayValue(catalogPlant.source_provider) },
              { label: 'Source quality', value: formatDisplayValue(catalogPlant.source_quality) },
            ]}
          />
        </section>

        <section className="panel page-stack catalog-plant-care-panel">
          <div className="plot-page-section-head">
            <div>
              <h2 className="section-title">Shared care</h2>
              <p className="section-copy">Catalog-linked care is reused across planted instances, so the important intervals come first.</p>
            </div>
            {care?.reusable ? <StatusBadge kind="status" tone="success">Reusable care</StatusBadge> : null}
          </div>

          {care ? (
            <>
              <div className="catalog-care-grid">
                <CareMetric label="Watering" value={formatDayCount(care.watering_interval_days)} />
                <CareMetric label="Fertilizing" value={formatDayCount(care.fertilizing_interval_days)} />
                <CareMetric label="Pest check" value={formatDayCount(care.pest_check_interval_days)} />
                <CareMetric label="Rain skip" value={formatNumberWithUnit(care.rain_skip_threshold_mm, 'mm', 1)} />
                <CareMetric label="Frost threshold" value={formatTemperatureC(care.frost_temp_threshold_c)} />
                <CareMetric label="Heat threshold" value={formatTemperatureC(care.heat_extra_water_temp_c)} />
                <CareMetric label="Wind protection" value={formatNumberWithUnit(care.wind_protection_kmh, 'km/h', 1)} />
                <CareMetric label="Growing duration" value={formatDayCount(care.growing_duration_days)} />
              </div>

              <details className="catalog-detail-disclosure" open>
                <summary>Growing guidance</summary>
                <DefinitionList
                  items={[
                    { label: 'Conditions', value: formatDisplayValue(care.conditions) },
                    { label: 'Description', value: formatDisplayValue(care.description) },
                  ]}
                />
              </details>

              <details className="catalog-detail-disclosure">
                <summary>Lifecycle timing</summary>
                <DefinitionList
                  items={[
                    { label: 'Germinating', value: formatDayCount(care.germinating_duration_days) },
                    { label: 'Flowering', value: formatDayCount(care.flowering_duration_days) },
                    { label: 'Mature start', value: formatDayCount(care.mature_duration_days) },
                    { label: 'Mature end', value: formatDayCount(care.mature_end_duration_days ?? care.mature_duration_end_days) },
                    { label: 'Regenerating', value: formatDayCount(care.regenerating_duration_days) },
                  ]}
                />
              </details>
            </>
          ) : (
            <EmptyStatePanel
              title="No shared care linked"
              description="Link a plant care profile on the catalog plant form so the calendar and care rules can use this entry."
              tone="subtle"
            />
          )}
        </section>
      </div>
    </div>
  )
}
