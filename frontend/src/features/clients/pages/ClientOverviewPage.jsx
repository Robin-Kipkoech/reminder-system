import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Mail, MapPin, Phone, User } from 'lucide-react'
import Button from '../../../components/Button'
import { api } from '../../../lib/api'
import { dueText, dueTone } from '../../../lib/time'
import { ITEM_STATUS_LABELS } from '../../items/utils'
import { STATUS_LABELS, formatDate } from '../../tasks/utils'

const unwrap = (r) => r.data?.data ?? r.data
const names = (list) => (Array.isArray(list) ? list.map((p) => p?.name ?? p).filter(Boolean).join(', ') : '') || '—'

function Stat({ label, value, tone = '' }) {
  return (
    <div className={`db-stat ${tone}`.trim()}>
      <span className="db-stat-value">{value ?? 0}</span>
      <span className="db-stat-label">{label}</span>
    </div>
  )
}

function Section({ title, children }) {
  return (
    <section className="db-panel">
      <header className="db-panel-head"><h2>{title}</h2></header>
      {children}
    </section>
  )
}

export default function ClientOverviewPage() {
  const { id } = useParams()
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['clients', 'overview', id],
    queryFn: () => api.get(`/api/clients/${id}/overview`).then(unwrap),
  })

  if (isError) {
    const notFound = error?.response?.status === 404
    return (
      <div className="md-page">
        <div className="md-state">
          <p>{notFound ? 'This client could not be found.' : 'We couldn’t load this client.'}</p>
          {!notFound && <Button className="btn-secondary" onClick={() => refetch()}>Try again</Button>}
          <Link to="/" className="db-link">Back to the dashboard</Link>
        </div>
      </div>
    )
  }

  if (isLoading || !data) {
    return (
      <div className="md-page">
        <div className="md-skeletons" role="status" aria-label="Loading">
          {Array.from({ length: 4 }, (_, i) => <div key={i} className="md-skeleton" style={{ height: 70 }} />)}
        </div>
      </div>
    )
  }

  const { client, counts = {}, open_tasks = [], items = [], recently_completed = [] } = data

  return (
    <div className="md-page">
      <Link to="/tasks" className="up-back"><ArrowLeft size={16} /> Tasks</Link>

      <header className="md-page-header">
        <div>
          <h1>{client.name}</h1>
          <ul className="rp-contact">
            {client.contact_person && <li><User size={15} aria-hidden="true" /> {client.contact_person}</li>}
            {client.email && <li><Mail size={15} aria-hidden="true" /> <a href={`mailto:${client.email}`}>{client.email}</a></li>}
            {client.phone && <li><Phone size={15} aria-hidden="true" /> <a href={`tel:${client.phone}`}>{client.phone}</a></li>}
            {client.address && <li><MapPin size={15} aria-hidden="true" /> {client.address}</li>}
          </ul>
        </div>
        {client.is_active === false && <span className="md-badge inactive">Inactive</span>}
      </header>

      <div className="db-stats">
        <Stat label="Open tasks" value={counts.open_tasks} />
        <Stat label="Overdue tasks" value={counts.overdue_tasks} tone={counts.overdue_tasks > 0 ? 'danger' : ''} />
        <Stat label="Active items" value={counts.active_items} />
        <Stat label="Overdue items" value={counts.overdue_items} tone={counts.overdue_items > 0 ? 'danger' : ''} />
      </div>

      <Section title="Open tasks">
        {open_tasks.length === 0 ? <p className="db-empty">No open tasks for this client.</p> : (
          <table className="md-table tk-table">
            <thead><tr><th>Task</th><th>Due</th><th>Responsible</th><th>Status</th></tr></thead>
            <tbody>
              {open_tasks.map((task) => (
                <tr key={task.id}>
                  <td data-label="Task"><Link to={`/tasks?open=${task.id}`} className="name-link">{task.title}</Link></td>
                  <td data-label="Due">
                    <div>{formatDate(task.due_date)}</div>
                    <small className={`tk-sub ${dueTone(task.days_until_due)}`}>{dueText(task.days_until_due)}</small>
                  </td>
                  <td data-label="Responsible">{names(task.assignees)}</td>
                  <td data-label="Status">
                    <span className={`md-badge tk-status-${task.status}`}>{STATUS_LABELS[task.status] ?? task.status}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Section>

      <Section title="Items">
        {items.length === 0 ? <p className="db-empty">No active items for this client.</p> : (
          <table className="md-table tk-table">
            <thead><tr><th>Item</th><th>Due</th><th>In charge</th><th>Status</th></tr></thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id}>
                  <td data-label="Item"><Link to={`/items?open=${item.id}`} className="name-link">{item.title}</Link></td>
                  <td data-label="Due">
                    <div>{formatDate(item.due_date)}</div>
                    <small className={`tk-sub ${dueTone(item.days_until_due)}`}>{dueText(item.days_until_due)}</small>
                  </td>
                  <td data-label="In charge">{names(item.owners)}</td>
                  <td data-label="Status">
                    <span className={`md-badge it-status-${item.status}`}>{ITEM_STATUS_LABELS[item.status] ?? item.status}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Section>

      <Section title="Recently completed">
        {recently_completed.length === 0 ? <p className="db-empty">Nothing completed yet.</p> : (
          <ul className="db-list">
            {recently_completed.map((task) => (
              <li key={task.id} className="db-row">
                <Link to={`/tasks?open=${task.id}`} className="db-row-main">
                  <span className="db-row-title">{task.title}</span>
                  <small className="db-row-sub">
                    Completed {task.completed_at ? formatDate(task.completed_at) : '—'}
                    {task.completed_by?.name ? ` by ${task.completed_by.name}` : ''}
                  </small>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Section>
    </div>
  )
}