import { Link } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import MetricCard from '../../components/ui/MetricCard.jsx'
import { useWorkspace } from '../../context/useWorkspace.js'
import { useI18n } from '../../i18n/i18n-context.js'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

const shortcuts = [
  ['/fields', 'Fields', 'Map boundaries and management zones.'],
  ['/crop-seasons', 'Crop seasons', 'Connect crops, fields, work, and harvests.'],
  ['/tasks', 'Tasks', 'See the next work across your active workspace.'],
  ['/resources', 'Shared resources', 'Request and manage community equipment.'],
]

async function loadDashboard(active) {
  if (!active) return { analytics: {}, analyticsAvailable: false, recommendations: [] }
  const analyticsPath = `${active.type === 'farm' ? 'farms' : 'communities'}/${active.id}/analytics`
  if (active.type === 'community') {
    return {
      analytics: await api.getV1Path(analyticsPath),
      analyticsAvailable: true,
      recommendations: [],
    }
  }
  const canViewAnalytics = active.permissions?.includes('view_analytics')
  const [analytics, recommendations] = await Promise.all([
    canViewAnalytics ? api.getV1Path(analyticsPath) : Promise.resolve({}),
    api.listV1Path('recommendations', { farm_id: active.id }),
  ])
  return { analytics, analyticsAvailable: canViewAnalytics, recommendations }
}

export default function Stage1DashboardPage() {
  const { active } = useWorkspace()
  const { formatArea, formatNumber } = useI18n()
  const pageState = useAsyncData(
    () => loadDashboard(active),
    [active?.type, active?.id, active?.permissions?.join('|')],
    {
      analytics: {},
      analyticsAvailable: false,
      recommendations: [],
    },
  )
  const analytics = pageState.data?.analytics || {}
  const communityFarms = Array.isArray(analytics.farms) ? analytics.farms : []
  const communityArea = communityFarms.reduce(
    (sum, farm) => sum + Number(farm.area_square_metres || 0),
    0,
  )
  const recommendations = pageState.data?.recommendations || []

  return (
    <div className="page-stack stage1-page">
      <PageHeader
        eyebrow="Yava overview"
        title={active ? `Good to see you at ${active.name}` : 'Grow with confidence'}
        description="Plan fields and seasons, coordinate work, and learn from every harvest."
      />
      {pageState.loading ? <LoadingState title="Preparing your workspace…" /> : null}
      {pageState.error ? <ErrorState error={pageState.error} onRetry={pageState.reload} /> : null}
      {!active ? (
        <section className="stage1-empty">
          <h2>Select or create a workspace</h2>
          <p>Choose a farm or community to see live operational data.</p>
        </section>
      ) : pageState.data.analyticsAvailable ? (
        <div className="metric-grid">
          <MetricCard
            label={active.type === 'farm' ? 'Fields' : 'Shared farms'}
            value={formatNumber(
              active.type === 'farm' ? analytics.fields || 0 : communityFarms.length,
            )}
          />
          <MetricCard
            label="Active crop seasons"
            value={formatNumber(
              active.type === 'farm'
                ? analytics.active_crop_seasons || 0
                : communityFarms.reduce(
                    (sum, farm) => sum + Number(farm.active_crop_seasons || 0),
                    0,
                  ),
            )}
          />
          <MetricCard
            label={active.type === 'farm' ? 'Open tasks' : 'Shared farm area'}
            value={
              active.type === 'farm'
                ? formatNumber(analytics.open_tasks || 0)
                : formatArea(communityArea)
            }
          />
          <MetricCard
            label="Harvest recorded"
            value={`${formatNumber(active.type === 'farm' ? analytics.harvest_quantity || 0 : communityFarms.reduce((sum, farm) => sum + Number(farm.harvest_quantity || 0), 0))} kg`}
          />
        </div>
      ) : (
        <section className="stage1-empty" role="status">
          <h2>Operational analytics are restricted</h2>
          <p>Your current farm access does not include analytics.</p>
        </section>
      )}
      <section className="stage1-card-grid" aria-label="Workspace shortcuts">
        {shortcuts.map(([to, title, description]) => (
          <Link className="stage1-record-card stage1-shortcut-card" to={to} key={to}>
            <span className="eyebrow">Open workspace</span>
            <h2>{title}</h2>
            <p>{description}</p>
            <span className="stage1-shortcut-link">Open →</span>
          </Link>
        ))}
      </section>
      {active?.type === 'farm' ? (
        <section className="panel stage1-recommendations">
          <div>
            <span className="eyebrow">Weather-linked guidance</span>
            <h2>Recommendations</h2>
          </div>
          {recommendations.length ? (
            recommendations.map((item) => (
              <article key={item.id}>
                <strong>{item.title}</strong>
                <p>{item.description}</p>
              </article>
            ))
          ) : (
            <p>
              No urgent recommendations. Yava will surface field guidance as weather and season data
              become available.
            </p>
          )}
        </section>
      ) : null}
    </div>
  )
}
