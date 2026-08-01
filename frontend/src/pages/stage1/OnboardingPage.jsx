import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'

const steps = ['Profile', 'Farm', 'Preferences']

export default function OnboardingPage() {
  const navigate = useNavigate()
  const [step, setStep] = useState(0)
  const [form, setForm] = useState({ name: '', phone: '', farm_name: '', state_code: '', district: '', village_or_city: '', postal_code: '', timezone: 'Asia/Kolkata', locale: 'en-IN', area_unit: 'hectare' })
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    api.getOnboarding().then((data) => {
      if (!active || !data) return
      setForm((current) => ({ ...current, ...(data.values || data) }))
      setStep(Math.min(Number(data.current_step || 0), steps.length - 1))
    }).catch((requestError) => active && requestError.status !== 404 && setError(requestError.message)).finally(() => active && setLoading(false))
    return () => { active = false }
  }, [])

  async function continueOnboarding(event) {
    event.preventDefault()
    if (saving) return
    setSaving(true); setError('')
    try {
      await api.saveOnboarding({ current_step: step + 1, values: form, completed: step === steps.length - 1 })
      if (step === steps.length - 1) navigate('/farms', { replace: true })
      else setStep((value) => value + 1)
    } catch (requestError) { setError(requestError.message) }
    finally { setSaving(false) }
  }

  if (loading) return <LoadingState title="Restoring onboarding progress…" />
  return <div className="page-stack onboarding-page">
    <PageHeader eyebrow={`Step ${step + 1} of ${steps.length}`} title="Set up your Yava workspace" description="Your progress is saved after every step." />
    <ol className="onboarding-steps">{steps.map((name, index) => <li className={index <= step ? 'is-active' : ''} key={name}>{name}</li>)}</ol>
    {error ? <ErrorState description={error} /> : null}
    <form className="panel stage1-form" onSubmit={continueOnboarding}>
      {step === 0 ? <><label className="field"><span>Your name</span><input required value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} /></label><label className="field"><span>Phone (optional)</span><input type="tel" value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} placeholder="+91…" /></label></> : null}
      {step === 1 ? <><label className="field"><span>Farm name</span><input required value={form.farm_name} onChange={(event) => setForm({ ...form, farm_name: event.target.value })} /></label><label className="field"><span>State or Union Territory code</span><input required value={form.state_code} onChange={(event) => setForm({ ...form, state_code: event.target.value })} /></label><label className="field"><span>District</span><input value={form.district} onChange={(event) => setForm({ ...form, district: event.target.value })} /></label><label className="field"><span>Village or city</span><input value={form.village_or_city} onChange={(event) => setForm({ ...form, village_or_city: event.target.value })} /></label></> : null}
      {step === 2 ? <><label className="field"><span>Timezone</span><input value={form.timezone} onChange={(event) => setForm({ ...form, timezone: event.target.value })} /></label><label className="field"><span>Area display</span><select value={form.area_unit} onChange={(event) => setForm({ ...form, area_unit: event.target.value })}><option value="hectare">Hectares</option><option value="acre">Acres</option><option value="square_meter">Square metres</option></select></label></> : null}
      <div className="form-actions"><Button type="submit" loading={saving}>{step === steps.length - 1 ? 'Finish setup' : 'Save and continue'}</Button>{step > 0 ? <Button variant="secondary" onClick={() => setStep((value) => value - 1)} disabled={saving}>Back</Button> : null}</div>
    </form>
  </div>
}
