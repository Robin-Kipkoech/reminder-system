import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import Avatar from '../../../components/Avatar'
import Button from '../../../components/Button'
import { dueText, dueTone, timeAgo } from '../../../lib/time'
import { formatDate } from '../../tasks/utils'
import { useUserProfile } from '../hooks/useProfile'

function browserSummary(userAgent = '') {
  const browser = /Edg\//.test(userAgent) ? 'Edge'
    : /OPR\//.test(userAgent) ? 'Opera'
      : /Chrome\//.test(userAgent) ? 'Chrome'
        : /Firefox\//.test(userAgent) ? 'Firefox'
          : /Safari\//.test(userAgent) ? 'Safari' : 'Unknown browser'
  const os = /Windows/.test(userAgent) ? 'Windows'
    : /Android/.test(userAgent) ? 'Android'
      : /iPhone|iPad/.test(userAgent) ? 'iOS'
        : /Mac OS/.test(userAgent) ? 'macOS'
          : /Linux/.test(userAgent) ? 'Linux' : ''
  return os ? `${browser} on ${os}` : browser
}

const TABS = [
  { key: 'tasks', label: 'Tasks' },
  { key: 'items', label: 'Items in charge' },
  { key: 'activity', label: 'Activity' },
  { key: 'signins', label: 'Sign-ins' },
]

function Stat({ label, value, tone = '' }) {
  return (
    <div className={`db-stat ${tone}`.trim()}>
      <span className="db-stat-value">{value ?? 0}</span>
      <span className="db-stat-label">{label}</span>
    </div>
  )
}

function Rows({ rows, basePath, empty }) {
  if (!rows?.length) return <p className="db-empty">{empty}</p>
  return (
    <ul className="db-list">
      {rows.map((row) => (
        <li key={row.id} className="db-row">
          <Link to={`${basePath}?open=${row.id}`} className="db-row-main">
            <span className="db-row-title">{row.title}</span>
            <small className="db-row-sub">
              {(row.client?.name ?? row.client_name) || '—'} · {formatDate(row.due_date)} ·{' '}
              <span className={dueTone(row.days_until_due)}>{dueText(row.days_until_due)}</span>
            </small>
          </Link>
        </li>
      ))}
    </ul>
  )
}

export default function UserProfilePage() {
  const { id } = useParams()
  const { data, isLoading, isError, refetch } = useUserProfile(id)
  const [tab, setTab] = useState('tasks')

  if (isError) {
    return (
      <div className="md-page">
        <div className="md-state">
          <p>We couldn’t load this user.</p>
          <Button className="btn-secondary" onClick={() => refetch()}>Try again</Button>
          <Link to="/admin/users" className="db-link">Back to users</Link>
        </div>
      </div>
    )
  }

  if (isLoading || !data) {
    return (
      <div className="md-page">
        <div className="md-skeletons" role="status" aria-label="Loading">
          {Array.from({ length: 4 }, (_, index) => <div key={index} className="md-skeleton" style={{ height: 70 }} />)}
        </div>
      </div>
    )
  }

  const { user, stats = {}, open_tasks: openTasks = [], items_in_charge: itemsInCharge = [], recent_activity: recentActivity = [], login_history: loginHistory = [] } = data

  return (
    <div className="md-page">
      <Link to="/admin/users" className="up-back"><ArrowLeft size={16} /> All users</Link>

      <header className="up-header">
        <Avatar user={user} size="lg" />
        <div className="up-identity">
          <h1>{user.name}</h1>
          <p>{user.email}{user.phone ? ` · ${user.phone}` : ''}</p>
          <div className="up-badges">
            <span className="md-badge tk-status-open">{user.role}</span>
            <span className={`md-badge ${user.status === 'active' ? 'active' : 'inactive'}`}>{user.status}</span>
            <small>Last active: {user.last_login_at ? timeAgo(user.last_login_at) : 'never'}</small>
          </div>
        </div>
      </header>

      <div className="db-stats">
        <Stat label="Open tasks" value={stats.open_tasks} />
        <Stat label="Overdue" value={stats.overdue_tasks} tone={stats.overdue_tasks > 0 ? 'danger' : ''} />
        <Stat label="Completed (30 days)" value={stats.completed_last_30_days} />
        <Stat label="Items in charge" value={stats.items_in_charge} />
      </div>

      <section className="db-panel">
        <div className="db-tabs" role="tablist" aria-label="User details">
          {TABS.map((item) => (
            <button
              key={item.key}
              type="button"
              role="tab"
              aria-selected={tab === item.key}
              className={`db-tab${tab === item.key ? ' active' : ''}`}
              onClick={() => setTab(item.key)}
            >
              {item.label}
            </button>
          ))}
        </div>

        {tab === 'tasks' && <Rows rows={openTasks} basePath="/tasks" empty="No open tasks." />}
        {tab === 'items' && <Rows rows={itemsInCharge} basePath="/items" empty="Not in charge of any items." />}

        {tab === 'activity' && (
          recentActivity.length === 0 ? <p className="db-empty">No activity yet.</p> : (
            <ul className="db-activity">
              {recentActivity.map((entry) => (
                <li key={entry.id}>
                  <Avatar user={user} size="sm" />
                  <p>
                    {String(entry.action).replace(/_/g, ' ')}{' '}
                    {String(entry.entity_type ?? '').split('\\').pop().toLowerCase()}
                    {entry.entity_title ? <> <em>{entry.entity_title}</em></> : null}
                    <small>{timeAgo(entry.created_at)}</small>
                  </p>
                </li>
              ))}
            </ul>
          )
        )}

        {tab === 'signins' && (
          loginHistory.length === 0 ? <p className="db-empty">No sign-ins recorded.</p> : (
            <table className="md-table up-table">
              <thead>
                <tr><th>When</th><th>IP address</th><th>Browser</th><th>Result</th></tr>
              </thead>
              <tbody>
                {loginHistory.map((event) => (
                  <tr key={event.id}>
                    <td data-label="When">{new Date(event.created_at).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' })}</td>
                    <td data-label="IP address">{event.ip_address ?? '—'}</td>
                    <td data-label="Browser">{browserSummary(event.user_agent)}</td>
                    <td data-label="Result">
                      <span className={`md-badge ${event.successful ? 'active' : 'deleted'}`}>
                        {event.successful ? 'Success' : 'Failed'}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )
        )}
      </section>
    </div>
  )
}
