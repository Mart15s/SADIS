import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'

export default function CommunityAccessPanel({ onChanged }) {
  const [searchParams] = useSearchParams()
  const [code, setCode] = useState(() => searchParams.get('invitation') || '')
  const [communityId, setCommunityId] = useState('')
  const [message, setMessage] = useState('')
  const [pending, setPending] = useState('')
  const [feedback, setFeedback] = useState({ type: '', message: '' })

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
            <span>Community ID</span>
            <input
              type="number"
              min="1"
              required
              value={communityId}
              onChange={(event) => setCommunityId(event.target.value)}
            />
          </label>
          <label className="field">
            <span>Message (optional)</span>
            <input value={message} onChange={(event) => setMessage(event.target.value)} />
          </label>
          <Button
            type="submit"
            variant="secondary"
            loading={pending === 'request'}
            disabled={Boolean(pending) && pending !== 'request'}
          >
            Request to join
          </Button>
        </form>
      </div>
    </section>
  )
}
