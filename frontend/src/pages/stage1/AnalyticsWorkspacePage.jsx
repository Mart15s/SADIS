import PageHeader from '../../components/layout/PageHeader.jsx'
import { ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import MetricCard from '../../components/ui/MetricCard.jsx'
import { useWorkspace } from '../../context/useWorkspace.js'
import { useI18n } from '../../i18n/i18n-context.js'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

export default function AnalyticsWorkspacePage() {
  const { active } = useWorkspace()
  const { formatArea, formatNumber } = useI18n()
  const canViewAnalytics =
    Boolean(active) &&
    (active.type === 'community' || active.permissions?.includes('view_analytics'))
  const pageState = useAsyncData(
    () =>
      canViewAnalytics
        ? api.getV1Path(
            `${active.type === 'farm' ? 'farms' : 'communities'}/${active.id}/analytics`,
          )
        : Promise.resolve({}),
    [active?.type, active?.id, canViewAnalytics],
    {},
  )
  if (pageState.loading) return <LoadingState title="Loading privacy-safe analytics…" />
  if (pageState.error) return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  const data = pageState.data || {}
  const communityFarms = Array.isArray(data.farms) ? data.farms : []
  const communityArea = communityFarms.reduce(
    (sum, farm) => sum + Number(farm.area_square_metres || 0),
    0,
  )
  const communitySeasons = communityFarms.reduce(
    (sum, farm) => sum + Number(farm.active_crop_seasons || 0),
    0,
  )
  const communityHarvest = communityFarms.reduce(
    (sum, farm) => sum + Number(farm.harvest_quantity || 0),
    0,
  )
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
          <MetricCard label="Shared harvest" value={`${formatNumber(communityHarvest)} kg`} />
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
            value={`${formatNumber(data.harvest_quantity || 0)} kg`}
          />
        </div>
      )}
    </div>
  )
}
