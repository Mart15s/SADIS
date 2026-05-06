import { useEffect, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import PlotSectionNav from '../../components/plot/PlotSectionNav.jsx'
import {
  EmptyState,
  ErrorState,
  LoadingState,
  ProcessingState,
  SuccessToast,
} from '../../components/shared/StatusView.jsx'
import Badge from '../../components/ui/Badge.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'
import { formatDate, safeNumber } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

function createEmptyForm(searchParams) {
  return {
    plant_id: searchParams.get('plantId') ?? '',
    task_id: searchParams.get('taskId') ?? '',
    quantity: '',
    harvested_on: searchParams.get('date') ?? new Date().toISOString().slice(0, 10),
    notes: '',
  }
}

export default function PlotHarvestsPage() {
  const { plotId } = useParams()
  const [searchParams, setSearchParams] = useSearchParams()
  const [form, setForm] = useState(() => createEmptyForm(searchParams))
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const pageState = useAsyncData(
    async () => {
      const plots = await api.listPlots()
      const accessRole = plots.find((entry) => String(entry.id) === String(plotId))?.access_role ?? null
      const [plot, plants, harvests] = await Promise.all([
        api.getPlot(plotId),
        api.listPlants(plotId),
        api.listHarvests(plotId),
      ])

      return { plot, plants, harvests, accessRole }
    },
    [plotId],
    { plot: null, plants: [], harvests: [], accessRole: null },
  )

  useEffect(() => {
    setForm(createEmptyForm(searchParams))
  }, [searchParams])

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    setSuccess('')

    try {
      const created = form.task_id
        ? (await api.completeTask(Number(form.task_id), {
          harvest: {
            quantity: Number(form.quantity),
            harvested_on: form.harvested_on,
            notes: form.notes || null,
          },
        })).harvest_record
        : await api.createHarvest(plotId, {
          plant_id: Number(form.plant_id),
          task_id: null,
          quantity: Number(form.quantity),
          harvested_on: form.harvested_on,
          notes: form.notes || null,
        })

      pageState.setData((current) => ({
        ...current,
        harvests: [created, ...current.harvests],
      }))
      setForm(createEmptyForm(new URLSearchParams()))
      setSearchParams({})
      setSuccess(form.task_id ? 'Harvest recorded and task completed successfully.' : 'Harvest record registered successfully.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  if (pageState.loading) {
    return <LoadingState title="Loading harvest workflow..." />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  return (
    <div className="page-stack">
      <PlotSectionNav
        plotId={plotId}
        plotName={pageState.data.plot?.name ?? 'Plot'}
        sectionKey="harvests"
        isOwner={pageState.data.accessRole === 'owner'}
        description="Register explicit harvest quantities and review the saved harvest history for this plot."
        meta={(
          <>
            <Badge tone="soft">{pageState.data.harvests.length} harvest records</Badge>
            <Badge tone="neutral">{pageState.data.plants.length} available plants</Badge>
          </>
        )}
        actions={(
          <Link to={`/plots/${plotId}/analytics`}>
            <Button variant="secondary">Analytics</Button>
          </Link>
        )}
      />

      <SuccessToast message={success} onDismiss={() => setSuccess('')} />

      <div className="detail-grid">
        <form className="panel input-grid" onSubmit={handleSubmit}>
          <h3>Register harvest</h3>
          <div className="field">
            <label htmlFor="harvest-plant">Plant</label>
            <select
              id="harvest-plant"
              value={form.plant_id}
              onChange={(event) => setForm((current) => ({ ...current, plant_id: event.target.value }))}
              required
            >
              <option value="">Select plant</option>
              {pageState.data.plants.map((plant) => (
                <option key={plant.id} value={plant.id}>
                  {plant.name}
                </option>
              ))}
            </select>
          </div>
          <div className="field">
            <label htmlFor="harvest-task">Linked harvest task</label>
            <input
              id="harvest-task"
              value={form.task_id}
              onChange={(event) => setForm((current) => ({ ...current, task_id: event.target.value }))}
              placeholder="Optional task ID"
            />
          </div>
          <div className="field">
            <label htmlFor="harvest-quantity">Quantity</label>
            <input
              id="harvest-quantity"
              type="number"
              min="0.01"
              step="0.01"
              value={form.quantity}
              onChange={(event) => setForm((current) => ({ ...current, quantity: event.target.value }))}
              required
            />
          </div>
          <div className="field">
            <label htmlFor="harvest-date">Harvest date</label>
            <input
              id="harvest-date"
              type="date"
              value={form.harvested_on}
              onChange={(event) => setForm((current) => ({ ...current, harvested_on: event.target.value }))}
              required
            />
          </div>
          <div className="field">
            <label htmlFor="harvest-notes">Notes</label>
            <textarea
              id="harvest-notes"
              value={form.notes}
              onChange={(event) => setForm((current) => ({ ...current, notes: event.target.value }))}
            />
          </div>

          {error ? <span className="field-error">{error}</span> : null}

          {submitting ? (
            <ProcessingState
              title="Registering harvest"
              description="Saving the harvest quantity and linking it to the selected plant."
              steps={['Validating record', 'Saving harvest', 'Refreshing history']}
              compact
            />
          ) : null}

          <Button type="submit" loading={submitting}>
            {submitting ? 'Saving harvest' : 'Register harvest'}
          </Button>
        </form>

        <section className="panel table-stack">
          <div className="list-head">
            <h3>Harvest history</h3>
            <span>{pageState.data.harvests.length} records</span>
          </div>

          {pageState.data.harvests.length === 0 ? (
            <EmptyState title="No harvest history" description="Register the first harvest record for this plot." />
          ) : (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Plant</th>
                    <th>Zone</th>
                    <th>Quantity</th>
                    <th>Task</th>
                  </tr>
                </thead>
                <tbody>
                  {pageState.data.harvests.map((record) => (
                    <tr key={record.id}>
                      <td>{formatDate(record.harvested_on)}</td>
                      <td>{record.plant_name}</td>
                      <td>{record.zone_name || 'Not set'}</td>
                      <td>{safeNumber(record.quantity, 2)}</td>
                      <td>{record.task_name || record.task_id || 'Manual'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>
    </div>
  )
}
