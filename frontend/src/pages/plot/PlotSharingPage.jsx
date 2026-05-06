import { useState } from 'react'
import { useParams } from 'react-router-dom'
import PlotSectionNav from '../../components/plot/PlotSectionNav.jsx'
import {
  EmptyState,
  ErrorState,
  LoadingState,
  SuccessToast,
} from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import EmptyStatePanel from '../../components/ui/EmptyStatePanel.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { api } from '../../lib/api.js'
import { formatDateTime } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

export default function PlotSharingPage() {
  const { plotId } = useParams()
  const [form, setForm] = useState({
    recipient_email: '',
    role: 'viewer',
  })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  const pageState = useAsyncData(
    async () => {
      const plots = await api.listPlots()
      const accessRole = plots.find((entry) => String(entry.id) === String(plotId))?.access_role ?? null
      const plot = await api.getPlot(plotId)
      const accessRights = accessRole === 'owner'
        ? await api.listAccessRights(plotId)
        : []

      return { plot, accessRole, accessRights }
    },
    [plotId],
    { plot: null, accessRole: null, accessRights: [] },
  )

  const isOwner = pageState.data.accessRole === 'owner'

  async function handleShare(event) {
    event.preventDefault()
    setBusy(true)
    setError('')

    try {
      await api.sharePlot(plotId, form)
      await pageState.reload()
      setForm({
        recipient_email: '',
        role: 'viewer',
      })
      setSuccess('Sharing access updated.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  async function handleRevoke(accessRightId) {
    setBusy(true)
    setError('')

    try {
      await api.revokeAccessRight(accessRightId)
      await pageState.reload()
      setSuccess('Sharing access revoked.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  if (pageState.loading) {
    return <LoadingState title="Loading sharing..." />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  if (!pageState.data.plot) {
    return <EmptyState title="Plot not found" description="The requested plot could not be loaded." />
  }

  return (
    <div className="page-stack">
      <PlotSectionNav
        plotId={plotId}
        plotName={pageState.data.plot.name}
        sectionKey="sharing"
        isOwner={isOwner}
        description="Keep sharing decisions separate from live layout work. Owners manage collaborators here without cluttering the editor."
        meta={(
          <>
            <StatusBadge kind="selection" tone="neutral">{pageState.data.accessRole ?? 'viewer'}</StatusBadge>
            {isOwner ? <StatusBadge kind="status" tone="success">Owner controls access</StatusBadge> : null}
          </>
        )}
      />
      <SuccessToast message={success} onDismiss={() => setSuccess('')} />

      {!isOwner ? (
        <EmptyState
          title="Owner access required"
          description="Only the plot owner can grant or revoke sharing access. You can still use the other plot workspaces you already have access to."
        />
      ) : (
        <div className="detail-grid plot-sharing-grid">
          <section className="panel page-stack plot-sharing-panel">
            <div className="plot-page-section-head">
              <div>
                <h2 className="section-title">Invite collaborator</h2>
                <p className="section-copy">Grant view-only or editing access without mixing sharing controls into the editor rail.</p>
              </div>
            </div>

            <form className="input-grid" onSubmit={handleShare}>
              <div className="field field-span-2">
                <label htmlFor="share-email">User email</label>
                <input
                  id="share-email"
                  type="email"
                  value={form.recipient_email}
                  onChange={(event) => setForm((current) => ({ ...current, recipient_email: event.target.value }))}
                  placeholder="teammate@example.com"
                  required
                />
              </div>
              <div className="field">
                <label htmlFor="share-role">Access level</label>
                <select
                  id="share-role"
                  value={form.role}
                  onChange={(event) => setForm((current) => ({ ...current, role: event.target.value }))}
                >
                  <option value="viewer">Viewer</option>
                  <option value="editor">Editor</option>
                </select>
              </div>

              {error ? <span className="field-error">{error}</span> : null}

              <div className="form-actions">
                <Button type="submit" loading={busy}>Share plot</Button>
              </div>
            </form>
          </section>

          <section className="panel page-stack plot-sharing-panel">
            <div className="plot-page-section-head">
              <div>
                <h2 className="section-title">Current access</h2>
                <p className="section-copy">Keep the list readable and compact so it feels like access management, not an admin table.</p>
              </div>
              <StatusBadge kind="selection" tone="neutral">{pageState.data.accessRights.length} active</StatusBadge>
            </div>

            {pageState.data.accessRights.length === 0 ? (
              <EmptyStatePanel
                title="No collaborators yet"
                description="Share this plot when you want someone else to review the plan or help edit it."
                tone="subtle"
              />
            ) : (
              <div className="plot-sharing-list">
                {pageState.data.accessRights.map((accessRight) => (
                  <article key={accessRight.access_right_id} className="plot-sharing-item">
                    <div className="plot-sharing-item-copy">
                      <div className="plot-sharing-item-head">
                        <strong>{accessRight.name || accessRight.email}</strong>
                        <StatusBadge kind="status" tone={accessRight.role === 'editor' ? 'warning' : 'neutral'}>
                          {accessRight.role}
                        </StatusBadge>
                      </div>
                      <span className="muted">{accessRight.email}</span>
                      <span className="plot-sharing-meta">Granted {formatDateTime(accessRight.granted_at)}</span>
                    </div>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => handleRevoke(accessRight.access_right_id)}
                      disabled={busy}
                    >
                      Remove
                    </Button>
                  </article>
                ))}
              </div>
            )}
          </section>
        </div>
      )}
    </div>
  )
}
