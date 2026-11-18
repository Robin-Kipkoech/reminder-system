import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { AlertTriangle, CheckCircle2, Send, XCircle } from 'lucide-react'
import { useAuth } from '../../../auth/useAuth'
import Button from '../../../components/Button'
import Input from '../../../components/Input'
import Switch from '../../../components/Switch'
import { useToast } from '../../../components/Toast'
import { errorMessage } from '../../../lib/api'
import { timeAgo } from '../../../lib/time'
import { useEmailLogs, useEmailSettings, useSaveEmailSettings, useSendTestEmail } from '../hooks/useEmailSettings'

const schema = z.object({
  host: z.string().trim().min(1, 'Enter the mail server host.').max(255),
  port: z.string().trim().regex(/^\d+$/, 'Enter a port number.')
    .refine((value) => Number(value) >= 1 && Number(value) <= 65535, 'The port must be between 1 and 65535.'),
  encryption: z.enum(['tls', 'ssl', 'none']),
  username: z.string().trim().min(1, 'Enter the username.'),
  password: z.string(),
  from_address: z.string().trim().email('Enter a valid email address.'),
  from_name: z.string().trim().min(1, 'Enter the sender name.').max(100),
  is_enabled: z.boolean(),
  reminder_send_time: z.string().regex(/^\d{2}:\d{2}$/, 'Choose a time.'),
  send_overdue_notices: z.boolean(),
})

const toValues = (s) => ({
  host: s.host ?? '',
  port: String(s.port ?? 587),
  encryption: s.encryption ?? 'none',
  username: s.username ?? '',
  password: '',
  from_address: s.from_address ?? '',
  from_name: s.from_name ?? '',
  is_enabled: s.is_enabled ?? true,
  reminder_send_time: String(s.reminder_send_time ?? '08:00').slice(0, 5),
  send_overdue_notices: s.send_overdue_notices ?? true,
})

const when = (value) => new Date(value).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' })

/* ---------- status strip ---------- */
function StatusStrip({ settings }) {
  const { queue = {} } = settings
  return (
    <div className="st-status" role="region" aria-label="Email status">
      <div className="st-status-item">
        <span className="st-label">Settings in use</span>
        <strong>{settings.source === 'database' ? 'Saved on this page' : 'Server .env file'}</strong>
        {!settings.configured && <small>Nothing has been saved here yet.</small>}
      </div>
      <div className="st-status-item">
        <span className="st-label">Sending</span>
        <strong className={settings.is_enabled ? 'st-ok' : 'st-warn'}>{settings.is_enabled ? 'Enabled' : 'Paused (using .env)'}</strong>
      </div>
      <div className="st-status-item">
        <span className="st-label">Last test</span>
        {settings.last_test_at ? (
          <>
            <strong className={settings.last_test_status === 'success' ? 'st-ok' : 'st-bad'}>
              {settings.last_test_status === 'success' ? 'Passed' : 'Failed'}
            </strong>
            <small>{timeAgo(settings.last_test_at)}</small>
          </>
        ) : <strong>Not tested yet</strong>}
      </div>
      <div className="st-status-item">
        <span className="st-label">Email queue</span>
        <strong>{queue.pending ?? 0} waiting</strong>
        <small className={queue.failed > 0 ? 'st-bad' : ''}>{queue.failed ?? 0} failed</small>
      </div>
    </div>
  )
}

