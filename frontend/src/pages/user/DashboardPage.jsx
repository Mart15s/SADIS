import { Link } from 'react-router-dom'
import { GardenTimeline, MeasurementBadge, PlantStatusBadge } from '../../components/garden/GardenControls.jsx'
import PageHeader from '../../components/layout/PageHeader.jsx'
import PlanPreview from '../../components/plot/PlanPreview.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import ActionRow from '../../components/ui/ActionRow.jsx'
import Button from '../../components/ui/Button.jsx'
import SectionCard from '../../components/ui/SectionCard.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { useAuth } from '../../context/AuthContext.jsx'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'
import { formatDate, formatSquareMetersValue, safeNumber } from '../../lib/constants.js'

export default function DashboardPage() {
  const { isAuthenticated, user } = useAuth()
  const { data, loading, error, reload } = useAsyncData(
    async () => {
      if (!isAuthenticated) {
        return { plots: [], inventory: [], plants: [] }
      }

      const [plots, inventory, plants] = await Promise.all([
        api.listPlots(),
        api.listInventory(),
        api.listManagedPlants(),
      ])

      return { plots, inventory, plants }
    },
    [isAuthenticated],
    { plots: [], inventory: [], plants: [] },
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

  const totalZones = data.plots.reduce((sum, plot) => sum + Number(plot.plant_zones_count ?? 0), 0)
  const totalPlants = data.plots.reduce((sum, plot) => sum + Number(plot.plants_count ?? 0), 0)
  const lowInventory = data.inventory.filter((item) => Number(item.quantity ?? 0) <= 0)
  const visiblePlants = data.plants.slice(0, 5)
  const timelineItems = [
    ...data.plots.slice(0, 3).map((plot) => ({
      id: `plot-${plot.id}`,
      label: plot.name,
      meta: `${plot.city || 'No city'} - created ${formatDate(plot.creation_date)}`,
      tone: 'leaf',
    })),
    ...lowInventory.slice(0, 2).map((item) => ({
      id: `inventory-${item.id}`,
      label: `${item.name} needs restock`,
      meta: `${item.type} - ${safeNumber(item.quantity, item.type === 'tool' ? 0 : 2)} available`,
      tone: 'amber',
    })),
  ]

  return (
    <div className="page-stack dashboard-workbench">
      <PageHeader
        eyebrow="Garden operations"
        title="Planning board"
        description={`Signed in as ${user?.email}. The workspace starts from plots, zones, plant condition, weather-sensitive tasks, and inventory readiness.`}
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

      <section className="dashboard-map-band">
        <div className="dashboard-map-copy">
          <span className="workspace-section-eyebrow">Active garden model</span>
          <h2>Plots, zones, plants, and tasks in one operational view.</h2>
          <p>
            This screen is shaped around the real planning objects: plot plans, mapped zones, calendar pressure, and stock needed for care tasks.
          </p>
        </div>
        <div className="dashboard-measurement-strip" aria-label="Garden summary">
          <MeasurementBadge label="Plots" value={data.plots.length} tone="earth" />
          <MeasurementBadge label="Zones" value={totalZones} tone="field" />
          <MeasurementBadge label="Plants" value={totalPlants} tone="leaf" />
          <MeasurementBadge label="Inventory" value={data.inventory.length} tone="amber" />
        </div>
      </section>

      <section className="dashboard-context-grid">
        <SectionCard
          title="Active plots"
          description="Plot plans stay as the main work object instead of being reduced to identical metric cards."
          actions={(
            <Link to="/plots">
              <Button variant="ghost">View all plots</Button>
            </Link>
          )}
          className="dashboard-active-plots"
        >
          {data.plots.length > 0 ? (
            <section className="dashboard-plot-list">
              {data.plots.slice(0, 3).map((plot) => (
                <article key={plot.id} className="dashboard-plot-row">
                  <PlanPreview
                    className="dashboard-plot-preview"
                    plotName={plot.name}
                    plotSize={plot.plot_size}
                    plotGeometry={plot.geometry}
                    zones={plot.zones ?? []}
                  />
                  <div className="dashboard-plot-row-copy">
                    <div className="list-head">
                      <strong>{plot.name}</strong>
                      <StatusBadge kind="ownership">{plot.access_role ?? 'viewer'}</StatusBadge>
                    </div>
                    <span className="muted">
                      {plot.city} / {formatSquareMetersValue(plot.plot_size, 2)}
                    </span>
                    <div className="dashboard-mini-metrics">
                      <MeasurementBadge label="Zones" value={plot.plant_zones_count ?? 0} tone="field" />
                      <MeasurementBadge label="Plants" value={plot.plants_count ?? 0} tone="leaf" />
                    </div>
                    <ActionRow>
                      <Link to={`/plots/${plot.id}`}>
                        <Button variant="ghost">Open plan</Button>
                      </Link>
                      <Link to={`/plots/${plot.id}/calendar`}>
                        <Button variant="secondary">Calendar</Button>
                      </Link>
                    </ActionRow>
                  </div>
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

        <div className="dashboard-side-stack">
          <SectionCard title="Today's garden work" description="Operational entry points for care tasks, condition checks, and stock blockers.">
            <div className="dashboard-task-lane">
              {data.plots.slice(0, 3).map((plot) => (
                <Link key={`task-${plot.id}`} to={`/plots/${plot.id}/calendar`} className="dashboard-task-row">
                  <span className="dashboard-task-date">Today</span>
                  <strong>{plot.name}</strong>
                  <span>Open calendar tasks for mapped zones</span>
                </Link>
              ))}
              {data.plots.length === 0 ? (
                <p className="muted">Create a plot and generate a calendar to see scheduled work here.</p>
              ) : null}
            </div>
          </SectionCard>

          <SectionCard title="Weather influence" description="Calendar generation uses Meteo.lt data and stored forecast fallback.">
            <div className="dashboard-weather-stack">
              <span className="weather-rule-chip">Rain can skip watering</span>
              <span className="weather-rule-chip">Frost adds protection tasks</span>
              <span className="weather-rule-chip">Heat increases watering pressure</span>
              <span className="weather-rule-chip">Wind can trigger protection</span>
            </div>
          </SectionCard>
        </div>
      </section>

      <section className="dashboard-context-grid dashboard-context-grid-secondary">
        <SectionCard title="Plant statuses" description="Plants are shown as living records attached to plots and zones, not just rows in a database.">
          {visiblePlants.length > 0 ? (
            <div className="dashboard-plant-list">
              {visiblePlants.map((plant) => (
                <Link key={plant.id} to={`/plants/${plant.id}`} className="dashboard-plant-row">
                  <div>
                    <strong>{plant.name}</strong>
                    <span>{plant.plot?.name ?? 'Unknown plot'} - {plant.plant_zone?.name ?? plant.plantZone?.name ?? 'No zone'}</span>
                  </div>
                  <PlantStatusBadge status={plant.condition ?? plant.lifecycle_phase} careLinked={plant.has_plant_care} />
                </Link>
              ))}
            </div>
          ) : (
            <EmptyState title="No plant records" description="Add planted records from the plant workspace or directly from a plot zone." />
          )}
        </SectionCard>

        <SectionCard title="Planning history summary" description="Recent plot and stock changes are presented as a planning timeline.">
          <GardenTimeline
            items={timelineItems}
            emptyText="Create a plot, draw zones, or adjust inventory to start building a planning trail."
          />
        </SectionCard>
      </section>
    </div>
  )
}
