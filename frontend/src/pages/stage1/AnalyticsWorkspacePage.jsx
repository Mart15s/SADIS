import PageHeader from '../../components/layout/PageHeader.jsx'
import { ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import MetricCard from '../../components/ui/MetricCard.jsx'
import { useWorkspace } from '../../context/useWorkspace.js'
import { useI18n } from '../../i18n/i18n-context.js'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

function mergeHarvestQuantities(farms) {
  return farms.reduce((totals, farm) => {
    Object.entries(farm.harvest_quantities || {}).forEach(([unit, quantity]) => {
      totals[unit] = (totals[unit] || 0) + Number(quantity || 0)
    })
    if (!farm.harvest_quantities && farm.harvest_quantity != null) {
      const unit = farm.harvest_unit || 'kg'
      totals[unit] = (totals[unit] || 0) + Number(farm.harvest_quantity || 0)
    }
    return totals
  }, {})
}

function harvestLabel(quantities, formatNumber) {
  const entries = Object.entries(quantities || {})
  if (!entries.length) return '0'
  return entries.map(([unit, quantity]) => `${formatNumber(quantity)} ${unit}`).join(' · ')
}

async function loadAnalytics(active, canViewAnalytics) {
  if (!active || !canViewAnalytics) return { analytics: {}, history: [] }
  const analytics = await api.getV1Path(
    `${active.type === 'farm' ? 'farms' : 'communities'}/${active.id}/analytics`,
  )
  const history =
    active.type === 'farm' ? await api.listV1Path('planning-history', { farm_id: active.id }) : []
  return { analytics, history }
}

export default function AnalyticsWorkspacePage() {
  const { active } = useWorkspace()
  const { formatArea, formatDateTime, formatNumber } = useI18n()
  const canViewAnalytics =
    Boolean(active) &&
    (active.type === 'community' || active.permissions?.includes('view_analytics'))
  const pageState = useAsyncData(
    () => loadAnalytics(active, canViewAnalytics),
    [active?.type, active?.id, canViewAnalytics],
    { analytics: {}, history: [] },
  )
  if (pageState.loading) return <LoadingState title="Loading privacy-safe analytics…" />
  if (pageState.error) return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  const data = pageState.data?.analytics || {}
  const history = pageState.data?.history || []
  const communityFarms = Array.isArray(data.farms) ? data.farms : []
  const communityArea = communityFarms.reduce(
    (sum, farm) => sum + Number(farm.area_square_metres || 0),
    0,
  )
  const communitySeasons = communityFarms.reduce(
    (sum, farm) => sum + Number(farm.active_crop_seasons || 0),
    0,
  )
  const communityHarvest = mergeHarvestQuantities(communityFarms)
  return (
    <div className="page-stack stage1-page">
      <PageHeader
        eyebrow={active?.type || 'workspace'}
        title="Analytics"
        description={
          active?.type === 'community'
            ? 'Only explicitly shared, aggregated farm data is included.'
            : 'Operational farm measures across fields, seasons, tasks, and harvests.'
        }
      />
      {!active ? (
        <section className="stage1-empty">
          <h2>Select a workspace</h2>
          <p>Choose a farm or community to view analytics.</p>
        </section>
      ) : !canViewAnalytics ? (
        <section className="stage1-empty" role="status">
          <h2>Analytics access not granted</h2>
          <p>Your current farm role does not include operational analytics.</p>
        </section>
      ) : active.type === 'community' ? (
        <div className="metric-grid">
          <MetricCard label="Shared farms" value={formatNumber(communityFarms.length)} />
          <MetricCard label="Shared farm area" value={formatArea(communityArea)} />
          <MetricCard label="Shared crop seasons" value={formatNumber(communitySeasons)} />
          <MetricCard label="Shared harvest" value={harvestLabel(communityHarvest, formatNumber)} />
        </div>
      ) : (
        <div className="metric-grid">
          <MetricCard label="Farm area" value={formatArea(data.area_square_metres || 0)} />
          <MetricCard
            label="Active crop seasons"
            value={formatNumber(data.active_crop_seasons || 0)}
          />
          <MetricCard label="Open tasks" value={formatNumber(data.open_tasks || 0)} />
          <MetricCard
            label="Harvest recorded"
            value={harvestLabel(data.harvest_quantities, formatNumber)}
          />
        </div>
      )}
      {active?.type === 'farm' && canViewAnalytics ? (
        <section className="panel">
          <div>
            <span className="eyebrow">Audit trail</span>
            <h2>Planning history</h2>
          </div>
          {history.length ? (
            <ol className="stage1-calendar" aria-label="Planning history">
              {history.map((item) => (
                <li className="stage1-calendar-item" key={item.id}>
                  <time dateTime={item.created_at}>
                    {formatDateTime(item.created_at, {}, active.timezone)}
                  </time>
                  <div>
                    <h3>{item.event.replaceAll('_', ' ')}</h3>
                    <p>{item.field_name || 'Farm-wide change'}</p>
                  </div>
                </li>
              ))}
            </ol>
          ) : (
            <p>No planning changes have been recorded yet.</p>
          )}
        </section>
      ) : null}
    </div>
  )
}
