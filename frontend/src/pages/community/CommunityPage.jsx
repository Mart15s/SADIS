import { startTransition, useDeferredValue, useState } from 'react'
import { MapLayerControl } from '../../components/garden/GardenControls.jsx'
import PageHeader from '../../components/layout/PageHeader.jsx'
import PlanPreview from '../../components/plot/PlanPreview.jsx'
import {
  EmptyState,
  ErrorState,
  LoadingState,
  ProcessingState,
  SuccessToast,
} from '../../components/shared/StatusView.jsx'
import Badge from '../../components/ui/Badge.jsx'
import Button from '../../components/ui/Button.jsx'
import { Dialog, DialogBody, DialogFooter, DialogHeader } from '../../components/ui/Dialog.jsx'
import FilterBar from '../../components/ui/FilterBar.jsx'
import FormField from '../../components/ui/FormField.jsx'
import { StatRow } from '../../components/ui/DefinitionList.jsx'
import ResourceCard, {
  ResourceCardBody,
  ResourceCardFooter,
  ResourceCardHeader,
  ResourceCardMeta,
} from '../../components/ui/ResourceCard.jsx'
import ResponsiveList from '../../components/ui/ResponsiveList.jsx'
import { useAuth } from '../../context/auth-context.js'
import { api } from '../../lib/api.js'
import { formatDateTime } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

const initialPostForm = {
  name: '',
  text: '',
  share: true,
  fk_plot_id: '',
}

function renderPostText(text) {
  const value = text ?? ''

  if (value.length <= 260) {
    return <p>{value}</p>
  }

  return (
    <div className="community-post-text">
      <p>{value.slice(0, 260).trim()}...</p>
      <details className="community-read-more">
        <summary>Skaityti daugiau</summary>
        <p>{value}</p>
      </details>
    </div>
  )
}