/* ---------- settings form ---------- */
function SettingsForm({ settings, onDirtyChange }) {
  const toast = useToast()
  const save = useSaveEmailSettings()
  const {
    register, handleSubmit, reset, setError, formState: { errors, isDirty, isSubmitting },
  } = useForm({ resolver: zodResolver(schema), defaultValues: toValues(settings) })

  // Reload the form only when the saved settings really change (not after a test email).
  useEffect(() => {
    reset(toValues(settings))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [settings.updated_at])

  useEffect(() => { onDirtyChange(isDirty) }, [isDirty, onDirtyChange])

  async function onSubmit(values) {
    const payload = {
      host: values.host,
      port: Number(values.port),
      encryption: values.encryption === 'none' ? null : values.encryption,
      username: values.username,
      from_address: values.from_address,
      from_name: values.from_name,
      is_enabled: values.is_enabled,
      reminder_send_time: values.reminder_send_time,
      send_overdue_notices: values.send_overdue_notices,
    }
    if (values.password) payload.password = values.password
    try {
      await save.mutateAsync(payload)
      toast.success('Email settings saved.')
    } catch (error) {
      const serverErrors = error.response?.data?.errors
      if (error.response?.status === 422 && serverErrors) {
        Object.entries(serverErrors).forEach(([name, messages]) => setError(name, { message: messages[0] }))
      } else {
        toast.error(errorMessage(error))
      }
    }
  }

  return (
    <form className="st-form" onSubmit={handleSubmit(onSubmit)} noValidate>
      <section className="st-card">
        <h2>Mail server connection</h2>
        <p className="st-help">The details your email provider gives you for sending mail (SMTP).</p>
        <div className="tk-grid-2">
          <Input id="es-host" label="Host" placeholder="smtppro.zoho.com" error={errors.host?.message} {...register('host')} />
          <Input id="es-port" label="Port" inputMode="numeric" placeholder="587" error={errors.port?.message} {...register('port')} />
        </div>
        <div className="tk-grid-2">
          <div className="field">
            <label className="field-label" htmlFor="es-encryption">Encryption</label>
            <div className="field-control">
              <select id="es-encryption" className="field-input" {...register('encryption')}>
                <option value="tls">TLS (port 587)</option>
                <option value="ssl">SSL (port 465)</option>
                <option value="none">None</option>
              </select>
            </div>
          </div>
          <Input id="es-username" label="Username" autoComplete="off" error={errors.username?.message} {...register('username')} />
        </div>
        <Input
          id="es-password" label="Password or app password" type="password" autoComplete="new-password"
          placeholder={settings.has_password ? '•••••••• (saved)' : 'Enter the password'}
          error={errors.password?.message} {...register('password')}
        />
        <p className="st-help">
          {settings.has_password
            ? 'A password is already saved and is never shown. Leave this empty to keep it, or type a new one to replace it.'
            : 'The password is stored encrypted.'}
        </p>
      </section>

      <section className="st-card">
        <h2>Sender</h2>
        <div className="tk-grid-2">
          <Input id="es-from" label="From address" type="email" error={errors.from_address?.message} {...register('from_address')} />
          <Input id="es-name" label="From name" error={errors.from_name?.message} {...register('from_name')} />
        </div>
        <div className="st-row">
          <div>
            <strong>Send email from these settings</strong>
            <small>When off, the system falls back to the server’s .env file.</small>
          </div>
          <Switch label="Send email from these settings" {...register('is_enabled')} />
        </div>
      </section>

      <section className="st-card">
        <h2>Reminder schedule</h2>
        <p className="st-help">When people are emailed about tasks and items that are due soon. People can choose their own time in My profile.</p>
        <div className="tk-grid-2">
          <Input id="es-time" label="Send reminders at" type="time" error={errors.reminder_send_time?.message} {...register('reminder_send_time')} />
        </div>
        <div className="st-row">
          <div>
            <strong>Daily overdue notices</strong>
            <small>Remind people each day about tasks and items that are already overdue.</small>
          </div>
          <Switch label="Daily overdue notices" {...register('send_overdue_notices')} />
        </div>
      </section>

      <div className="st-save-bar">
        {isDirty && <span className="st-unsaved">You have unsaved changes.</span>}
        <Button type="submit" loading={isSubmitting} disabled={!isDirty}>Save settings</Button>
      </div>
    </form>
  )
}

/* ---------- test email ---------- */
function TestCard({ dirty, redirectTo }) {
  const { user } = useAuth()
  const send = useSendTestEmail()
  const [to, setTo] = useState('')
  const [result, setResult] = useState(null)

  async function submit(event) {
    event.preventDefault()
    setResult(null)
    try {
      const data = await send.mutateAsync(to.trim() || undefined)
      setResult({ ok: true, message: data?.message || 'Test email sent. Check the inbox (and the spam folder).' })
    } catch (error) {
      setResult({ ok: false, message: error.response?.data?.message || errorMessage(error, 'The test email could not be sent.') })
    }
  }

  return (
    <section className="st-card">
      <h2>Send a test email</h2>
      <p className="st-help">Uses the saved settings, so save any changes first.</p>
      {dirty && (
        <div className="st-banner warn"><AlertTriangle size={17} aria-hidden="true" /> You have unsaved changes. The test will use the previously saved settings.</div>
      )}
      {redirectTo && (
        <div className="st-banner info">Test mode is on: every email is redirected to {redirectTo}.</div>
      )}
      <form className="st-test-form" onSubmit={submit} noValidate>
        <Input id="es-test-to" label="Send to" type="email" placeholder={user?.email ?? 'you@example.com'}
          value={to} onChange={(event) => setTo(event.target.value)} />
        <Button type="submit" loading={send.isPending}><Send size={16} /> Send test email</Button>
      </form>
      {result && (
        <div className={`st-banner ${result.ok ? 'ok' : 'bad'}`} role="status">
          {result.ok ? <CheckCircle2 size={17} aria-hidden="true" /> : <XCircle size={17} aria-hidden="true" />}
          {result.message}
        </div>
      )}
    </section>
  )
}

/* ---------- delivery log ---------- */
function LogCard() {
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading, isError, refetch } = useEmailLogs({ page, per_page: 15, status: status || undefined })
  const rows = Array.isArray(data) ? data : data?.data ?? []
  const lastPage = data?.meta?.last_page ?? 1
  const total = data?.meta?.total ?? rows.length

  return (
    <section className="st-card">
      <div className="st-card-head">
        <h2>Delivery log</h2>
        <select className="md-select" aria-label="Filter by status" value={status}
          onChange={(event) => { setStatus(event.target.value); setPage(1) }}>
          <option value="">All</option>
          <option value="sent">Sent</option>
          <option value="failed">Failed</option>
          <option value="pending">Pending</option>
          <option value="skipped">Skipped</option>
        </select>
      </div>

      {isError ? (
        <div className="md-state"><p>We couldn’t load the log.</p>
          <Button className="btn-secondary" onClick={() => refetch()}>Try again</Button></div>
      ) : isLoading ? (
        <div className="md-skeleton" style={{ height: 160 }} role="status" aria-label="Loading" />
      ) : rows.length === 0 ? (
        <p className="db-empty">No emails recorded yet.</p>
      ) : (
        <>
          <table className="md-table st-log">
            <thead><tr><th>When</th><th>Recipient</th><th>Subject</th><th>Status</th></tr></thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.id}>
                  <td data-label="When">{when(row.sent_at ?? row.created_at)}</td>
                  <td data-label="Recipient">
                    {row.user?.name ?? 'Unknown'}
                    {row.user?.email && <small className="tk-sub">{row.user.email}</small>}
                  </td>
                  <td data-label="Subject">
                    {row.title}
                    {row.error_message && <small className="tk-sub st-bad">{row.error_message}</small>}
                  </td>
                  <td data-label="Status"><span className={`md-badge st-${row.status}`}>{row.status}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
          <nav className="md-pagination" aria-label="Pagination">
            <span>Page {page} of {lastPage} · {total} total</span>
            <div>
              <Button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
              <Button className="btn-secondary" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>Next</Button>
            </div>
          </nav>
        </>
      )}
    </section>
  )
}

