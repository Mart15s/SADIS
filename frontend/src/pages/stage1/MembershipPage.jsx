import { useState } from 'react'
import { useParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { ErrorState, LoadingState, SuccessToast } from '../../components/shared/StatusView.jsx'
import Badge from '../../components/ui/Badge.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'
import { useWorkspace } from '../../context/useWorkspace.js'
import FarmCommunityLinksPanel from './FarmCommunityLinksPanel.jsx'

const farmRoles = ['owner', 'admin', 'manager', 'worker', 'viewer']
const communityRoles = ['admin', 'coordinator', 'resource_manager', 'member']

function memberLabel(member) {
  const profileName = [member.user?.name, member.user?.surname].filter(Boolean).join(' ')
  return profileName || member.name || member.user?.email || `Member ${member.user_id || member.id}`
}

export default function MembershipPage({ scope }) {
  const params = useParams()
  const id = params[`${scope}Id`]
  const { contexts } = useWorkspace()
  const context = contexts.find((item) => item.type === scope && String(item.id) === String(id))
  const canManage = Boolean(context?.permissions?.includes('manage_members'))
  const [identifier, setIdentifier] = useState('')
  const [role, setRole] = useState(scope === 'farm' ? 'viewer' : 'member')
  const [pending, setPending] = useState(null)
  const [error, setError] = useState('')
  const [toast, setToast] = useState('')
  const [invitationCode, setInvitationCode] = useState('')
  const basePath = `${scope === 'farm' ? 'farms' : 'communities'}/${id}`
  const roles = scope === 'farm' ? farmRoles : communityRoles
  const pageState = useAsyncData(
    async () => {
      const members = await api.listV1Path(`${basePath}/members`)
      if (scope !== 'community' || !canManage) {
        return { members, invitations: [], requests: [], managementError: '' }
      }
      const [invitationsResult, requestsResult] = await Promise.allSettled([
        api.listV1Path(`${basePath}/invitations`),
        api.listV1Path(`${basePath}/join-requests`),
      ])
      const failed = [invitationsResult, requestsResult].find(
        (result) => result.status === 'rejected',
      )
      return {
        members,
        invitations: invitationsResult.status === 'fulfilled' ? invitationsResult.value : [],
        requests: requestsResult.status === 'fulfilled' ? requestsResult.value : [],
        managementError: failed?.reason?.message || '',
      }
    },
    [basePath, canManage, scope],
    { members: [], invitations: [], requests: [], managementError: '' },
  )

  async function addMember(event) {
    event.preventDefault()
    if (pending) return
    setPending('invite')
    setError('')
    setInvitationCode('')
    try {
      if (scope === 'community') {
        const result = await api.createCommunityInvitation(id, { email: identifier, role })
        pageState.setData((current) => ({
          ...current,
          invitations: [result.invitation, ...current.invitations],
        }))
        setInvitationCode(result.code || '')
        setToast('Invitation created.')
      } else {
        const member = await api.postV1Path(`${basePath}/members`, { user_id: identifier, role })
        pageState.setData((current) => ({
          ...current,
          members: [member, ...current.members.filter((item) => item.id !== member.id)],
        }))
        setToast('Farm member added.')
      }
      setIdentifier('')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setPending(null)
    }
  }

  async function decide(request, action) {
    if (pending) return
    setPending(`request-${request.id}`)
    setError('')
    try {
      await api.postV1Path(`${basePath}/join-requests/${request.id}/${action}`)
      pageState.setData((current) => ({
        ...current,
        requests: current.requests.filter((item) => item.id !== request.id),
      }))
      setToast(`Join request ${action === 'approve' ? 'approved' : 'rejected'}.`)
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setPending(null)
    }
  }

  async function updateMember(member, changes) {
    if (pending) return
    setPending(`member-${member.id}`)
    setError('')
    try {
      const updated = await api.patchV1Path(`${basePath}/members/${member.id}`, changes)
      pageState.setData((current) => ({
        ...current,
        members: current.members.map((item) =>
          item.id === member.id ? { ...item, ...updated } : item,
        ),
      }))
      setToast(changes.status === 'revoked' ? 'Member access revoked.' : 'Member role updated.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setPending(null)
    }
  }

  if (pageState.loading) return <LoadingState title="Loading members…" />
  if (pageState.error) return <ErrorState error={pageState.error} onRetry={pageState.reload} />

  return (
    <div className="page-stack stage1-page">
      <PageHeader
        eyebrow={`${scope} access`}
        title={canManage ? 'Members and invitations' : 'Members'}
        description={
          canManage
            ? `Manage explicit roles for this ${scope}. Community roles never imply farm access.`
            : `View the privacy-safe member roster for this ${scope}.`
        }
      />
      <SuccessToast message={toast} onDismiss={() => setToast('')} />
      {error ? <ErrorState description={error} /> : null}
      {pageState.data.managementError ? (
        <ErrorState description={pageState.data.managementError} />
      ) : null}
      <section className="stage1-split">
        <div className="panel">
          <h2>Members</h2>
          <div className="stage1-list">
            {pageState.data.members.map((member) => (
              <article key={member.id}>
                <div>
                  <strong>{memberLabel(member)}</strong>
                  {member.user?.email ? <p>{member.user.email}</p> : null}
                </div>
                <div className="stage1-member-actions">
                  <Badge tone={member.status === 'revoked' ? 'neutral' : 'success'}>
                    {member.status || 'active'}
                  </Badge>
                  {canManage ? (
                    <>
                      <label>
                        <span className="sr-only">Role for {memberLabel(member)}</span>
                        <select
                          value={member.role}
                          disabled={Boolean(pending) || member.status === 'revoked'}
                          onChange={(event) => updateMember(member, { role: event.target.value })}
                        >
                          {roles.map((value) => (
                            <option value={value} key={value}>
                              {value.replaceAll('_', ' ')}
                            </option>
                          ))}
                        </select>
                      </label>
                      {member.status !== 'revoked' ? (
                        <Button
                          size="sm"
                          variant="danger"
                          onClick={() => updateMember(member, { status: 'revoked' })}
                          loading={pending === `member-${member.id}`}
                        >
                          Revoke
                        </Button>
                      ) : null}
                    </>
                  ) : null}
                </div>
              </article>
            ))}
            {pageState.data.members.length === 0 ? <p>No members found.</p> : null}
          </div>
        </div>
        {canManage ? (
          <form className="panel stage1-form" onSubmit={addMember}>
            <h2>{scope === 'community' ? 'Invite a member' : 'Add a farm member'}</h2>
            <label className="field">
              <span>{scope === 'community' ? 'Email address' : 'User ID'}</span>
              <input
                type={scope === 'community' ? 'email' : 'number'}
                min={scope === 'farm' ? '1' : undefined}
                required
                value={identifier}
                onChange={(event) => setIdentifier(event.target.value)}
              />
            </label>
            <label className="field">
              <span>Role</span>
              <select value={role} onChange={(event) => setRole(event.target.value)}>
                {roles.map((value) => (
                  <option value={value} key={value}>
                    {value.replaceAll('_', ' ')}
                  </option>
                ))}
              </select>
            </label>
            {invitationCode ? (
              <div className="stage1-invitation-code" role="status">
                <strong>Invitation code</strong>
                <code>{invitationCode}</code>
                <p>Share this code through a trusted channel. It is shown only once.</p>
              </div>
            ) : null}
            <Button type="submit" loading={pending === 'invite'}>
              {scope === 'community' ? 'Create invitation' : 'Add member'}
            </Button>
          </form>
        ) : null}
      </section>
      {scope === 'community' && canManage ? (
        <section className="panel">
          <h2>Join requests</h2>
          <div className="stage1-list">
            {pageState.data.requests.map((request) => (
              <article key={request.id}>
                <div>
                  <strong>{memberLabel(request)}</strong>
                  <p>{request.message}</p>
                </div>
                <div className="form-actions">
                  <Button
                    size="sm"
                    onClick={() => decide(request, 'approve')}
                    loading={pending === `request-${request.id}`}
                  >
                    Approve
                  </Button>
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => decide(request, 'reject')}
                    disabled={Boolean(pending)}
                  >
                    Reject
                  </Button>
                </div>
              </article>
            ))}
            {pageState.data.requests.length === 0 ? <p>No pending join requests.</p> : null}
          </div>
        </section>
      ) : null}
      <FarmCommunityLinksPanel scope={scope} context={context} />
    </div>
  )
}
