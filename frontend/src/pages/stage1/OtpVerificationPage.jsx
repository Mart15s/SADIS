import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ErrorState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import { api } from '../../lib/api.js'

export default function OtpVerificationPage() {
  const navigate = useNavigate()
  const [phone, setPhone] = useState('')
  const [code, setCode] = useState('')
  const [requested, setRequested] = useState(false)
  const [pending, setPending] = useState(false)
  const [error, setError] = useState('')
  async function submit(event) {
    event.preventDefault(); if (pending) return; setPending(true); setError('')
    try {
      if (!requested) { await api.requestOtp({ phone }); setRequested(true) }
      else { await api.verifyOtp({ phone, code }); navigate('/onboarding', { replace: true }) }
    } catch (requestError) { setError(requestError.message) }
    finally { setPending(false) }
  }
  return <section className="auth-card"><p className="eyebrow">Secure verification</p><h1>Verify your phone</h1><p>Email and password login remains available. Phone verification adds a second trusted sign-in method.</p>{error ? <ErrorState description={error} /> : null}<form onSubmit={submit} className="stage1-form"><label className="field"><span>Phone number</span><input type="tel" autoComplete="tel" required disabled={requested} value={phone} onChange={(event) => setPhone(event.target.value)} placeholder="+91…" /></label>{requested ? <label className="field"><span>One-time code</span><input inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength="6" required value={code} onChange={(event) => setCode(event.target.value.replace(/\D/g, ''))} /></label> : null}<Button type="submit" loading={pending}>{requested ? 'Verify code' : 'Send code'}</Button>{requested ? <Button variant="ghost" onClick={() => setRequested(false)} disabled={pending}>Change phone</Button> : null}</form></section>
}