export default function CommunityPage() {
  const { isAuthenticated } = useAuth()
  const [search, setSearch] = useState('')
  const [selectedPlotId, setSelectedPlotId] = useState('')
  const [form, setForm] = useState(initialPostForm)
  const [createOpen, setCreateOpen] = useState(false)
  const [createError, setCreateError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [toastMessage, setToastMessage] = useState('')
  const deferredSearch = useDeferredValue(search)

  const plotsState = useAsyncData(
    async () => (isAuthenticated ? api.listPlots() : []),
    [isAuthenticated],
    [],
  )
  const postsState = useAsyncData(
    async () => api.listCommunityPosts(isAuthenticated && selectedPlotId ? selectedPlotId : null),
    [isAuthenticated, selectedPlotId],
    [],
  )

  const filteredPosts = postsState.data.filter((post) => {
    const needle = deferredSearch.trim().toLowerCase()

    if (!needle) {
      return true
    }

    return [post.name, post.text, post.owner_name, post.plot_name]
      .filter(Boolean)
      .some((value) => value.toLowerCase().includes(needle))
  })

  function handleFormChange(event) {
    const { name, value, type, checked } = event.target
    setForm((current) => ({
      ...current,
      [name]: type === 'checkbox' ? checked : value,
    }))
  }

  async function handleCreatePost(event) {
    event.preventDefault()
    setSubmitting(true)
    setCreateError('')

    try {
      const created = await api.createCommunityPost({
        ...form,
        fk_plot_id: form.fk_plot_id || null,
      })
      postsState.setData((current) => [created, ...current])
      setForm(initialPostForm)
      setCreateOpen(false)
      setToastMessage('Bendruomenės įrašas paskelbtas.')
    } catch (error) {
      setCreateError(error.message)
    } finally {
      setSubmitting(false)
    }
  }

  if (postsState.loading || (isAuthenticated && plotsState.loading)) {
    return <LoadingState title="Įkeliama bendruomenės sklaidos juosta..." />
  }

  if (plotsState.error) {
    return <ErrorState error={plotsState.error} onRetry={plotsState.reload} />
  }

  if (postsState.error) {
    return <ErrorState error={postsState.error} onRetry={postsState.reload} />
  }

  return (
    <div className="page-stack">
      <PageHeader
        title="Bendruomenė"
        eyebrow="Bendrinamos daržo erdvės"
        description="Peržiūrėkite bendrinamus sklypų planus su savininkais, zonomis ir planavimo kontekstu."
        meta={(
          <>
            <Badge tone="soft">{filteredPosts.length} matomi įrašai</Badge>
            <Badge tone={isAuthenticated ? 'success' : 'warning'}>{isAuthenticated ? 'Prisijungta' : 'Svečio peržiūra'}</Badge>
          </>
        )}
        actions={(
          <Button onClick={() => setCreateOpen(true)}>
            Kurti įrašą
          </Button>
        )}
      />

      <SuccessToast message={toastMessage} onDismiss={() => setToastMessage('')} />

      <FilterBar
        resultCount={filteredPosts.length}
        onClear={search || selectedPlotId ? () => {
          setSearch('')
          startTransition(() => {
            setSelectedPlotId('')
          })
        } : null}
      >
        <FormField id="community-search" label="Ieškoti įrašų">
          <input
            id="community-search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Ieškoti pagal pavadinimą, tekstą, autorių arba sklypą"
          />
        </FormField>

        {isAuthenticated ? (
          <FormField id="plot-filter" label="Sklypo filtras">
            <select
              id="plot-filter"
              value={selectedPlotId}
              onChange={(event) => {
                startTransition(() => {
                  setSelectedPlotId(event.target.value)
                })
              }}
            >
              <option value="">Visi pasiekiami įrašai</option>
              {plotsState.data.map((plot) => (
                <option key={plot.id} value={plot.id}>
                  {plot.name}
                </option>
              ))}
            </select>
          </FormField>
        ) : null}
      </FilterBar>

      <Dialog
        open={createOpen}
        onClose={() => {
          if (!submitting) {
            setCreateOpen(false)
            setCreateError('')
          }
        }}
        labelledBy="community-create-title"
        describedBy="community-create-subtitle"
        size="md"
        className="community-create-dialog"
      >
        <DialogHeader
          title="Kurti bendruomenės įrašą"
          subtitle="Pasidalykite daržo naujiena ir, jei reikia, pridėkite vieną savo sklypą."
          titleId="community-create-title"
          subtitleId="community-create-subtitle"
          onClose={() => {
            if (!submitting) {
              setCreateOpen(false)
              setCreateError('')
            }
          }}
          closeLabel="Uždaryti įrašo kūrimą"
        />
        {!isAuthenticated ? (
          <>
            <DialogBody>
              <EmptyState
                title="Prisijunkite, kad galėtumėte skelbti"
                description="Svečiai gali naršyti viešus bendruomenės įrašus, bet įrašui sukurti reikia prisijungti."
              />
            </DialogBody>
            <DialogFooter>
              <Button type="button" variant="secondary" onClick={() => setCreateOpen(false)}>
                Atšaukti
              </Button>
            </DialogFooter>
          </>
        ) : (
          <form onSubmit={handleCreatePost}>
            <DialogBody className="community-create-body">
              <FormField id="post-name" label="Pavadinimas">
                <input id="post-name" name="name" value={form.name} onChange={handleFormChange} required />
              </FormField>
              <FormField id="post-text" label="Tekstas">
                <textarea id="post-text" name="text" value={form.text} onChange={handleFormChange} required rows={6} />
              </FormField>
              <div className="community-create-options">
                <FormField id="post-plot" label="Susieti su sklypu">
                  <select id="post-plot" name="fk_plot_id" value={form.fk_plot_id} onChange={handleFormChange}>
                    <option value="">Be sklypo</option>
                    {plotsState.data.map((plot) => (
                      <option key={plot.id} value={plot.id}>
                        {plot.name}
                      </option>
                    ))}
                  </select>
                </FormField>
                <FormField id="post-share" label="Matomumas">
                  <select
                    id="post-share"
                    name="share"
                    value={String(form.share)}
                    onChange={(event) => {
                      setForm((current) => ({
                        ...current,
                        share: event.target.value === 'true',
                      }))
                    }}
                  >
                    <option value="true">Bendrinamas</option>
                    <option value="false">Privatus</option>
                  </select>
                </FormField>
              </div>

              {createError ? <span className="field-error">{createError}</span> : null}

              {submitting ? (
                <ProcessingState
                  title="Skelbiamas įrašas"
                  description="Ruošiami įrašo duomenys ir pridedama naujausia sklypo peržiūra."
                  steps={['Tikrinamas turinys', 'Skelbiamas įrašas', 'Atnaujinama sklaidos juosta']}
                  compact
                />
              ) : null}
            </DialogBody>
            <DialogFooter>
              <Button type="submit" loading={submitting}>
                {submitting ? 'Skelbiamas įrašas' : 'Kurti įrašą'}
              </Button>
              <Button
                type="button"
                variant="secondary"
                onClick={() => {
                  setCreateOpen(false)
                  setCreateError('')
                }}
                disabled={submitting}
              >
                Atšaukti
              </Button>
            </DialogFooter>
          </form>
        )}
      </Dialog>

      <div className="community-grid">
        <section className="post-stack">
          {postsState.loading ? <LoadingState title="Atnaujinama bendruomenės sklaidos juosta..." layout="rows" /> : null}
          {filteredPosts.length === 0 ? (
            <EmptyState
              title="Bendruomenės įrašų nėra"
              description="Bandykite kitą paieškos frazę arba išvalykite sklypo filtrą."
            />
          ) : (
            <ResponsiveList className="resource-feed-list" ariaLabel="Bendruomenės įrašai">
              {filteredPosts.map((post) => (
                <ResourceCard key={post.id} className="post-card">
                  <ResourceCardHeader
                    title={post.name}
                    badge={<Badge tone="soft">{post.owner_name || 'Nežinomas autorius'}</Badge>}
                  />
                  <ResourceCardMeta>
                    <Badge tone={post.share ? 'success' : 'warning'}>{post.share ? 'Bendrinamas' : 'Privatus'}</Badge>
                    {post.plot_name ? <Badge tone="neutral">{post.plot_name}</Badge> : null}
                  </ResourceCardMeta>
                  <ResourceCardBody>
                    {renderPostText(post.text)}
                    {post.plot_preview ? (
                      <div className="community-plan-shell">
                        <MapLayerControl
                          title="Bendrinamo sklypo sluoksniai"
                          items={[
                            { id: 'boundary', label: 'Riba', active: true, color: '#47633b' },
                            { id: 'zones', label: `${post.plot_preview.zones?.length ?? 0} zonos`, active: true, color: '#b9683f' },
                          ]}
                        />
                        <PlanPreview
                          className="community-plan-preview"
                          plotName={post.plot_preview.plot_name}
                          plotSize={post.plot_preview.plot_size}
                          plotGeometry={post.plot_preview.geometry}
                          zones={post.plot_preview.zones}
                        />
                      </div>
                    ) : null}
                  </ResourceCardBody>
                  <ResourceCardFooter>
                    <StatRow label="Autorius" value={post.owner_name || 'Nežinomas autorius'} />
                    <StatRow label="Sklypas" value={post.plot_name || 'Bendras įrašas'} />
                    <StatRow label="Paskelbta" value={formatDateTime(post.created_at)} />
                  </ResourceCardFooter>
                </ResourceCard>
              ))}
            </ResponsiveList>
          )}
        </section>
      </div>
    </div>
  )
}
