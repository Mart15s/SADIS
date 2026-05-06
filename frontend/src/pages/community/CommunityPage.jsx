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
import { useAuth } from '../../context/AuthContext.jsx'
import { api } from '../../lib/api.js'
import { formatDateTime } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

const initialPostForm = {
  name: '',
  text: '',
  share: true,
  fk_plot_id: '',
}

export default function CommunityPage() {
  const { isAuthenticated } = useAuth()
  const [search, setSearch] = useState('')
  const [selectedPlotId, setSelectedPlotId] = useState('')
  const [form, setForm] = useState(initialPostForm)
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
      setToastMessage('Community post published.')
    } catch (error) {
      setCreateError(error.message)
    } finally {
      setSubmitting(false)
    }
  }

  if (postsState.loading || (isAuthenticated && plotsState.loading)) {
    return <LoadingState title="Loading community feed..." />
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
        title="Community"
        eyebrow="Shared garden spaces"
        description="Explore shared plot plans as map snapshots with owners, zones, and garden planning context."
        meta={(
          <>
            <Badge tone="soft">{filteredPosts.length} visible posts</Badge>
            <Badge tone={isAuthenticated ? 'success' : 'warning'}>{isAuthenticated ? 'Signed in' : 'Guest browsing'}</Badge>
          </>
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
        <FormField id="community-search" label="Search posts">
          <input
            id="community-search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search by title, text, author, or plot"
          />
        </FormField>

        {isAuthenticated ? (
          <FormField id="plot-filter" label="Plot filter">
            <select
              id="plot-filter"
              value={selectedPlotId}
              onChange={(event) => {
                startTransition(() => {
                  setSelectedPlotId(event.target.value)
                })
              }}
            >
              <option value="">All accessible posts</option>
              {plotsState.data.map((plot) => (
                <option key={plot.id} value={plot.id}>
                  {plot.name}
                </option>
              ))}
            </select>
          </FormField>
        ) : null}
      </FilterBar>

      <div className="community-grid">
        <section className="post-stack">
          {postsState.loading ? <LoadingState title="Refreshing community feed..." layout="rows" /> : null}
          {filteredPosts.length === 0 ? (
            <EmptyState
              title="No community posts"
              description="Try a different search term or clear the plot filter."
            />
          ) : (
            <ResponsiveList className="resource-feed-list" ariaLabel="Community posts">
              {filteredPosts.map((post) => (
                <ResourceCard key={post.id} className="post-card">
                  <ResourceCardHeader
                    title={post.name}
                    badge={<Badge tone="soft">{post.owner_name || 'Unknown author'}</Badge>}
                  />
                  <ResourceCardMeta>
                    <Badge tone={post.share ? 'success' : 'warning'}>{post.share ? 'Shared' : 'Private'}</Badge>
                    {post.plot_name ? <Badge tone="neutral">{post.plot_name}</Badge> : null}
                  </ResourceCardMeta>
                  <ResourceCardBody>
                    <p>{post.text}</p>
                    {post.plot_preview ? (
                      <div className="community-plan-shell">
                        <MapLayerControl
                          title="Shared plot layers"
                          items={[
                            { id: 'boundary', label: 'Boundary', active: true, color: '#47633b' },
                            { id: 'zones', label: `${post.plot_preview.zones?.length ?? 0} zones`, active: true, color: '#b9683f' },
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
                    <StatRow label="Author" value={post.owner_name || 'Unknown author'} />
                    <StatRow label="Plot" value={post.plot_name || 'General post'} />
                    <StatRow label="Posted" value={formatDateTime(post.created_at)} />
                  </ResourceCardFooter>
                </ResourceCard>
              ))}
            </ResponsiveList>
          )}
        </section>

        <aside className="panel input-grid">
          <div className="page-stack" style={{ gap: '0.4rem' }}>
            <h3 style={{ margin: 0 }}>Create community post</h3>
            <p className="section-copy">Share a polished update, optionally attach one of your plots, and keep the feed useful for other gardeners.</p>
          </div>
          {!isAuthenticated ? (
            <EmptyState
              title="Sign in to post"
              description="Guests can browse public community posts, but creating a post requires authentication."
            />
          ) : (
            <form className="input-grid" onSubmit={handleCreatePost}>
              <FormField id="post-name" label="Title">
                <input id="post-name" name="name" value={form.name} onChange={handleFormChange} required />
              </FormField>
              <FormField id="post-text" label="Text">
                <textarea id="post-text" name="text" value={form.text} onChange={handleFormChange} required />
              </FormField>
              <FormField id="post-plot" label="Link to plot">
                <select id="post-plot" name="fk_plot_id" value={form.fk_plot_id} onChange={handleFormChange}>
                  <option value="">No plot</option>
                  {plotsState.data.map((plot) => (
                    <option key={plot.id} value={plot.id}>
                      {plot.name}
                    </option>
                  ))}
                </select>
              </FormField>
              <FormField id="post-share" label="Visibility">
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
                  <option value="true">Shared</option>
                  <option value="false">Private</option>
                </select>
              </FormField>

              {createError ? <span className="field-error">{createError}</span> : null}

              {submitting ? (
                <ProcessingState
                  title="Publishing post"
                  description="Preparing the post payload and attaching the latest plot preview."
                  steps={['Validating content', 'Publishing post', 'Refreshing feed']}
                  compact
                />
              ) : null}

              <Button type="submit" loading={submitting}>
                {submitting ? 'Posting update' : 'Create post'}
              </Button>
            </form>
          )}
        </aside>
      </div>
    </div>
  )
}
