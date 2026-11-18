import { useState } from 'react'
import { Eye, EyeOff } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
import Button from '../components/Button'
import Input from '../components/Input'
import techronLogo from '../assets/techron-logo.png'

const loginSchema = z.object({
  email: z.email('Enter a valid email address.'),
  password: z.string().min(1, 'Password is required.'),
  remember: z.boolean(),
})

export default function LoginPage() {
  const { user, loading, login } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()
  const [passwordVisible, setPasswordVisible] = useState(false)
  const [serverError, setServerError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '', remember: false },
  })

  if (loading) {
    return (
      <main className="login-page" aria-busy="true">
        <p role="status">Checking your session…</p>
      </main>
    )
  }
  if (user) return <Navigate to="/" replace />

  async function submit(values) {
    setSubmitting(true)
    setServerError('')
    try {
      const signedInUser = await login(values.email, values.password, values.remember)
      const destination = signedInUser?.must_change_password
        ? '/set-password'
        : location.state?.from
          ? `${location.state.from.pathname}${location.state.from.search || ''}${location.state.from.hash || ''}`
          : '/'
      navigate(destination, { replace: true })
    } catch (error) {
      const status = error.response?.status
      const message = error.response?.data?.message
      if (status === 429) {
        setServerError('Too many attempts. Please try again in a minute.')
      } else if (status === 403) {
        setServerError(message || 'Your account cannot sign in. Contact an administrator.')
      } else if (status === 422) {
        setServerError(message || 'Invalid email or password.')
      } else {
        setServerError(message || 'Unable to sign in. Please try again.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  const passwordToggle = (
    <button
      className="login-password-toggle"
      type="button"
      onClick={() => setPasswordVisible((visible) => !visible)}
      aria-label={passwordVisible ? 'Hide password' : 'Show password'}
      aria-pressed={passwordVisible}
    >
      {passwordVisible ? <EyeOff size={19} /> : <Eye size={19} />}
    </button>
  )

  return (
    <main className="login-page">
      <section className="login-card" aria-label="Sign in to Reminder System">
        <aside className="login-brand-panel">
          <div className="login-logo-frame">
            <img className="login-brand-logo" src={techronLogo} alt="TechRon Ltd." />
          </div>
          <div className="login-brand-copy">
            <p className="login-eyebrow">TECHRON LTD.</p>
            <h1>Reminder System</h1>
            <p>Never miss a task or a due date.</p>
          </div>
          <span className="login-brand-decoration" aria-hidden="true" />
        </aside>

        <div className="login-form-panel">
          <img className="login-mobile-logo" src={techronLogo} alt="TechRon Ltd." />
          <div className="login-heading">
            <p className="login-eyebrow">WELCOME BACK</p>
            <h2>Sign in to your account</h2>
            <p>Enter your details to continue to your workspace.</p>
          </div>

          <form className="login-form" onSubmit={handleSubmit(submit)} noValidate>
            {serverError && <div className="login-alert" role="alert">{serverError}</div>}

            <Input
              id="login-email"
              label="Email address"
              type="email"
              autoComplete="email"
              placeholder="you@example.com"
              error={errors.email?.message}
              {...register('email')}
            />

            <Input
              id="login-password"
              label="Password"
              type={passwordVisible ? 'text' : 'password'}
              autoComplete="current-password"
              placeholder="Enter your password"
              error={errors.password?.message}
              trailing={passwordToggle}
              {...register('password')}
            />

            <div className="login-form-options">
              <label className="login-remember">
                <input type="checkbox" {...register('remember')} />
                <span>Remember me</span>
              </label>
              <Link className="login-forgot-link" to="/forgot-password">Forgot password?</Link>
            </div>

            <Button className="login-submit" type="submit" loading={submitting} disabled={submitting}>
              {submitting ? 'Signing in…' : 'Sign in'}
            </Button>
          </form>
        </div>
      </section>

      <footer className="login-footer">© 2026 TechRon Ltd. All rights reserved.</footer>
    </main>
  )
}
