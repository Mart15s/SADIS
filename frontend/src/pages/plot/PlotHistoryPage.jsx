import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import PlanPreview from '../../components/plot/PlanPreview.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'
import { formatDateTime } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

export default function PlotHistoryPage() {
  const { plotId } = useParams()
  const [selectedSnapshotId, setSelectedSnapshotId] = useState(null)
  const historyState = useAsyncData(
    async () => {
      const [plot, snapshots] = await Promise.all([
        api.getPlot(plotId),
        api.listPlotHistory(plotId),
      ])

      return { plot, snapshots }
    },
    [plotId],
    { plot: null, snapshots: [] },
  )

  useEffect(() => {
    if (!selectedSnapshotId && historyState.data.snapshots.length > 0) {
      setSelectedSnapshotId(historyState.data.snapshots[0].id)
    }
  }, [historyState.data.snapshots, selectedSnapshotId])

  if (historyState.loading) {
    return <LoadingState title="Loading planning history..." />
  }

  if (historyState.error) {
    return <ErrorState error={historyState.error} onRetry={historyState.reload} />
  }

  const selectedSnapshot = historyState.data.snapshots.find((snapshot) => snapshot.id === selectedSnapshotId) ?? null
  const snapshotPayload = selectedSnapshot?.snapshot ?? {}

  return (
    <div className="page-stack">
      <PageHeader
        title={`${historyState.data.plot?.name ?? 'Plot'} planning history`}
        description="Browse saved plot snapshots and inspect the historical version of the plan, zones, and plants."
        actions={(
          <Link to={`/plots/${plotId}`}>
            <Button variant="secondary">Back to plot</Button>
          </Link>
        )}
      />

      {historyState.data.snapshots.length === 0 ? (
        <EmptyState title="No planning history" description="This plot does not have saved snapshots yet." />
      ) : (
        <div className="detail-grid">
          <section className="panel table-stack">
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Action</th>
                    <th>Saved at</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {historyState.data.snapshots.map((snapshot) => (
                    <tr key={snapshot.id}>
                      <td>{snapshot.action}</td>
                      <td>{formatDateTime(snapshot.created_at)}</td>
                      <td>
                        <Button variant="ghost" onClick={() => setSelectedSnapshotId(snapshot.id)}>
                          View version
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          <section className="page-stack">
            {selectedSnapshot ? (
              <>
                <section className="panel page-stack">
                  <div className="list-head">
                    <div className="stack">
                      <h3>Selected version</h3>
                      <span className="muted">{selectedSnapshot.action}</span>
                    </div>
                    <span>{formatDateTime(selectedSnapshot.created_at)}</span>
                  </div>
                  <PlanPreview
                    plotName={snapshotPayload.plot?.name}
                    plotSize={snapshotPayload.plot?.plot_size}
                    plotGeometry={snapshotPayload.plot?.geometry}
                    zones={snapshotPayload.zones ?? []}
                  />
                </section>

                <section className="panel page-stack">
                  <h3>Plants in this version</h3>
                  {snapshotPayload.plants?.length ? (
                    <div className="table-wrap">
                      <table>
                        <thead>
                          <tr>
                            <th>Name</th>
                            <th>Zone ID</th>
                            <th>Condition</th>
                          </tr>
                        </thead>
                        <tbody>
                          {snapshotPayload.plants.map((plant) => (
                            <tr key={`${selectedSnapshot.id}-${plant.id}`}>
                              <td>{plant.name}</td>
                              <td>{plant.plant_zone_id ?? plant.fk_plant_zone_id ?? 'Not set'}</td>
                              <td>{plant.condition ?? 'Not set'}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  ) : (
                    <EmptyState title="No plants in snapshot" description="This version did not include plant entries." />
                  )}
                </section>
              </>
            ) : null}
          </section>
        </div>
      )}
    </div>
  )
}
