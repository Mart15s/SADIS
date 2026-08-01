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
  const pageState = useAsyncData(
    () => active ? api.getV1Path(`${active.type === 'farm' ? 'farms' : 'communities'}/${active.id}/analytics`) : Promise.resolve({}),
    [active?.type, active?.id],
    {},
  )
  if (pageState.loading) return <LoadingState title="Loading privacy-safe analytics…" />
  if (pageState.error) return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  const data = pageState.data || {}
  return <div className="page-stack stage1-page">
    <PageHeader eyebrow={active?.type || 'workspace'} title="Analytics" description={active?.type === 'community' ? 'Only explicitly shared, aggregated farm data is included.' : 'Operational farm measures across fields, seasons, tasks, and harvests.'} />
    {!active ? <section className="stage1-empty"><h2>Select a workspace</h2><p>Choose a farm or community to view analytics.</p></section> : (
      <div className="metric-grid">
        <MetricCard label="Cultivated area" value={formatArea(data.cultivated_area_square_metres || 0)} />
        <MetricCard label="Active crop seasons" value={formatNumber(data.active_crop_seasons || 0)} />
        <MetricCard label="Open tasks" value={formatNumber(data.open_tasks || 0)} />
        <MetricCard label="Harvest this season" value={`${formatNumber(data.harvest_quantity || 0)} ${data.harvest_unit || 'kg'}`} />
      </div>
    )}
  </div>
}
