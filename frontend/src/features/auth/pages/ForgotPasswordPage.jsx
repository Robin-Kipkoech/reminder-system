import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { CheckCircle2 } from 'lucide-react'
import Button from '../../../components/Button'
import Input from '../../../components/Input'
import { api, errorMessage } from '../../../lib/api'
import techronLogo from '../../../assets/techron-logo.png'

const RESEND_SECONDS = 60

async function post(path, body) {
  const base = import.meta.env.VITE_API_BASE_URL || ''
  await api.get(`${base}/sanctum/csrf-cookie`)
  const response = await api.post(path, body)
  return response.data
}

const looksLikeEmail = (value) => /^\S+@\S+\.\S+$/.test(value)

export default function ForgotPasswordPage() {
  const [step, setStep] = useState('email')
  const [email, setEmail] = useState('')
  const [code, setCode] = useState('')
  const [token, setToken] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [cooldown, setCooldown] = useState(0)

  useEffect(() => {
    document.title = 'Reset password · Reminder System'
  }, [])

  useEffect(() => {
    if (cooldown <= 0) return undefined
    const timer = setTimeout(() => setCooldown((seconds) => seconds - 1), 1000)
    return () => clearTimeout(timer)
  }, [cooldown])

  function fail(requestError, fallback) {
    if (requestError.response?.status === 429) {
      setError(requestError.response?.data?.message || 'Too many attempts. Please wait a minute and try again.')
    } else {
      setError(errorMessage(requestError, fallback))
    }
  }

  async function sendCode(event) {
    event.preventDefault()
    setError('')
    const cleaned = email.trim().toLowerCase()
    if (!looksLikeEmail(cleaned)) {
      setError('Enter a valid email address.')
      return
    }

    setBusy(true)
    try {
      await post('/api/forgot-password', { email: cleaned })
      setEmail(cleaned)
      setCode('')
      setNotice(`If an account exists for ${cleaned}, we’ve sent a 6-digit code to it.`)
      setCooldown(RESEND_SECONDS)
      setStep('code')
    } catch (requestError) {
      fail(requestError, 'We couldn’t send the code. Please try again.')
    } finally {
      setBusy(false)
    }
  }

  async function resend() {
    if (cooldown > 0 || busy) return
    setError('')
    setBusy(true)
    try {
      await post('/api/resend-reset-otp', { email })
      setCode('')
      setNotice('A new code has been sent. Older codes no longer work.')
      setCooldown(RESEND_SECONDS)
    } catch (requestError) {
      fail(requestError, 'We couldn’t resend the code. Please try again.')
    } finally {
      setBusy(false)
    }
  }

  async function verify(event) {
    event.preventDefault()
    setError('')
    if (code.length !== 6) {
      setError('Enter the 6-digit code from your email.')
      return
    }

    setBusy(true)
    try {
      const data = await post('/api/verify-reset-otp', { email, otp: code })
      setToken(data.reset_token)
      setNotice('')
      setStep('password')
    } catch (requestError) {
      setCode('')
      fail(requestError, 'That code didn’t work. Please try again.')
    } finally {
      setBusy(false)
    }
  }

  async function reset(event) {
    event.preventDefault()
    setError('')
    if (password.length < 8) {
      setError('Use at least 8 characters for your new password.')
      return
    }
    if (password !== confirmation) {
      setError('The password confirmation does not match.')
      return
    }

    setBusy(true)
    try {
      await post('/api/reset-password', {
        email,
        token,
        password,
        password_confirmation: confirmation,
      })
      setToken('')
      setPassword('')
      setConfirmation('')
      setStep('done')
    } catch (requestError) {
      const fieldError = requestError.response?.data?.errors?.password?.[0]
      if (fieldError) {
        setError(fieldError)
      } else if (requestError.response?.status === 422) {
        setToken('')
        setPassword('')
        setConfirmation('')
        setStep('email')
        setError('Your reset session expired. Please request a new code.')
      } else {
        fail(requestError, 'We couldn’t update your password. Please try again.')
      }
    } finally {
      setBusy(false)
    }
  }

  const stepNumber = { email: 1, code: 2, password: 3 }[step]

  return (
    <main className="login-page">
      <section className="fp-card" aria-live="polite">
        <img className="fp-logo" src={techronLogo} alt="TechRon Ltd." />

        {step !== 'done' && <p className="fp-step">STEP {stepNumber} OF 3</p>}

        {error && <div className="login-alert" role="alert">{error}</div>}
        {notice && step === 'code' && <div className="fp-notice" role="status">{notice}</div>}

        {step === 'email' && (
          <form className="login-form" onSubmit={sendCode} noValidate>
            <div className="fp-heading">
              <h1>Forgot your password?</h1>
              <p className="fp-muted">Enter your work email and we’ll send you a 6-digit code.</p>
            </div>
            <Input
              id="fp-email"
              label="Email address"
              type="email"
              autoComplete="email"
              autoFocus
              placeholder="you@example.com"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
            <Button className="login-submit" type="submit" loading={busy}>Send code</Button>
            <Link className="login-forgot-link fp-center" to="/login">Back to sign in</Link>
          </form>
        )}

        {step === 'code' && (
          <form className="login-form" onSubmit={verify} noValidate>
            <div className="fp-heading">
              <h1>Check your email</h1>
              <p className="fp-muted">The code expires in 10 minutes.</p>
            </div>
            <Input
              id="fp-code"
              className="fp-otp"
              label="Verification code"
              inputMode="numeric"
              autoComplete="one-time-code"
              autoFocus
              maxLength={6}
              placeholder="000000"
              value={code}
              onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))}
            />
            <Button className="login-submit" type="submit" loading={busy} disabled={code.length !== 6}>
              Verify code
            </Button>
            <div className="fp-row">
              <button type="button" className="fp-link" onClick={resend} disabled={cooldown > 0 || busy}>
                {cooldown > 0 ? `Resend code in ${cooldown}s` : 'Resend code'}
              </button>
              <button
                type="button"
                className="fp-link"
                onClick={() => { setStep('email'); setError(''); setNotice('') }}
              >
                Use a different email
              </button>
            </div>
          </form>
        )}

        {step === 'password' && (
          <form className="login-form" onSubmit={reset} noValidate>
            <div className="fp-heading">
              <h1>Choose a new password</h1>
              <p className="fp-muted">Use at least 8 characters.</p>
            </div>
            <Input
              id="fp-password"
              label="New password"
              type="password"
              autoComplete="new-password"
              autoFocus
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
            <Input
              id="fp-confirm"
              label="Confirm new password"
              type="password"
              autoComplete="new-password"
              value={confirmation}
              onChange={(event) => setConfirmation(event.target.value)}
            />
            <Button className="login-submit" type="submit" loading={busy}>Update password</Button>
          </form>
        )}

        {step === 'done' && (
          <div className="fp-done">
            <CheckCircle2 size={44} aria-hidden="true" />
            <h1>Password updated</h1>
            <p className="fp-muted">You can now sign in with your new password.</p>
            <Link className="login-button login-submit fp-button-link" to="/login">Back to sign in</Link>
          </div>
        )}
      </section>
      <footer className="login-footer">© 2026 TechRon Ltd. All rights reserved.</footer>
    </main>
  )
}