/* ---------- page ---------- */
export default function EmailSettingsPage() {
  const { data: settings, isLoading, isError, refetch } = useEmailSettings()
  const [dirty, setDirty] = useState(false)

  return (
    <div className="md-page">
      <header className="md-page-header">
        <div>
          <h1>Email settings</h1>
          <p>How the system sends email, when reminders go out, and a record of what was sent.</p>
        </div>
      </header>

      {isError ? (
        <div className="md-state"><p>We couldn’t load the email settings.</p>
          <Button className="btn-secondary" onClick={() => refetch()}>Try again</Button></div>
      ) : isLoading || !settings ? (
        <div className="md-skeletons" role="status" aria-label="Loading">
          {Array.from({ length: 4 }, (_, i) => <div key={i} className="md-skeleton" style={{ height: 90 }} />)}
        </div>
      ) : (
        <>
          <StatusStrip settings={settings} />
          {settings.queue?.failed > 0 && (
            <div className="st-banner warn">
              <AlertTriangle size={17} aria-hidden="true" />
              {settings.queue.failed} email job(s) failed after several attempts. See the delivery log below for the reason.
            </div>
          )}
          <div className="st-layout">
            <SettingsForm settings={settings} onDirtyChange={setDirty} />
            <TestCard dirty={dirty} redirectTo={settings.redirect_to} />
          </div>
          <LogCard />
        </>
      )}
    </div>
  )
}