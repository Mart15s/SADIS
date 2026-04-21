import { Link, useLocation, useParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import Badge from '../../components/ui/Badge.jsx'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

function valueOrFallback(value, suffix = '') {
  if (value === null || value === undefined || value === '') return null
  return `${value}${suffix}`
}

function CareMetric({ label, value, suffix = '' }) {
  const display = valueOrFallback(value, suffix)
  return (
    <div className="care-metric-item">
      <span className="care-metric-label">{label}</span>
      <span className="care-metric-value">{display ?? <span style={{ color: 'var(--text-faint)', fontWeight: 400 }}>—</span>}</span>
    </div>
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
        title={catalogPlant.name}
        description={`${catalogPlant.plant_type ?? 'Unknown type'} · ${catalogPlant.usage_count ?? 0} planted instances`}
        actions={(
          <>
            <Link to="/plants?view=catalog">
              <Button variant="secondary">Back</Button>
            </Link>
            <Link to={`/plants/new?catalogPlantId=${catalogPlant.id}`}>
              <Button variant="ghost">Place in Zone</Button>
            </Link>
            <Link to={`/plants/catalog/${catalogPlant.id}/edit`}>
              <Button>Edit</Button>
            </Link>
          </>
        )}
      />

      {notice ? <div className="inline-note">{notice}</div> : null}

      <div className="detail-grid">
        {/* ── Left: Identity ── */}
        <section className="panel page-stack">
          <div>
            <h3 className="section-title">Plant Identity</h3>
            <p className="section-copy">Reusable plant definition shared across plots and zones.</p>
          </div>

          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem' }}>
            {catalogPlant.plant_type ? <Badge tone="neutral">{catalogPlant.plant_type}</Badge> : null}
            {catalogPlant.usage_count > 0
              ? <Badge tone="soft">{catalogPlant.usage_count} uses</Badge>
              : <Badge tone="warning">Not planted yet</Badge>}
          </div>

          {catalogPlant.description ? (
            <div className="card">
              <p style={{ margin: 0, color: 'var(--text-soft)', fontSize: '0.95rem', lineHeight: 1.6 }}>
                {catalogPlant.description}
              </p>
            </div>
          ) : null}

          <div className="catalog-identity-grid">
            <div className="card">
              <span style={{ fontSize: '0.75rem', fontWeight: 600, color: 'var(--text-faint)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Canonical name</span>
              <strong style={{ marginTop: '0.2rem', display: 'block' }}>{catalogPlant.canonical_name || '—'}</strong>
            </div>
            <div className="card">
              <span style={{ fontSize: '0.75rem', fontWeight: 600, color: 'var(--text-faint)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Scientific name</span>
              <strong style={{ marginTop: '0.2rem', display: 'block', fontStyle: 'italic' }}>{catalogPlant.source_scientific_name || '—'}</strong>
            </div>
            <div className="card">
              <span style={{ fontSize: '0.75rem', fontWeight: 600, color: 'var(--text-faint)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Family</span>
              <strong style={{ marginTop: '0.2rem', display: 'block' }}>{catalogPlant.source_family || '—'}</strong>
            </div>
            <div className="card">
              <span style={{ fontSize: '0.75rem', fontWeight: 600, color: 'var(--text-faint)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Source provider</span>
              <strong style={{ marginTop: '0.2rem', display: 'block' }}>{catalogPlant.source_provider || '—'}</strong>
            </div>
            <div className="card">
              <span style={{ fontSize: '0.75rem', fontWeight: 600, color: 'var(--text-faint)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Source quality</span>
              <strong style={{ marginTop: '0.2rem', display: 'block' }}>{catalogPlant.source_quality || '—'}</strong>
            </div>
          </div>
        </section>

        {/* ── Right: Care ── */}
        <aside className="page-stack">
          <section className="panel page-stack">
            <div>
              <h3 className="section-title">Shared Plant Care</h3>
              <p className="section-copy">Editing this care updates the shared guidance for all catalog-linked plants.</p>
            </div>

            {care ? (
              <>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.4rem' }}>
                  {care.reusable ? <Badge tone="success">Reusable</Badge> : null}
                  {care.source_provider ? <Badge tone="neutral">{care.source_provider}</Badge> : null}
                  {care.source_quality ? <Badge tone="soft">{care.source_quality}</Badge> : null}
                </div>

                {care.description ? (
                  <div className="card">
                    <p style={{ margin: 0, color: 'var(--text-soft)', fontSize: '0.9rem', lineHeight: 1.6 }}>
                      {care.description}
                    </p>
                  </div>
                ) : null}

                {care.conditions ? (
                  <div className="card">
                    <span style={{ fontSize: '0.75rem', fontWeight: 600, color: 'var(--text-faint)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Growing conditions</span>
                    <p style={{ margin: '0.3rem 0 0', color: 'var(--text-soft)', fontSize: '0.9rem' }}>{care.conditions}</p>
                  </div>
                ) : null}

                <div>
                  <p style={{ margin: '0 0 0.6rem', fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-faint)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                    At a Glance
                  </p>
                  <div className="care-metrics-grid">
                    <CareMetric label="Watering" value={care.watering_interval_days} suffix=" days" />
                    <CareMetric label="Fertilizing" value={care.fertilizing_interval_days} suffix=" days" />
                    <CareMetric label="Pest check" value={care.pest_check_interval_days} suffix=" days" />
                    <CareMetric label="Rain skip" value={care.rain_skip_threshold_mm} suffix=" mm" />
                    <CareMetric label="Frost threshold" value={care.frost_temp_threshold_c} suffix=" °C" />
                    <CareMetric label="Heat threshold" value={care.heat_extra_water_temp_c} suffix=" °C" />
                    <CareMetric label="Wind protection" value={care.wind_protection_kmh} suffix=" km/h" />
                    <CareMetric label="Growing duration" value={care.growing_duration_days} suffix=" days" />
                  </div>
                </div>
              </>
            ) : (
              <EmptyState title="No shared care linked" description="Link or edit plant care on the catalog plant form." />
            )}
          </section>
        </aside>
      </div>
    </div>
  )
}
