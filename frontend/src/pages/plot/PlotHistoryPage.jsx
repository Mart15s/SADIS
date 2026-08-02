import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import PlanPreview from '../../components/plot/PlanPreview.jsx'
import PlotSectionNav from '../../components/plot/PlotSectionNav.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import EmptyStatePanel from '../../components/ui/EmptyStatePanel.jsx'
import MetricCard from '../../components/ui/MetricCard.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { api } from '../../lib/api.js'
import {
  formatDateTime,
  formatPlantCondition,
  formatSnapshotText,
  formatSquareMetersValue,
} from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

function plantZoneLabel(plant, zones) {
  const zoneId = plant.plant_zone_id ?? plant.fk_plant_zone_id ?? null
  const zone = zones.find((entry) => String(entry.id) === String(zoneId))
  return zone?.name ?? 'Zona nenurodyta'
}

export default function PlotHistoryPage() {
  const { plotId } = useParams()
  const [selectedSnapshotId, setSelectedSnapshotId] = useState(null)
  const historyState = useAsyncData(
    async () => {
      const plots = await api.listPlots()
      const accessRole = plots.find((entry) => String(entry.id) === String(plotId))?.access_role ?? null
      const [plot, snapshots] = await Promise.all([
        api.getPlot(plotId),
        api.listPlotHistory(plotId),
      ])

      return { plot, snapshots, accessRole }
    },
    [plotId],
    { plot: null, snapshots: [], accessRole: null },
  )

  useEffect(() => {
    if (!selectedSnapshotId && historyState.data.snapshots.length > 0) {
      setSelectedSnapshotId(historyState.data.snapshots[0].id)
    }
  }, [historyState.data.snapshots, selectedSnapshotId])

  if (historyState.loading) {
    return <LoadingState title="Loading planning history…" />
  }

  if (historyState.error) {
    return <ErrorState error={historyState.error} onRetry={historyState.reload} />
  }

  if (!historyState.data.plot) {
    return <EmptyState title="Field not found" description="The selected field could not be loaded." />
  }

  const isOwner = historyState.data.accessRole === 'owner'
  const selectedSnapshot = historyState.data.snapshots.find((snapshot) => snapshot.id === selectedSnapshotId)
    ?? historyState.data.snapshots[0]
    ?? null
  const snapshotPayload = selectedSnapshot?.snapshot ?? {}
  const versionZones = snapshotPayload.zones ?? []
  const versionPlants = snapshotPayload.plants ?? []

  return (
    <div className="page-stack">
      <PlotSectionNav
        plotId={plotId}
        plotName={historyState.data.plot.name}
        sectionKey="history"
        isOwner={isOwner}
        description="History shows meaningful saved changes so you can review actual field versions."
        meta={(
          <StatusBadge kind="selection" tone="neutral">{historyState.data.snapshots.length} saved versions</StatusBadge>
        )}
      />

      {historyState.data.snapshots.length === 0 ? (
        <EmptyState
          title="No saved versions yet"
          description="History starts accumulating when the editor workspace is explicitly saved."
        />
      ) : (
        <div className="plot-history-browser">
          <section className="panel page-stack plot-history-list-panel">
            <div className="plot-page-section-head">
              <div>
                <h2 className="section-title">Saved versions</h2>
                <p className="section-copy">Select a version to review its saved plan, metadata, and plants.</p>
              </div>
            </div>

            <div className="plot-history-list">
              {historyState.data.snapshots.map((snapshot) => {
                const isSelected = snapshot.id === selectedSnapshot?.id

                return (
                  <button
                    key={snapshot.id}
                    type="button"
                    className={`plot-history-row ${isSelected ? 'is-selected' : ''}`.trim()}
                    onClick={() => setSelectedSnapshotId(snapshot.id)}
                  >
                    <div className="plot-history-row-copy">
                      <div className="plot-history-row-head">
                        <strong>{formatSnapshotText(snapshot.label ?? snapshot.action)}</strong>
                        <span className="plot-history-row-date">{formatDateTime(snapshot.created_at)}</span>
                      </div>
                      <p className="plot-history-row-summary">{formatSnapshotText(snapshot.summary)}</p>
                    </div>
                    <div className="plot-history-row-meta">
                      <StatusBadge kind="selection" tone="neutral">{snapshot.zone_count ?? 0} zonos</StatusBadge>
                      <StatusBadge kind="selection" tone="neutral">{snapshot.plant_count ?? 0} augalai</StatusBadge>
                    </div>
                  </button>
                )
              })}
            </div>
          </section>

          <section className="panel page-stack plot-history-preview-panel">
            {selectedSnapshot ? (
              <>
                <div className="plot-page-section-head">
                  <div>
                    <h2 className="section-title">{formatSnapshotText(selectedSnapshot.label ?? selectedSnapshot.action)}</h2>
                    <p className="section-copy">{formatSnapshotText(selectedSnapshot.summary)}</p>
                  </div>
                  <span className="plot-history-preview-date">{formatDateTime(selectedSnapshot.created_at)}</span>
                </div>

                <div className="plot-history-preview-meta">
                  <MetricCard label="Zones" value={versionZones.length} />
                  <MetricCard label="Plants" value={versionPlants.length} />
                  <MetricCard label="Sklypo plotas" value={formatSquareMetersValue(snapshotPayload.plot?.plot_size, 2, '--')} />
                </div>

                <PlanPreview
                  plotName={snapshotPayload.plot?.name}
                  plotSize={snapshotPayload.plot?.plot_size}
                  plotGeometry={snapshotPayload.plot?.geometry}
                  zones={versionZones}
                  className="plot-history-plan-preview"
                />

                <section className="page-stack">
                  <div className="plot-page-section-head">
                    <div>
                      <h3 className="section-title">Plants in this version</h3>
                      <p className="section-copy">Compact plant records make the version easy to review without an oversized table.</p>
                    </div>
                  </div>

                  {versionPlants.length > 0 ? (
                    <div className="plot-history-plant-list">
                      {versionPlants.map((plant) => (
                        <article key={`${selectedSnapshot.id}-${plant.id}`} className="plot-history-plant-chip">
                          <div>
                            <strong>{plant.name}</strong>
                            <p className="muted">{plantZoneLabel(plant, versionZones)}</p>
                          </div>
                          <StatusBadge kind="status" tone="neutral">{formatPlantCondition(plant.condition ?? '')}</StatusBadge>
                        </article>
                      ))}
                    </div>
                  ) : (
                    <EmptyStatePanel
                      title="No plants saved in this version"
                      description="This version contains layout or field-level changes without plant records."
                      tone="subtle"
                    />
                  )}
                </section>
              </>
            ) : (
              <EmptyStatePanel
                title="Select a version"
                description="Choose a saved version on the left to review its plan and plants."
                tone="subtle"
              />
            )}
          </section>
        </div>
      )}
    </div>
  )
}
