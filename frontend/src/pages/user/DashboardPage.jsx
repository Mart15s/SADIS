import { Link } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import ActionRow from '../../components/ui/ActionRow.jsx'
import Button from '../../components/ui/Button.jsx'
import SectionCard from '../../components/ui/SectionCard.jsx'
import StatCard from '../../components/ui/StatCard.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { useAuth } from '../../context/AuthContext.jsx'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'
import { formatDate } from '../../lib/constants.js'

export default function DashboardPage() {
  const { isAuthenticated, profile, user } = useAuth()
  const { data, loading, error, reload } = useAsyncData(
    async () => {
      if (!isAuthenticated) {
        return { plots: [], inventory: [] }
      }

      const [plots, inventory] = await Promise.all([
        api.listPlots(),
        api.listInventory(),
      ])

      return { plots, inventory }
    },
    [isAuthenticated],
    { plots: [], inventory: [] },
  )

  if (!isAuthenticated) {
    return (
      <div className="page-stack">
        <PageHeader
          eyebrow="Welcome"
          title="Garden work, one shared workspace"
          description="Plan plots, review conditions, and keep garden work aligned from one clear frontend shell."
          actions={(
            <>
              <Link to="/login">
                <Button variant="secondary">Sign in</Button>
              </Link>
              <Link to="/register">
                <Button>Create account</Button>
              </Link>
            </>
          )}
        />

        <SectionCard title="What you unlock after sign-in" description="The product stays content-first and keeps your next garden decisions close to the data.">
          <ul>
            <li>Create plots and organize plant zones visually.</li>
            <li>Track plants, condition history, rotations, and calendar tasks.</li>
            <li>Manage inventory, share plots by role, register harvests, and export PDF reports.</li>
          </ul>
          <ActionRow>
            <Link to="/forgot-password">
              <Button variant="secondary">Reset password</Button>
            </Link>
          </ActionRow>
        </SectionCard>
      </div>
    )
  }

  if (loading) {
    return <LoadingState title="Loading your garden overview..." />
  }

  if (error) {
    return <ErrorState error={error} onRetry={reload} />
  }

  return (
    <div className="page-stack">
      <PageHeader
        eyebrow="Overview"
        title={`Welcome back, ${profile?.name ?? 'gardener'}`}
        description={`Signed in as ${user?.email}. Here is the current snapshot of your plots, zones, plants, and inventory.`}
        meta={(
          <>
            <StatusBadge kind="connection">Garden data synced</StatusBadge>
            <StatusBadge kind="ownership">{data.plots.length > 0 ? `${data.plots.length} active plots` : 'No plots yet'}</StatusBadge>
          </>
        )}
        actions={(
          <>
            <Link to="/account">
              <Button variant="secondary">Account</Button>
            </Link>
            <Link to="/plots">
              <Button>Open plots</Button>
            </Link>
            <Link to="/inventory">
              <Button variant="secondary">Inventory</Button>
            </Link>
          </>
        )}
      />

      <section className="stats-grid">
        <StatCard label="Plots" value={data.plots.length} note="Owned and shared plots" accent="brand" />
        <StatCard label="Zones" value={data.plots.reduce((sum, plot) => sum + Number(plot.plant_zones_count ?? 0), 0)} />
        <StatCard label="Plants" value={data.plots.reduce((sum, plot) => sum + Number(plot.plants_count ?? 0), 0)} />
        <StatCard label="Inventory items" value={data.inventory.length} />
      </section>

      <SectionCard
        title="Plot snapshot"
        description="Jump back into the plots that need attention without wasting space on an oversized overview panel."
        actions={(
          <Link to="/plots">
            <Button variant="ghost">View all plots</Button>
          </Link>
        )}
      >
        {data.plots.length > 0 ? (
          <section className="card-grid plot-card-grid">
            {data.plots.slice(0, 4).map((plot) => (
              <article key={plot.id} className="plot-summary-card">
                <div className="list-head">
                  <strong>{plot.name}</strong>
                  <StatusBadge kind="ownership">{plot.access_role ?? 'viewer'}</StatusBadge>
                </div>
                <span className="muted">
                  {plot.city} | created {formatDate(plot.creation_date)}
                </span>
                <div className="meta-cluster">
                  <span>{plot.plant_zones_count} zones</span>
                  <span>{plot.plants_count} plants</span>
                </div>
                <Link to={`/plots/${plot.id}`}>
                  <Button variant="ghost">Open plot</Button>
                </Link>
              </article>
            ))}
          </section>
        ) : (
          <EmptyState
            title="No plots yet"
            description="Create your first plot from the plots module to start using zones, plants, calendars, and analytics."
            action={(
              <Link to="/plots">
                <Button>Create first plot</Button>
              </Link>
            )}
          />
        )}
      </SectionCard>
    </div>
  )
}
