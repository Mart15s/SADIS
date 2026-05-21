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
import { formatAccessRole, formatDateTime } from '../../lib/constants.js'
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
      setSuccess('Bendrinimo prieiga atnaujinta.')
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
      setSuccess('Bendrinimo prieiga pašalinta.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  if (pageState.loading) {
    return <LoadingState title="Įkeliamas bendrinimas..." />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  if (!pageState.data.plot) {
    return <EmptyState title="Sklypas nerastas" description="Pasirinkto sklypo nepavyko įkelti." />
  }

  return (
    <div className="page-stack">
      <PlotSectionNav
        plotId={plotId}
        plotName={pageState.data.plot.name}
        sectionKey="sharing"
        isOwner={isOwner}
        description="Bendrinimo sprendimai laikomi atskirai nuo plano redagavimo. Savininkai čia valdo bendradarbius."
        meta={(
          <>
            <StatusBadge kind="selection" tone="neutral">{formatAccessRole(pageState.data.accessRole)}</StatusBadge>
            {isOwner ? <StatusBadge kind="status" tone="success">Savininko valdymas</StatusBadge> : null}
          </>
        )}
      />
      <SuccessToast message={success} onDismiss={() => setSuccess('')} />

      {!isOwner ? (
        <EmptyState
          title="Reikalinga savininko prieiga"
          description="Tik sklypo savininkas gali suteikti arba panaikinti bendrinimo prieigą."
        />
      ) : (
        <div className="detail-grid plot-sharing-grid">
          <section className="panel page-stack plot-sharing-panel">
            <div className="plot-page-section-head">
              <div>
                <h2 className="section-title">Pakviesti bendradarbį</h2>
                <p className="section-copy">Suteikite peržiūros arba redagavimo teisę kitam naudotojui.</p>
              </div>
            </div>

            <form className="input-grid" onSubmit={handleShare}>
              <div className="field field-span-2">
                <label htmlFor="share-email">Naudotojo el. paštas</label>
                <input
                  id="share-email"
                  type="email"
                  value={form.recipient_email}
                  onChange={(event) => setForm((current) => ({ ...current, recipient_email: event.target.value }))}
                  placeholder="naudotojas@example.com"
                  required
                />
              </div>
              <div className="field">
                <label htmlFor="share-role">Prieigos lygis</label>
                <select
                  id="share-role"
                  value={form.role}
                  onChange={(event) => setForm((current) => ({ ...current, role: event.target.value }))}
                >
                  <option value="viewer">{formatAccessRole('viewer')}</option>
                  <option value="editor">{formatAccessRole('editor')}</option>
                </select>
              </div>

              {error ? <span className="field-error">{error}</span> : null}

              <div className="form-actions">
                <Button type="submit" loading={busy}>Bendrinti sklypą</Button>
              </div>
            </form>
          </section>

          <section className="panel page-stack plot-sharing-panel">
            <div className="plot-page-section-head">
              <div>
                <h2 className="section-title">Dabartinės prieigos</h2>
                <p className="section-copy">Čia matomi visi naudotojai, turintys prieigą prie šio sklypo.</p>
              </div>
              <StatusBadge kind="selection" tone="neutral">{pageState.data.accessRights.length} aktyvių</StatusBadge>
            </div>

            {pageState.data.accessRights.length === 0 ? (
              <EmptyStatePanel
                title="Aktyvių prieigų nėra"
                description="Šis sklypas dar nėra bendrinamas su kitais naudotojais."
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
                          {formatAccessRole(accessRight.role)}
                        </StatusBadge>
                      </div>
                      <span className="muted">{accessRight.email}</span>
                      <span className="plot-sharing-meta">Suteikta {formatDateTime(accessRight.granted_at)}</span>
                    </div>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => handleRevoke(accessRight.access_right_id)}
                      disabled={busy}
                    >
                      Pašalinti
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
