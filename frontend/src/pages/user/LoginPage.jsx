import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import Button from '../../components/ui/Button.jsx'
import { useAuth } from '../../context/AuthContext.jsx'

export default function LoginPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const { login } = useAuth()
  const [form, setForm] = useState({
    email: '',
    password: '',
  })
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
      await login(form)
      navigate(searchParams.get('redirect') || '/')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="page-stack auth-card">
      <PageHeader
        title="Sign in"
        description="Authentication uses the Laravel Sanctum token returned by the backend login endpoint and persists it for the SPA session."
      />

      <form className="panel input-grid" onSubmit={handleSubmit}>
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

        {error ? <span className="field-error">{error}</span> : null}

        <div className="form-actions">
          <Button type="submit" disabled={submitting}>
            {submitting ? 'Signing in...' : 'Sign in'}
          </Button>
          <Link to="/forgot-password">
            <Button variant="secondary">Forgot password</Button>
          </Link>
          <Link to="/register">
            <Button variant="ghost">Create account</Button>
          </Link>
        </div>
      </form>
    </div>
  )
}
