import { useState } from 'react'
import { Link } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    setMessage('')

    try {
      const response = await api.forgotPassword({ email })
      setMessage(response.message)
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="page-stack auth-card">
      <PageHeader
        title="Forgot password"
        description="This form uses the backend email reset endpoint. Enter the account email and the backend will send a reset code."
      />

      <form className="panel input-grid" onSubmit={handleSubmit}>
        <div className="field">
          <label htmlFor="forgot-email">Email</label>
          <input
            id="forgot-email"
            name="email"
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            required
          />
        </div>

        {message ? <span className="badge badge-success">{message}</span> : null}
        {error ? <span className="field-error">{error}</span> : null}

        <div className="form-actions">
          <Button type="submit" disabled={submitting}>
            {submitting ? 'Sending...' : 'Send reset code'}
          </Button>
          <Link to="/reset-password">
            <Button variant="secondary">Enter reset code</Button>
          </Link>
        </div>
      </form>
    </div>
  )
}
