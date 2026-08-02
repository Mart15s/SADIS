import { useState } from 'react'
import { ErrorState, LoadingState, SuccessToast } from '../../components/shared/StatusView.jsx'
import Badge from '../../components/ui/Badge.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

const analyticsScopes = ['crop_summary', 'harvest_summary', 'task_summary']
const farmPermissions = [
  'view_farm',
  'manage_fields',
  'manage_crops',
  'manage_tasks',
  'manage_inventory',
  'view_analytics',
  'manage_members',
]

function toggleValue(values, value) {
  return values.includes(value) ? values.filter((item) => item !== value) : [...values, value]
}

function humanize(value) {
  return value.replaceAll('_', ' ')
}

export default function FarmCommunityLinksPanel({ scope, context }) {
  const canManage = context?.type === scope && context.permissions?.includes('manage_members')
  const [communityId, setCommunityId] = useState('')
  const [selectedAnalytics, setSelectedAnalytics] = useState([])
  const [selectedPermissions, setSelectedPermissions] = useState(['view_farm'])
  const [pending, setPending] = useState('')
  const [error, setError] = useState('')
  const [toast, setToast] = useState('')

  const pageState = useAsyncData(
    async () => {
      if (!canManage) return { links: [], communities: [] }
      const [links, communities] = await Promise.all([
        api.listV1Path('farm-community-links', { [`${scope}_id`]: context.id }),
        scope === 'farm' ? api.listV1('communities') : Promise.resolve([]),
      ])
      return { links, communities }
    },
    [context?.id, canManage, scope],
    { links: [], communities: [] },
  )

  if (!canManage) return null
  if (pageState.loading) return <LoadingState title="Loading farm–community links…" />
  if (pageState.error) return <ErrorState error={pageState.error} onRetry={pageState.reload} />

  async function requestLink(event) {
    event.preventDefault()
    if (pending || !communityId) return
    setPending('create')
    setError('')
    try {
      await api.postV1Path(`farms/${context.id}/communities/${communityId}`, {
        analytics_scopes: selectedAnalytics,
        farm_access_permissions: selectedPermissions,
      })
      setCommunityId('')
      setSelectedAnalytics([])
      setSelectedPermissions(['view_farm'])
      setToast('Community link requested.')
      await pageState.reload()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setPending('')
    }
  }

  async function decide(link, decision) {
    if (pending) return
    setPending(`${decision}-${link.id}`)
    setError('')
    try {
      await api.postV1Path(`farm-community-links/${link.id}/${decision}`)
      setToast(`Farm link ${decision === 'approve' ? 'approved' : 'rejected'}.`)
      await pageState.reload()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setPending('')
    }
  }

  async function revoke(link) {
    if (pending || !window.confirm('Revoke this farm–community link?')) return
    setPending(`revoke-${link.id}`)
    setError('')
    try {
      await api.deleteV1Path(`farms/${link.farm.id}/community-links/${link.id}`)
      setToast('Farm–community link revoked.')
      await pageState.reload()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setPending('')
    }
  }

  return (
    <section className="panel page-stack" aria-labelledby="farm-community-links-title">
      <div>
        <h2 id="farm-community-links-title">Farm–community links</h2>
        <p>
          Share only the selected analytics scopes and farm permissions. A community role alone does
          not grant farm access.
        </p>
      </div>
      <SuccessToast message={toast} onDismiss={() => setToast('')} />
      {error ? <ErrorState description={error} /> : null}

      {scope === 'farm' ? (
        <form className="stage1-form" onSubmit={requestLink}>
          <label className="field">
            <span>Community</span>
            <select
              required
              value={communityId}
              onChange={(event) => setCommunityId(event.target.value)}
            >
              <option value="">Select community</option>
              {pageState.data.communities.map((community) => (
                <option value={community.id} key={community.id}>
                  {community.name}
                </option>
              ))}
            </select>
          </label>
          <fieldset className="stage1-checkbox-group">
            <legend>Shared analytics</legend>
            {analyticsScopes.map((value) => (
              <label className="stage1-checkbox" key={value}>
                <input
                  type="checkbox"
                  checked={selectedAnalytics.includes(value)}
                  onChange={() => setSelectedAnalytics((current) => toggleValue(current, value))}
                />
                <span>{humanize(value)}</span>
              </label>
            ))}
          </fieldset>
          <fieldset className="stage1-checkbox-group">
            <legend>Farm permissions</legend>
            {farmPermissions.map((value) => (
              <label className="stage1-checkbox" key={value}>
                <input
                  type="checkbox"
                  checked={selectedPermissions.includes(value)}
                  onChange={() => setSelectedPermissions((current) => toggleValue(current, value))}
                />
                <span>{humanize(value)}</span>
              </label>
            ))}
          </fieldset>
          <Button type="submit" loading={pending === 'create'}>
            Request community link
          </Button>
        </form>
      ) : null}

      <div className="stage1-list">
        {pageState.data.links.map((link) => (
          <article key={link.id}>
            <div>
              <strong>{scope === 'farm' ? link.community?.name : link.farm?.name}</strong>
              <p>
                Analytics: {link.analytics_scopes?.map(humanize).join(', ') || 'none'} · Farm
                access: {link.farm_access_permissions?.map(humanize).join(', ') || 'none'}
              </p>
            </div>
            <div className="stage1-member-actions">
              <Badge tone={link.status === 'active' ? 'success' : 'neutral'}>{link.status}</Badge>
              {scope === 'community' && link.status === 'pending' ? (
                <>
                  <Button
                    size="sm"
                    onClick={() => decide(link, 'approve')}
                    loading={pending === `approve-${link.id}`}
                  >
                    Approve
                  </Button>
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => decide(link, 'reject')}
                    disabled={Boolean(pending)}
                  >
                    Reject
                  </Button>
                </>
              ) : null}
              {link.status === 'active' ? (
                <Button
                  size="sm"
                  variant="danger"
                  onClick={() => revoke(link)}
                  loading={pending === `revoke-${link.id}`}
                >
                  Revoke
                </Button>
              ) : null}
            </div>
          </article>
        ))}
        {pageState.data.links.length === 0 ? <p>No farm–community links found.</p> : null}
      </div>
    </section>
  )
}
