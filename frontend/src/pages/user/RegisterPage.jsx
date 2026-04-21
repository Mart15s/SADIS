import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import Button from '../../components/ui/Button.jsx'
import { useAuth } from '../../context/AuthContext.jsx'

const initialForm = {
  name: '',
  surname: '',
  email: '',
  password: '',
  password_confirmation: '',
}

export default function RegisterPage() {
  const navigate = useNavigate()
  const { register } = useAuth()
  const [form, setForm] = useState(initialForm)
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  function handleChange(event) {
    setForm((current) => ({
      ...current,
      [event.target.name]: event.target.value,
    }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')

    try {
      await register(form)
      navigate('/')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="page-stack auth-card">
      <PageHeader
        title="Create account"
        description="Registration mirrors the backend sign-up flow and creates the user, profile, garden owner, and API token in one request."
      />

      <form className="panel split-form" onSubmit={handleSubmit}>
        <div className="field">
          <label htmlFor="name">Name</label>
          <input id="name" name="name" value={form.name} onChange={handleChange} required />
        </div>
        <div className="field">
          <label htmlFor="surname">Surname</label>
          <input id="surname" name="surname" value={form.surname} onChange={handleChange} required />
        </div>
        <div className="field">
          <label htmlFor="email">Email</label>
          <input id="email" name="email" type="email" value={form.email} onChange={handleChange} required />
        </div>
        <div className="field">
          <label htmlFor="password">Password</label>
          <input
            id="password"
            name="password"
            type="password"
            value={form.password}
            onChange={handleChange}
            required
          />
        </div>
        <div className="field">
          <label htmlFor="password_confirmation">Confirm password</label>
          <input
            id="password_confirmation"
            name="password_confirmation"
            type="password"
            value={form.password_confirmation}
            onChange={handleChange}
            required
          />
        </div>

        <div className="field">
          <label>Ready to continue?</label>
          <div className="inline-note">
            Your new account lands straight into the SPA without a full page reload.
          </div>
        </div>

        {error ? <span className="field-error">{error}</span> : null}

        <div className="form-actions">
          <Button type="submit" disabled={submitting}>
            {submitting ? 'Creating account...' : 'Create account'}
          </Button>
          <Link to="/login">
            <Button variant="secondary">Back to sign in</Button>
          </Link>
        </div>
      </form>
    </div>
  )
}
