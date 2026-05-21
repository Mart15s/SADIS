import { useEffect, useState } from 'react'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { useAuth } from '../../context/AuthContext.jsx'
import Button from '../../components/ui/Button.jsx'
import FormSection from '../../components/ui/FormSection.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'

export default function AccountPage() {
  const { profile, updateAccount, user } = useAuth()
  const [form, setForm] = useState({
    email: user?.email ?? '',
    name: profile?.name ?? '',
    surname: profile?.surname ?? '',
  })
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    setForm({
      email: user?.email ?? '',
      name: profile?.name ?? '',
      surname: profile?.surname ?? '',
    })
  }, [profile?.name, profile?.surname, user?.email])

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
    setSuccess('')

    try {
      await updateAccount(form)
      setSuccess('Paskyros duomenys atnaujinti.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="page-stack">
      <PageHeader
        eyebrow="Profilis"
        title="Paskyra"
        description="Peržiūrėkite ir atnaujinkite su jūsų paskyra susietus profilio duomenis."
        meta={<StatusBadge kind="connection">{user?.email}</StatusBadge>}
      />

      <div className="form-shell">
        <form onSubmit={handleSubmit}>
          <FormSection
            title="Redaguoti paskyros duomenis"
            description="Pagrindinius tapatybės duomenis galite patogiai peržiūrėti ir atnaujinti."
          >
            <div className="input-grid">
              <div className="field">
                <label htmlFor="account-email">El. paštas</label>
                <input id="account-email" name="email" type="email" value={form.email} onChange={handleChange} required />
              </div>
              <div className="field">
                <label htmlFor="account-name">Vardas</label>
                <input id="account-name" name="name" value={form.name} onChange={handleChange} required />
              </div>
              <div className="field">
                <label htmlFor="account-surname">Pavardė</label>
                <input id="account-surname" name="surname" value={form.surname} onChange={handleChange} required />
              </div>
            </div>

            {error ? <span className="field-error">{error}</span> : null}
            {success ? <span className="form-success">{success}</span> : null}

            <div className="action-row">
              <Button type="submit" disabled={submitting}>
                {submitting ? 'Saugoma...' : 'Išsaugoti paskyros duomenis'}
              </Button>
            </div>
          </FormSection>
        </form>
      </div>
    </div>
  )
}
