import { useState } from 'react'
import { useParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { ErrorState, LoadingState, SuccessToast } from '../../components/shared/StatusView.jsx'
import Badge from '../../components/ui/Badge.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

export default function MembershipPage({ scope }) {
  const params = useParams()
  const id = params[`${scope}Id`]
  const [email, setEmail] = useState('')
  const [role, setRole] = useState(scope === 'farm' ? 'viewer' : 'member')
  const [pending, setPending] = useState(null)
  const [error, setError] = useState('')
  const [toast, setToast] = useState('')
  const basePath = `${scope === 'farm' ? 'farms' : 'communities'}/${id}`
  const pageState = useAsyncData(
    async () => {
      const [members, invitations, requests] = await Promise.all([
        api.listV1Path(`${basePath}/members`),
        api.listV1Path(`${basePath}/invitations`),
        scope === 'community' ? api.listV1Path(`${basePath}/join-requests`) : Promise.resolve([]),
      ])
      return { members, invitations, requests }
    },
    [basePath, scope],
    { members: [], invitations: [], requests: [] },
  )

  async function invite(event) {
    event.preventDefault()
    if (pending) return
    setPending('invite')
    setError('')
    try {
      const invitation = await api.postV1Path(`${basePath}/invitations`, { email, role })
      pageState.setData((current) => ({ ...current, invitations: [invitation, ...current.invitations] }))
      setEmail('')
      setToast('Invitation sent.')
    } catch (requestError) { setError(requestError.message) }
    finally { setPending(null) }
  }

  async function decide(request, action) {
    if (pending) return
    setPending(request.id)
    setError('')
    try {
      await api.postV1Path(`${basePath}/join-requests/${request.id}/${action}`)
      pageState.setData((current) => ({ ...current, requests: current.requests.filter((item) => item.id !== request.id) }))
      setToast(`Join request ${action}d.`)
    } catch (requestError) { setError(requestError.message) }
    finally { setPending(null) }
  }

  if (pageState.loading) return <LoadingState title="Loading members…" />
  if (pageState.error) return <ErrorState error={pageState.error} onRetry={pageState.reload} />

  return (
    <div className="page-stack stage1-page">
      <PageHeader eyebrow={`${scope} access`} title="Members and invitations" description={`Manage explicit roles for this ${scope}. Community roles never imply farm access.`} />
      <SuccessToast message={toast} onDismiss={() => setToast('')} />
      {error ? <ErrorState description={error} /> : null}
      <section className="stage1-split">
        <div className="panel">
          <h2>Members</h2>
          <div className="stage1-list">
            {pageState.data.members.map((member) => (
              <article key={member.id}><div><strong>{member.name || member.user?.email}</strong><p>{member.user?.email}</p></div><Badge>{member.role}</Badge></article>
            ))}
            {pageState.data.members.length === 0 ? <p>No members found.</p> : null}
          </div>
        </div>
        <form className="panel stage1-form" onSubmit={invite}>
          <h2>Invite a member</h2>
          <label className="field"><span>Email address</span><input type="email" required value={email} onChange={(event) => setEmail(event.target.value)} /></label>
          <label className="field"><span>Role</span><select value={role} onChange={(event) => setRole(event.target.value)}>
            {(scope === 'farm' ? ['owner', 'admin', 'manager', 'worker', 'viewer'] : ['admin', 'resource_manager', 'member']).map((value) => <option value={value} key={value}>{value.replace('_', ' ')}</option>)}
          </select></label>
          <Button type="submit" loading={pending === 'invite'}>Send invitation</Button>
        </form>
      </section>
      {scope === 'community' ? (
        <section className="panel">
          <h2>Join requests</h2>
          <div className="stage1-list">
            {pageState.data.requests.map((request) => <article key={request.id}><div><strong>{request.user?.email || request.email}</strong><p>{request.message}</p></div><div className="form-actions"><Button size="sm" onClick={() => decide(request, 'approve')} loading={pending === request.id}>Approve</Button><Button size="sm" variant="secondary" onClick={() => decide(request, 'reject')} disabled={Boolean(pending)}>Reject</Button></div></article>)}
            {pageState.data.requests.length === 0 ? <p>No pending join requests.</p> : null}
          </div>
        </section>
      ) : null}
    </div>
  )
}
