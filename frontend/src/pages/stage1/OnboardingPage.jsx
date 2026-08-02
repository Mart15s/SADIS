import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { useWorkspace } from '../../context/useWorkspace.js'
import { api } from '../../lib/api.js'

const steps = [
  { key: 'profile', label: 'Profile' },
  { key: 'mode', label: 'Mode' },
  { key: 'farm', label: 'Farm' },
  { key: 'community', label: 'Community' },
  { key: 'field', label: 'Field' },
  { key: 'season', label: 'Crop season' },
  { key: 'preferences', label: 'Preferences' },
]

const initialDraft = {
  first_name: '',
  last_name: '',
  phone: '',
  mode: 'independent',
  farm_action: 'create',
  farm_id: '',
  farm_name: '',
  farm_area_square_metres: '',
  state_code: '',
  district: '',
  locality: '',
  timezone: 'Asia/Kolkata',
  community_action: 'create',
  community_id: '',
  community_name: '',
  invitation_code: '',
  field_name: '',
  field_area_square_metres: '',
  soil_type: '',
  crop_name: '',
  crop_category: '',
  season_name: '',
  starts_on: new Date().toISOString().slice(0, 10),
  expected_ends_on: '',
  locale: 'en-IN',
  area_unit: 'hectare',
}

function Field({ label, children }) {
  return (
    <label className="field">
      <span>{label}</span>
      {children}
    </label>
  )
}

