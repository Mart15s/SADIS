import { Link } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import MetricCard from '../../components/ui/MetricCard.jsx'
import { useWorkspace } from '../../context/useWorkspace.js'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

const shortcuts = [
  ['/fields', 'Fields', 'Map boundaries and management zones.'],
  ['/crop-seasons', 'Crop seasons', 'Connect crops, fields, work, and harvests.'],
  ['/tasks', 'Tasks', 'See the next work across your active workspace.'],
  ['/resources', 'Shared resources', 'Request and manage community equipment.'],
]

export default function Stage1DashboardPage() {
  const { active } = useWorkspace()
  const pageState = useAsyncData(
    () => active ? api.getV1Path(`${active.type === 'farm' ? 'farms' : 'communities'}/${active.id}/dashboard`) : Promise.resolve({}),
    [active?.type, active?.id],
    {},
  )
  const data = pageState.data || {}
  return <div className="page-stack stage1-page">
    <PageHeader eyebrow="Yava overview" title={active ? `Good to see you at ${active.name}` : 'Grow with confidence'} description="Plan fields and seasons, coordinate work, and learn from every harvest." />
    {pageState.loading ? <LoadingState title="Preparing your workspace…" /> : null}
    {pageState.error ? <ErrorState error={pageState.error} onRetry={pageState.reload} /> : null}
    <div className="metric-grid">
      <MetricCard label="Active fields" value={data.active_fields || 0} />
      <MetricCard label="Crop seasons" value={data.active_crop_seasons || 0} />
      <MetricCard label="Tasks due" value={data.tasks_due || 0} />
      <MetricCard label="Recommendations" value={data.recommendations_count || 0} />
    </div>
    <section className="stage1-card-grid" aria-label="Workspace shortcuts">
      {shortcuts.map(([to, title, description]) => <Link className="stage1-record-card stage1-shortcut-card" to={to} key={to}><span className="eyebrow">Open workspace</span><h2>{title}</h2><p>{description}</p><span className="stage1-shortcut-link">Open →</span></Link>)}
    </section>
    <section className="panel stage1-recommendations"><div><span className="eyebrow">Weather-linked guidance</span><h2>Recommendations</h2></div>{data.recommendations?.length ? data.recommendations.map((item) => <article key={item.id}><strong>{item.title}</strong><p>{item.description}</p></article>) : <p>No urgent recommendations. Yava will surface field guidance as weather and season data become available.</p>}</section>
  </div>
}
