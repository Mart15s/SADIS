import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'

const initialForm = {
  email: '',
  reset_code: '',
  password: '',
  password_confirmation: '',
}

export default function ResetPasswordPage() {
  const [searchParams] = useSearchParams()
  const [form, setForm] = useState(() => ({
    ...initialForm,
    email: searchParams.get('email') ?? '',
    reset_code: searchParams.get('token') ?? searchParams.get('reset_code') ?? '',
  }))
  const [message, setMessage] = useState('')
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
    setMessage('')

    try {
      const response = await api.resetPassword(form)
      setMessage(response.message)
      setForm(initialForm)
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="page-stack auth-card">
      <PageHeader
        title="Atkurti slaptažodį"
        description="Naudokite el. paštu gautą slaptažodžio atkūrimo nuorodą ir pasirinkite naują slaptažodį."
      />

      <form className="panel split-form" onSubmit={handleSubmit}>
        <div className="field">
          <label htmlFor="reset-email">El. paštas</label>
          <input id="reset-email" name="email" type="email" value={form.email} onChange={handleChange} required />
        </div>
        <div className="field">
          <label htmlFor="reset_code">Atkūrimo kodas</label>
          <input id="reset_code" name="reset_code" value={form.reset_code} onChange={handleChange} required />
        </div>
        <div className="field">
          <label htmlFor="new-password">Naujas slaptažodis</label>
          <input
            id="new-password"
            name="password"
            type="password"
            value={form.password}
            onChange={handleChange}
            required
          />
        </div>
        <div className="field">
          <label htmlFor="new-password-confirmation">Pakartokite naują slaptažodį</label>
          <input
            id="new-password-confirmation"
            name="password_confirmation"
            type="password"
            value={form.password_confirmation}
            onChange={handleChange}
            required
          />
        </div>

        {message ? <span className="badge badge-success">{message}</span> : null}
        {error ? <span className="field-error">{error}</span> : null}

        <div className="form-actions">
          <Button type="submit" disabled={submitting}>
            {submitting ? 'Atnaujinama...' : 'Atnaujinti slaptažodį'}
          </Button>
          <Link to="/login">
            <Button variant="secondary">Grįžti į prisijungimą</Button>
          </Link>
        </div>
      </form>
    </div>
  )
}
