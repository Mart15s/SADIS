import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { ErrorState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { useAuth } from '../../context/auth-context.js'
import { api } from '../../lib/api.js'
import { safeRedirectPath } from '../../lib/navigation.js'

export default function OtpVerificationPage({ purpose = 'login' }) {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const { verifyOtpLogin } = useAuth()
  const [phone, setPhone] = useState('')
  const [code, setCode] = useState('')
  const [challenge, setChallenge] = useState(null)
  const [pending, setPending] = useState(false)
  const [error, setError] = useState('')
  const isLogin = purpose === 'login'

  async function submit(event) {
    event.preventDefault()
    if (pending) return
    setPending(true)
    setError('')

    try {
      if (!challenge) {
        setChallenge(await api.requestOtp({ phone, purpose }))
      } else if (isLogin) {
        await verifyOtpLogin({ challenge_id: challenge.challenge_id, code })
        navigate(safeRedirectPath(searchParams.get('redirect')), { replace: true })
      } else {
        await api.verifyOtp({ challenge_id: challenge.challenge_id, code, purpose })
        navigate('/account', { replace: true })
      }
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setPending(false)
    }
  }

  function changePhone() {
    setChallenge(null)
    setCode('')
    setError('')
  }

  return (
    <section className="auth-card">
      <p className="eyebrow">Secure verification</p>
      <h1>{isLogin ? 'Sign in with a one-time code' : 'Verify your phone'}</h1>
      <p>
        {isLogin
          ? 'Use the phone number linked to your active Yava account.'
          : 'Confirm your phone number with a six-digit code.'}
      </p>
      {error ? <ErrorState description={error} /> : null}
      {challenge?.debug_code ? (
        <p className="inline-note" role="status">
          Development code: <strong>{challenge.debug_code}</strong>
        </p>
      ) : null}
      <form onSubmit={submit} className="stage1-form">
        <label className="field">
          <span>Phone number</span>
          <input
            type="tel"
            autoComplete="tel"
            required
            disabled={Boolean(challenge)}
            value={phone}
            onChange={(event) => setPhone(event.target.value)}
            placeholder="+91…"
          />
        </label>
        {challenge ? (
          <label className="field">
            <span>One-time code</span>
            <input
              inputMode="numeric"
              autoComplete="one-time-code"
              pattern="[0-9]{6}"
              maxLength="6"
              required
              value={code}
              onChange={(event) => setCode(event.target.value.replace(/\D/g, ''))}
            />
          </label>
        ) : null}
        <div className="form-actions">
          <Button type="submit" loading={pending}>
            {challenge ? 'Verify code' : 'Send code'}
          </Button>
          {challenge ? (
            <Button variant="ghost" onClick={changePhone} disabled={pending}>
              Change phone
            </Button>
          ) : null}
        </div>
      </form>
    </section>
  )
}
