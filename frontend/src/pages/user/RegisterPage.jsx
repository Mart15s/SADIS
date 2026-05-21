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
        title="Sukurti paskyrą"
        description="Registracija sukuria naudotoją, profilį, daržo savininko įrašą ir API prieigos raktą vienoje užklausoje."
      />

      <form className="panel split-form" onSubmit={handleSubmit}>
        <div className="field">
          <label htmlFor="name">Vardas</label>
          <input id="name" name="name" value={form.name} onChange={handleChange} required />
        </div>
        <div className="field">
          <label htmlFor="surname">Pavardė</label>
          <input id="surname" name="surname" value={form.surname} onChange={handleChange} required />
        </div>
        <div className="field">
          <label htmlFor="email">El. paštas</label>
          <input id="email" name="email" type="email" value={form.email} onChange={handleChange} required />
        </div>
        <div className="field">
          <label htmlFor="password">Slaptažodis</label>
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
          <label htmlFor="password_confirmation">Pakartokite slaptažodį</label>
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
          <label>Pasiruošę tęsti?</label>
          <div className="inline-note">
            Nauja paskyra atidaroma iš karto, neperkraunant viso puslapio.
          </div>
        </div>

        {error ? <span className="field-error">{error}</span> : null}

        <div className="form-actions">
          <Button type="submit" disabled={submitting}>
            {submitting ? 'Kuriama paskyra...' : 'Sukurti paskyrą'}
          </Button>
          <Link to="/login">
            <Button variant="secondary">Grįžti į prisijungimą</Button>
          </Link>
        </div>
      </form>
    </div>
  )
}
