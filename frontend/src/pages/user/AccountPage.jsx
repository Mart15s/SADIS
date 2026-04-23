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
      setSuccess('Account data updated successfully.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="page-stack">
      <PageHeader
        eyebrow="Profile"
        title="Account"
        description="Review and update the profile data tied to your authenticated account."
        meta={<StatusBadge kind="connection">{user?.email}</StatusBadge>}
      />

      <div className="form-shell">
        <form onSubmit={handleSubmit}>
          <FormSection
            title="Edit account data"
            description="Keep your core identity details readable and easy to update without stretching the form across the full page."
          >
            <div className="input-grid">
              <div className="field">
                <label htmlFor="account-email">Email</label>
                <input id="account-email" name="email" type="email" value={form.email} onChange={handleChange} required />
              </div>
              <div className="field">
                <label htmlFor="account-name">Name</label>
                <input id="account-name" name="name" value={form.name} onChange={handleChange} required />
              </div>
              <div className="field">
                <label htmlFor="account-surname">Surname</label>
                <input id="account-surname" name="surname" value={form.surname} onChange={handleChange} required />
              </div>
            </div>

            {error ? <span className="field-error">{error}</span> : null}
            {success ? <span className="form-success">{success}</span> : null}

            <div className="action-row">
              <Button type="submit" disabled={submitting}>
                {submitting ? 'Saving...' : 'Save account data'}
              </Button>
            </div>
          </FormSection>
        </form>
      </div>
    </div>
  )
}
