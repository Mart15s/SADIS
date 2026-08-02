import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
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
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

export default function PlotEditPage() {
  const navigate = useNavigate()
  const { plotId } = useParams()
  const [form, setForm] = useState(null)
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [toastMessage, setToastMessage] = useState('')

  const pageState = useAsyncData(
    async () => {
      const [plot, plots] = await Promise.all([
        api.getPlot(plotId),
        api.listPlots(),
      ])
      const accessRole = plots.find((entry) => String(entry.id) === String(plotId))?.access_role ?? null
      return { plot, accessRole }
    },
    [plotId],
    { plot: null, accessRole: null },
  )

  useEffect(() => {
    if (pageState.data.plot) {
      setForm({
        name: pageState.data.plot.name ?? '',
        city: pageState.data.plot.city ?? '',
        plot_size: pageState.data.plot.plot_size ?? '',
        creation_date: pageState.data.plot.creation_date ?? '',
        description: pageState.data.plot.description ?? '',
        share: Boolean(pageState.data.plot.share),
      })
    }
  }, [pageState.data.plot])

  if (pageState.loading) {
    return <LoadingState title="Loading field editor…" />
  }

  if (pageState.error) {
    return <ErrorState error={pageState.error} onRetry={pageState.reload} />
  }

  if (!pageState.data.plot) {
    return <EmptyState title="Field not found" description="The selected field could not be loaded." />
  }

  if (!form) {
    return <LoadingState title="Preparing field editor…" />
  }

  const canEdit = ['owner', 'editor'].includes(pageState.data.accessRole)
  const isOwner = pageState.data.accessRole === 'owner'

  function handleChange(event) {
    const { name, value } = event.target
    setForm((current) => ({
      ...current,
      [name]: value,
    }))
  }

  async function handleSave(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')

    try {
      const updatedPlot = await api.updatePlot(plotId, {
        ...form,
        plot_size: Number(form.plot_size),
        share: form.share,
      })
      if (updatedPlot) {
        pageState.setData((current) => ({ ...current, plot: updatedPlot }))
      } else {
        await pageState.reload()
      }
      setToastMessage('Plot details saved.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  async function handleDelete() {
    if (!window.confirm('Delete this plot permanently?')) return
    setSubmitting(true)
    setError('')

    try {
      await api.deletePlot(plotId)
      navigate('/plots', { replace: true, state: { toast: 'Plot deleted.' } })
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  if (!canEdit) {
    return (
      <div className="page-stack">
        <PageHeader
          title="Sklypo redaktorius"
          description="Your current role can view this field, but only owners and editors can update it."
        />
        <EmptyState
          title="Redagavimo prieiga negalima"
          description="Return to the field overview if you only need to read its data."
          action={(
            <Link to={`/plots/${plotId}`}>
              <Button>Return to field</Button>
            </Link>
          )}
        />
      </div>
    )
  }

  return (
    <div className="page-stack">
      <PageHeader
        eyebrow="Sklypo nustatymai"
        title="Edit field details"
        description={`Update ${pageState.data.plot.name}'s identity, location, size, and community visibility without changing its layout.`}
        meta={(
          <>
            <Badge tone="soft">{pageState.data.accessRole}</Badge>
            <Badge tone={form.share ? 'success' : 'neutral'}>{form.share ? 'Shared' : 'Private'}</Badge>
          </>
        )}
      />

      <SuccessToast message={toastMessage} onDismiss={() => setToastMessage('')} />

      <form className="panel split-form" onSubmit={handleSave}>
        <div className="field">
          <label htmlFor="edit-name">Name</label>
          <input id="edit-name" name="name" value={form.name} onChange={handleChange} required />
        </div>
        <div className="field">
          <label htmlFor="edit-city">City</label>
          <input id="edit-city" name="city" value={form.city} onChange={handleChange} required />
        </div>
        <div className="field">
          <label htmlFor="edit-size">Sklypo plotas</label>
          <input
            id="edit-size"
            name="plot_size"
            type="number"
            min="0.01"
            step="0.01"
            value={form.plot_size}
            onChange={handleChange}
            required
          />
        </div>
        <div className="field">
          <label htmlFor="edit-date">Created on</label>
          <input
            id="edit-date"
            name="creation_date"
            type="date"
            value={form.creation_date}
            onChange={handleChange}
            required
          />
        </div>
        <div className="field">
          <label htmlFor="edit-description">Description</label>
          <textarea id="edit-description" name="description" value={form.description} onChange={handleChange} />
        </div>
        <div className="field">
          <label htmlFor="edit-share">Community visibility</label>
          <select
            id="edit-share"
            value={String(form.share)}
            onChange={(event) => {
              setForm((current) => ({
                ...current,
                share: event.target.value === 'true',
              }))
            }}
          >
            <option value="false">Private</option>
            <option value="true">Shared</option>
          </select>
        </div>

        {error ? <span className="field-error">{error}</span> : null}

        {submitting ? (
          <ProcessingState
            title="Saugomi sklypo nustatymai"
            description="Atnaujinami sklypo duomenys ir darbo sritis."
            steps={['Tikrinami laukai', 'Saugomi metaduomenys', 'Atnaujinamas sklypo vaizdas']}
            compact
          />
        ) : null}

        <div className="form-actions">
          <Button type="submit" loading={submitting}>
            {submitting ? 'Saving changes' : 'Save changes'}
          </Button>
          <Link to={`/plots/${plotId}`}>
            <Button variant="secondary">Cancel</Button>
          </Link>
          {isOwner ? (
            <Button variant="danger" onClick={handleDelete} disabled={submitting}>
              Delete field
            </Button>
          ) : null}
        </div>
      </form>
    </div>
  )
}