export default function OnboardingPage() {
  const navigate = useNavigate()
  const { contexts, reload: reloadContexts } = useWorkspace()
  const [step, setStep] = useState(0)
  const [completedSteps, setCompletedSteps] = useState([])
  const [form, setForm] = useState(initialDraft)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const farmContexts = useMemo(() => contexts.filter((item) => item.type === 'farm'), [contexts])
  const communityContexts = useMemo(
    () => contexts.filter((item) => item.type === 'community'),
    [contexts],
  )

  useEffect(() => {
    let active = true
    api
      .getOnboarding()
      .then((data) => {
        if (!active || !data) return
        const draft = data.draft || {}
        setForm((current) => ({
          ...current,
          ...draft,
          first_name: draft.first_name || draft.name || current.first_name,
        }))
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

  function update(name, value) {
    setForm((current) => ({ ...current, [name]: value }))
  }

  async function saveProgress({ finish = false, leave = false } = {}) {
    if (saving) return
    setSaving(true)
    setError('')
    const currentKey = steps[step].key
    const nextCompleted = leave
      ? completedSteps
      : completedSteps.includes(currentKey)
        ? completedSteps
        : [...completedSteps, currentKey]
    const nextStep = steps[Math.min(step + 1, steps.length - 1)].key

    try {
      const saved = await api.saveOnboarding({
        current_step: finish ? steps.at(-1).key : leave ? currentKey : nextStep,
        completed_steps: nextCompleted,
        draft: form,
        completed: finish,
      })
      setCompletedSteps(nextCompleted)
      if (finish) {
        const preferred = saved?.provisioned?.preferred_context
        await reloadContexts(preferred)
        navigate('/', { replace: true })
      } else if (leave) navigate('/', { replace: true })
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
        description="Choose how you work, create or select a farm, and leave with a usable first field and crop season."
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
            <Field label="First name">
              <input
                required
                value={form.first_name}
                onChange={(event) => update('first_name', event.target.value)}
              />
            </Field>
            <Field label="Last name">
              <input
                required
                value={form.last_name}
                onChange={(event) => update('last_name', event.target.value)}
              />
            </Field>
            <Field label="Phone (optional)">
              <input
                type="tel"
                value={form.phone}
                onChange={(event) => update('phone', event.target.value)}
                placeholder="+91…"
              />
            </Field>
          </>
        ) : null}
        {step === 1 ? (
          <fieldset className="stage1-choice-group">
            <legend>How will you use Yava?</legend>
            <label>
              <input
                type="radio"
                name="mode"
                value="independent"
                checked={form.mode === 'independent'}
                onChange={(event) => update('mode', event.target.value)}
              />{' '}
              Independent farm
            </label>
            <label>
              <input
                type="radio"
                name="mode"
                value="community"
                checked={form.mode === 'community'}
                onChange={(event) => update('mode', event.target.value)}
              />{' '}
              Farm with a Community
            </label>
          </fieldset>
        ) : null}
        {step === 2 ? (
          <>
            {farmContexts.length ? (
              <Field label="Farm setup">
                <select
                  value={form.farm_action}
                  onChange={(event) => update('farm_action', event.target.value)}
                >
                  <option value="create">Create a new Farm</option>
                  <option value="existing">Use an existing Farm</option>
                </select>
              </Field>
            ) : null}
            {form.farm_action === 'existing' && farmContexts.length ? (
              <Field label="Existing Farm">
                <select
                  required
                  value={form.farm_id}
                  onChange={(event) => update('farm_id', event.target.value)}
                >
                  <option value="">Select a Farm</option>
                  {farmContexts.map((context) => (
                    <option value={context.id} key={context.id}>
                      {context.name}
                    </option>
                  ))}
                </select>
              </Field>
            ) : (
              <>
                <Field label="Farm name">
                  <input
                    required
                    value={form.farm_name}
                    onChange={(event) => update('farm_name', event.target.value)}
                  />
                </Field>
                <Field label="Farm area (m²)">
                  <input
                    type="number"
                    min="0"
                    step="any"
                    required
                    value={form.farm_area_square_metres}
                    onChange={(event) => update('farm_area_square_metres', event.target.value)}
                  />
                </Field>
                <Field label="State or Union Territory code">
                  <input
                    value={form.state_code}
                    onChange={(event) => update('state_code', event.target.value)}
                  />
                </Field>
                <Field label="District">
                  <input
                    value={form.district}
                    onChange={(event) => update('district', event.target.value)}
                  />
                </Field>
                <Field label="Village or city">
                  <input
                    value={form.locality}
                    onChange={(event) => update('locality', event.target.value)}
                  />
                </Field>
              </>
            )}
          </>
        ) : null}
        {step === 3 ? (
          form.mode === 'independent' ? (
            <div className="inline-note">
              Independent mode selected. You can join or create Communities later from the
              Communities page.
            </div>
          ) : (
            <>
              <Field label="Community setup">
                <select
                  value={form.community_action}
                  onChange={(event) => update('community_action', event.target.value)}
                >
                  <option value="create">Create a Community</option>
                  {communityContexts.length ? (
                    <option value="existing">Use an existing membership</option>
                  ) : null}
                  <option value="invitation">Join with an invitation code</option>
                </select>
              </Field>
              {form.community_action === 'create' ? (
                <Field label="Community name">
                  <input
                    required
                    value={form.community_name}
                    onChange={(event) => update('community_name', event.target.value)}
                  />
                </Field>
              ) : null}
              {form.community_action === 'existing' ? (
                <Field label="Existing Community">
                  <select
                    required
                    value={form.community_id}
                    onChange={(event) => update('community_id', event.target.value)}
                  >
                    <option value="">Select a Community</option>
                    {communityContexts.map((context) => (
                      <option value={context.id} key={context.id}>
                        {context.name}
                      </option>
                    ))}
                  </select>
                </Field>
              ) : null}
              {form.community_action === 'invitation' ? (
                <Field label="Invitation code">
                  <input
                    required
                    autoComplete="one-time-code"
                    value={form.invitation_code}
                    onChange={(event) =>
                      update('invitation_code', event.target.value.toUpperCase())
                    }
                  />
                </Field>
              ) : null}
            </>
          )
        ) : null}
        {step === 4 ? (
          <>
            <Field label="First Field name">
              <input
                required
                value={form.field_name}
                onChange={(event) => update('field_name', event.target.value)}
              />
            </Field>
            <Field label="Field area (m²)">
              <input
                type="number"
                min="0"
                step="any"
                required
                value={form.field_area_square_metres}
                onChange={(event) => update('field_area_square_metres', event.target.value)}
              />
            </Field>
            <Field label="Soil type (optional)">
              <input
                value={form.soil_type}
                onChange={(event) => update('soil_type', event.target.value)}
              />
            </Field>
          </>
        ) : null}
        {step === 5 ? (
          <>
            <Field label="Crop name">
              <input
                required
                value={form.crop_name}
                onChange={(event) => update('crop_name', event.target.value)}
              />
            </Field>
            <Field label="Crop category (optional)">
              <input
                value={form.crop_category}
                onChange={(event) => update('crop_category', event.target.value)}
              />
            </Field>
            <Field label="Season name (optional)">
              <input
                value={form.season_name}
                onChange={(event) => update('season_name', event.target.value)}
              />
            </Field>
            <Field label="Starts on">
              <input
                type="date"
                required
                value={form.starts_on}
                onChange={(event) => update('starts_on', event.target.value)}
              />
            </Field>
            <Field label="Expected end date">
              <input
                type="date"
                min={form.starts_on}
                value={form.expected_ends_on}
                onChange={(event) => update('expected_ends_on', event.target.value)}
              />
            </Field>
          </>
        ) : null}
        {step === 6 ? (
          <>
            <Field label="Timezone">
              <input
                required
                value={form.timezone}
                onChange={(event) => update('timezone', event.target.value)}
              />
            </Field>
            <Field label="Area display">
              <select
                value={form.area_unit}
                onChange={(event) => update('area_unit', event.target.value)}
              >
                <option value="hectare">Hectares</option>
                <option value="acre">Acres</option>
                <option value="square_meter">Square metres</option>
              </select>
            </Field>
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
