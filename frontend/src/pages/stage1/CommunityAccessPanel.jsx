import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

function communityOptionLabel(community) {
  const location = [community.locality, community.state_code].filter(Boolean).join(', ')
  const requestStatus = community.join_request_status
    ? ` · request ${community.join_request_status}`
    : ''

  return `${community.name}${location ? ` — ${location}` : ''}${requestStatus}`
}

export default function CommunityAccessPanel({ onChanged }) {
  const [searchParams] = useSearchParams()
  const [code, setCode] = useState(() => searchParams.get('invitation') || '')
  const [communityId, setCommunityId] = useState('')
  const [message, setMessage] = useState('')
  const [pending, setPending] = useState('')
  const [feedback, setFeedback] = useState({ type: '', message: '' })
  const discovery = useAsyncData(() => api.listV1Path('communities/discover'), [], [])

  async function acceptInvitation(event) {
    event.preventDefault()
    if (pending) return
    setPending('invitation')
    setFeedback({ type: '', message: '' })
    try {
      await api.postV1Path(`invitations/${encodeURIComponent(code.trim())}/accept`)
      setCode('')
      setFeedback({
        type: 'success',
        message: 'Invitation accepted. The community is now available in your workspace switcher.',
      })
      await onChanged?.()
    } catch (error) {
      setFeedback({ type: 'error', message: error.message })
    } finally {
      setPending('')
    }
  }

  async function requestAccess(event) {
    event.preventDefault()
    if (pending) return
    setPending('request')
    setFeedback({ type: '', message: '' })
    try {
      await api.postV1Path(`communities/${communityId}/join-requests`, { message })
      discovery.setData((communities) =>
        communities.map((community) =>
          String(community.id) === String(communityId)
            ? { ...community, join_request_status: 'pending' }
            : community,
        ),
      )
      setCommunityId('')
      setMessage('')
      setFeedback({
        type: 'success',
        message: 'Join request submitted. A Community Admin can now review it.',
      })
    } catch (error) {
      setFeedback({ type: 'error', message: error.message })
    } finally {
      setPending('')
    }
  }

  return (
    <section className="panel community-access-panel" aria-labelledby="community-access-title">
      <div>
        <span className="eyebrow">Community access</span>
        <h2 id="community-access-title">Join an existing community</h2>
      </div>
      {feedback.message ? (
        <p
          className={feedback.type === 'error' ? 'field-error' : 'form-success'}
          role={feedback.type === 'error' ? 'alert' : 'status'}
        >
          {feedback.message}
        </p>
      ) : null}
      <div className="community-access-grid">
        <form className="stage1-form" onSubmit={acceptInvitation}>
          <label className="field">
            <span>Invitation code</span>
            <input
              value={code}
              required
              autoComplete="one-time-code"
              onChange={(event) => setCode(event.target.value.toUpperCase())}
            />
          </label>
          <Button
            type="submit"
            loading={pending === 'invitation'}
            disabled={Boolean(pending) && pending !== 'invitation'}
          >
            Accept invitation
          </Button>
        </form>
        <form className="stage1-form" onSubmit={requestAccess}>
          <label className="field">
            <span>Community</span>
            <select
              required
              value={communityId}
              disabled={discovery.loading || Boolean(discovery.error)}
              onChange={(event) => setCommunityId(event.target.value)}
            >
              <option value="">
                {discovery.loading ? 'Loading communities…' : 'Select a community'}
              </option>
              {discovery.data.map((community) => (
                <option
                  value={community.id}
                  key={community.id}
                  disabled={community.join_request_status === 'pending'}
                >
                  {communityOptionLabel(community)}
                </option>
              ))}
            </select>
          </label>
          {discovery.error ? (
            <div>
              <p className="field-error" role="alert">
                {discovery.error.message}
              </p>
              <Button type="button" size="sm" variant="secondary" onClick={discovery.reload}>
                Retry communities
              </Button>
            </div>
          ) : null}
          {!discovery.loading && !discovery.error && discovery.data.length === 0 ? (
            <p>No communities are currently available to join.</p>
          ) : null}
          <label className="field">
            <span>Message (optional)</span>
            <input value={message} onChange={(event) => setMessage(event.target.value)} />
          </label>
          <Button
            type="submit"
            variant="secondary"
            loading={pending === 'request'}
            disabled={
              !communityId ||
              discovery.loading ||
              Boolean(discovery.error) ||
              (Boolean(pending) && pending !== 'request')
            }
          >
            Request to join
          </Button>
        </form>
      </div>
    </section>
  )
}
