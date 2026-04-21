import { Link } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import MetricCard from '../../components/ui/MetricCard.jsx'
import Button from '../../components/ui/Button.jsx'
import Card from '../../components/ui/Card.jsx'
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
        title="Garden work, one SPA shell"
        description="Plan, monitor, and coordinate your whole garden from one cohesive product workspace."
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

        <section className="hero-panel">
          <strong>What you can do after sign-in</strong>
          <ul>
            <li>Create plots and organize plant zones visually.</li>
            <li>Track plants, condition history, rotations, and calendar tasks.</li>
            <li>Manage inventory, share plots by role, register harvests, and export PDF reports.</li>
          </ul>
          <div className="auth-links">
            <Link to="/forgot-password">
              <Button variant="secondary">Reset password</Button>
            </Link>
          </div>
        </section>
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

      <section className="dashboard-grid">
        <MetricCard label="Plots" value={data.plots.length} note="Owned and shared plots" />
        <MetricCard label="Zones" value={data.plots.reduce((sum, plot) => sum + Number(plot.plant_zones_count ?? 0), 0)} />
        <MetricCard label="Plants" value={data.plots.reduce((sum, plot) => sum + Number(plot.plants_count ?? 0), 0)} />
        <MetricCard label="Inventory items" value={data.inventory.length} />
      </section>

      <section className="card-grid">
        {data.plots.length > 0 ? data.plots.slice(0, 4).map((plot) => (
          <Card key={plot.id} className="plot-card">
            <div className="list-head">
              <strong>{plot.name}</strong>
              <span className="badge badge-soft">{plot.access_role ?? 'viewer'}</span>
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
          </Card>
        )) : (
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
      </section>
    </div>
  )
}
