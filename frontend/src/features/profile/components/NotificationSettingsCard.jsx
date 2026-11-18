import { useState } from 'react'
import Button from '../../../components/Button'
import Switch from '../../../components/Switch'
import { useToast } from '../../../components/Toast'
import { errorMessage } from '../../../lib/api'
import { usePreferences, useSavePreferences } from '../../users/hooks/useProfile'

const TYPES = [
  { key: 'assigned', title: 'Assignments', text: 'A task is assigned to you, or you are put in charge of an item.', email: true },
  { key: 'mention', title: 'Mentions', text: 'Someone mentions you in a comment.', email: true },
  { key: 'completed', title: 'Completed tasks', text: 'A task you created is marked as done.', email: true },
  { key: 'reminder', title: 'Due-date reminders', text: 'Reminders before something is due, and overdue notices.', email: true },
  { key: 'comment', title: 'Comments', text: 'New comments on tasks you are involved in.', email: false },
]

function startingTypes(data) {
  const result = {}
  for (const type of TYPES) {
    const saved = data?.types?.[type.key] ?? {}
    result[type.key] = {
      email: saved.email ?? type.email,
      in_app: saved.in_app ?? true,
    }
  }
  return result
}

function Form({ initial }) {
  const toast = useToast()
  const save = useSavePreferences()
  const [masterEmail, setMasterEmail] = useState(Boolean(initial.notify_email))
  const [masterApp, setMasterApp] = useState(Boolean(initial.notify_push))
  const [digest, setDigest] = useState(initial.digest_time ? String(initial.digest_time).slice(0, 5) : '')
  const [types, setTypes] = useState(() => startingTypes(initial))
  const orgTime = initial.reminder_send_time ? String(initial.reminder_send_time).slice(0, 5) : null

  const setType = (key, channel, value) =>
    setTypes((current) => ({ ...current, [key]: { ...current[key], [channel]: value } }))

  async function submit(event) {
    event.preventDefault()
    try {
      await save.mutateAsync({
        notify_email: masterEmail,
        notify_push: masterApp,
        digest_time: digest || null,
        types,
      })
      toast.success('Notification settings saved.')
    } catch (error) {
      toast.error(errorMessage(error))
    }
  }

  return (
    <form className="md-form" onSubmit={submit}>
      <div className="st-row">
        <div>
          <strong>Email notifications</strong>
          <small>Master switch for every email from the system.</small>
        </div>
        <Switch label="Email notifications" checked={masterEmail} onChange={(event) => setMasterEmail(event.target.checked)} />
      </div>
      <div className="st-row">
        <div>
          <strong>In-app notifications</strong>
          <small>Master switch for the bell and the notifications page.</small>
        </div>
        <Switch label="In-app notifications" checked={masterApp} onChange={(event) => setMasterApp(event.target.checked)} />
      </div>

      <table className="st-types">
        <caption className="sr-only">Choose which notifications you receive</caption>
        <thead>
          <tr>
            <th scope="col">What</th>
            <th scope="col" className="st-center">Email</th>
            <th scope="col" className="st-center">In the app</th>
          </tr>
        </thead>
        <tbody>
          {TYPES.map((type) => (
            <tr key={type.key}>
              <th scope="row">
                <strong>{type.title}</strong>
                <small>{type.text}</small>
              </th>
              <td className="st-center">
                <Switch
                  label={`${type.title} by email`}
                  checked={masterEmail && types[type.key].email}
                  disabled={!masterEmail}
                  onChange={(event) => setType(type.key, 'email', event.target.checked)}
                />
              </td>
              <td className="st-center">
                <Switch
                  label={`${type.title} in the app`}
                  checked={masterApp && types[type.key].in_app}
                  disabled={!masterApp}
                  onChange={(event) => setType(type.key, 'in_app', event.target.checked)}
                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {(!masterEmail || !masterApp) && (
        <p className="tk-hint">
          {!masterEmail && 'Turn on email notifications to choose which emails you get. '}
          {!masterApp && 'Turn on in-app notifications to choose what shows in the app.'}
        </p>
      )}

      <div className="field">
        <label className="field-label" htmlFor="ns-digest">Daily digest time (optional)</label>
        <div className="field-control">
          <input id="ns-digest" type="time" className="field-input" value={digest}
            onChange={(event) => setDigest(event.target.value)} />
        </div>
        <p className="tk-hint">
          {digest
            ? `Your reminders are combined into one email sent at ${digest}.`
            : `Leave empty to get reminders one by one${orgTime ? ` at ${orgTime}` : ' at the organisation’s usual time'}.`}
        </p>
      </div>

      <div className="md-actions">
        <Button type="submit" loading={save.isPending}>Save notification settings</Button>
      </div>
    </form>
  )
}

export default function NotificationSettingsCard() {
  const { data, isLoading, isError, refetch } = usePreferences()
  return (
    <section className="pf-card st-wide">
      <h2>Notifications</h2>
      {isError ? (
        <div className="md-state"><p>We couldn’t load your settings.</p>
          <Button className="btn-secondary" onClick={() => refetch()}>Try again</Button></div>
      ) : isLoading || !data ? (
        <div className="md-skeleton" style={{ height: 220 }} role="status" aria-label="Loading" />
      ) : (
        <Form initial={data} />
      )}
    </section>
  )
}