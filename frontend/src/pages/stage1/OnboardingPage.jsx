import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'

const steps = [
  { key: 'profile', label: 'Profile' },
  { key: 'farm', label: 'Farm' },
  { key: 'preferences', label: 'Preferences' },
]

const initialDraft = {
  name: '',
  phone: '',
  farm_name: '',
  state_code: '',
  district: '',
  locality: '',
  postal_code: '',
  timezone: 'Asia/Kolkata',
  locale: 'en-IN',
  area_unit: 'hectare',
}

export default function OnboardingPage() {
  const navigate = useNavigate()
  const [step, setStep] = useState(0)
  const [completedSteps, setCompletedSteps] = useState([])
  const [form, setForm] = useState(initialDraft)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    api
      .getOnboarding()
      .then((data) => {
        if (!active || !data) return
        setForm((current) => ({ ...current, ...(data.draft || {}) }))
        setCompletedSteps(Array.isArray(data.completed_steps) ? data.completed_steps : [])
        const restoredStep = steps.findIndex((item) => item.key === data.current_step)
        setStep(restoredStep >= 0 ? restoredStep : 0)
      })
      .catch((requestError) => {
        if (active && requestError.status !== 404) setError(requestError.message)
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => {
      active = false
    }
  }, [])

  async function saveProgress({ finish = false, leave = false } = {}) {
    if (saving) return
    setSaving(true)
    setError('')
    const currentKey = steps[step].key
    const nextCompleted = completedSteps.includes(currentKey)
      ? completedSteps
      : [...completedSteps, currentKey]
    const nextStep = steps[Math.min(step + 1, steps.length - 1)].key

    try {
      await api.saveOnboarding({
        current_step: finish ? steps.at(-1).key : nextStep,
        completed_steps: nextCompleted,
        draft: form,
        completed: finish,
      })
      setCompletedSteps(nextCompleted)
      if (finish) navigate('/farms', { replace: true })
      else if (leave) navigate('/', { replace: true })
      else setStep((value) => Math.min(value + 1, steps.length - 1))
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSaving(false)
    }
  }

  async function continueOnboarding(event) {
    event.preventDefault()
    await saveProgress({ finish: step === steps.length - 1 })
  }

  if (loading) return <LoadingState title="Restoring onboarding progress…" />
  return (
    <div className="page-stack onboarding-page">
      <PageHeader
        eyebrow={`Step ${step + 1} of ${steps.length}`}
        title="Set up your Yava workspace"
        description="Your progress is saved after every step, and you can resume later."
      />
      <ol className="onboarding-steps" aria-label="Onboarding progress">
        {steps.map((item, index) => (
          <li
            className={index <= step ? 'is-active' : ''}
            aria-current={index === step ? 'step' : undefined}
            key={item.key}
          >
            {item.label}
          </li>
        ))}
      </ol>
      {error ? <ErrorState description={error} /> : null}
      <form className="panel stage1-form" onSubmit={continueOnboarding}>
        {step === 0 ? (
          <>
            <label className="field">
              <span>Your name</span>
              <input
                required
                value={form.name}
                onChange={(event) =>
                  setForm((current) => ({ ...current, name: event.target.value }))
                }
              />
            </label>
            <label className="field">
              <span>Phone (optional)</span>
              <input
                type="tel"
                value={form.phone}
                onChange={(event) =>
                  setForm((current) => ({ ...current, phone: event.target.value }))
                }
                placeholder="+91…"
              />
            </label>
          </>
        ) : null}
        {step === 1 ? (
          <>
            <label className="field">
              <span>Farm name</span>
              <input
                required
                value={form.farm_name}
                onChange={(event) =>
                  setForm((current) => ({ ...current, farm_name: event.target.value }))
                }
              />
            </label>
            <label className="field">
              <span>State or Union Territory code</span>
              <input
                required
                value={form.state_code}
                onChange={(event) =>
                  setForm((current) => ({ ...current, state_code: event.target.value }))
                }
              />
            </label>
            <label className="field">
              <span>District</span>
              <input
                value={form.district}
                onChange={(event) =>
                  setForm((current) => ({ ...current, district: event.target.value }))
                }
              />
            </label>
            <label className="field">
              <span>Village or city</span>
              <input
                value={form.locality}
                onChange={(event) =>
                  setForm((current) => ({ ...current, locality: event.target.value }))
                }
              />
            </label>
          </>
        ) : null}
        {step === 2 ? (
          <>
            <label className="field">
              <span>Timezone</span>
              <input
                value={form.timezone}
                onChange={(event) =>
                  setForm((current) => ({ ...current, timezone: event.target.value }))
                }
              />
            </label>
            <label className="field">
              <span>Area display</span>
              <select
                value={form.area_unit}
                onChange={(event) =>
                  setForm((current) => ({ ...current, area_unit: event.target.value }))
                }
              >
                <option value="hectare">Hectares</option>
                <option value="acre">Acres</option>
                <option value="square_meter">Square metres</option>
              </select>
            </label>
          </>
        ) : null}
        <div className="form-actions">
          <Button type="submit" loading={saving}>
            {step === steps.length - 1 ? 'Finish setup' : 'Save and continue'}
          </Button>
          {step > 0 ? (
            <Button
              variant="secondary"
              onClick={() => setStep((value) => value - 1)}
              disabled={saving}
            >
              Back
            </Button>
          ) : null}
          <Button variant="ghost" onClick={() => saveProgress({ leave: true })} disabled={saving}>
            Save and finish later
          </Button>
        </div>
      </form>
    </div>
  )
}
