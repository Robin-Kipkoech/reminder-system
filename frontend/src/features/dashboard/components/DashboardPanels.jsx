import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { CalendarClock, CheckCircle2, ClipboardList } from 'lucide-react'
import Avatar from '../../../components/Avatar'
import { useToast } from '../../../components/Toast'
import { errorMessage } from '../../../lib/api'
import { dueText, dueTone, timeAgo } from '../../../lib/time'
import { useTaskMutations } from '../../tasks/hooks/useTasks'
import { formatDate } from '../../tasks/utils'

function Panel({ title, action, children, className = '' }) {
    return (
        <section className={`db-panel ${className}`.trim()}>
            <header className="db-panel-head">
                <h2>{title}</h2>
                {action}
            </header>
            {children}
        </section>
    )
}

const Empty = ({ children }) => <p className="db-empty">{children}</p>

export function StatCards({ stats, showItems }) {
    const cards = [
        { label: 'Overdue', value: stats.overdue_tasks, to: '/tasks?status=overdue', tone: stats.overdue_tasks > 0 ? 'danger' : '' },
        { label: 'Due today', value: stats.due_today, to: '/tasks?status=open', tone: stats.due_today > 0 ? 'warn' : '' },
        { label: 'Due this week', value: stats.due_this_week, to: '/tasks', tone: '' },
        { label: 'My open tasks', value: stats.my_open_tasks, to: '/tasks?mine=1', tone: '' },
    ]
    if (showItems) {
        cards.push(
            { label: 'Items expiring in 30 days', value: stats.items_expiring_30, to: '/items?status=active', tone: stats.items_expiring_30 > 0 ? 'warn' : '' },
            { label: 'Overdue items', value: stats.overdue_items, to: '/items?status=overdue', tone: stats.overdue_items > 0 ? 'danger' : '' },
        )
    }

    return (
        <div className="db-stats">
            {cards.map((card) => (
                <Link key={card.label} to={card.to} className={`db-stat ${card.tone}`.trim()}>
                    <span className="db-stat-value">{card.value ?? 0}</span>
                    <span className="db-stat-label">{card.label}</span>
                </Link>
            ))}
        </div>
    )
}

export function MyTasksPanel({ tasks }) {
    const toast = useToast()
    const queryClient = useQueryClient()
    const { complete } = useTaskMutations()
    const [busyId, setBusyId] = useState(null)

    async function markDone(task) {
        setBusyId(task.id)
        try {
            await complete.mutateAsync(task.id)
            toast.success(`“${task.title}” marked as done.`)
            queryClient.invalidateQueries({ queryKey: ['dashboard'] })
        } catch (error) {
            toast.error(errorMessage(error))
        } finally {
            setBusyId(null)
        }
    }

    return (
        <Panel title="My tasks" action={<Link to="/tasks?mine=1" className="db-link">View all</Link>}>
            {tasks.length === 0 ? (
                <Empty>Nothing assigned to you right now. 🎉</Empty>
            ) : (
                <ul className="db-list">
                    {tasks.map((task) => (
                        <li key={task.id} className="db-row">
                            <Link to={`/tasks?open=${task.id}`} className="db-row-main">
                                <span className="db-row-title">{task.title}</span>
                                <small className="db-row-sub">
                                    {task.client?.name} · <span className={dueTone(task.days_until_due)}>{dueText(task.days_until_due)}</span>
                                </small>
                            </Link>
                            <button
                                type="button"
                                className="db-done"
                                disabled={busyId === task.id}
                                onClick={() => markDone(task)}
                                aria-label={`Mark “${task.title}” as done`}
                            >
                                <CheckCircle2 size={18} /> Done
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    )
}

export function UpcomingPanel({ entries }) {
    return (
        <Panel title="Coming up">
            {entries.length === 0 ? (
                <Empty>No deadlines in the next two weeks.</Empty>
            ) : (
                <ul className="db-list">
                    {entries.map((entry) => (
                        <li key={`${entry.type}-${entry.id}`} className="db-row">
                            <Link to={`/${entry.type === 'item' ? 'items' : 'tasks'}?open=${entry.id}`} className="db-row-main">
                                <span className="db-row-title">
                                    {entry.type === 'item'
                                        ? <CalendarClock size={15} aria-label="Item" />
                                        : <ClipboardList size={15} aria-label="Task" />}
                                    {entry.title}
                                </span>
                                <small className="db-row-sub">
                                    {entry.client_name} · {formatDate(entry.due_date)} ·{' '}
                                    <span className={dueTone(entry.days_until_due)}>{dueText(entry.days_until_due)}</span>
                                </small>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    )
}

const TABS = [
    { key: 'within_7', label: '0–7 days' },
    { key: 'within_30', label: '8–30 days' },
    { key: 'within_60', label: '31–60 days' },
]

export function ItemsExpiringPanel({ buckets, onRenew }) {
    const [tab, setTab] = useState('within_7')
    const rows = buckets?.[tab] ?? []

    return (
        <Panel title="Items expiring soon" action={<Link to="/items" className="db-link">View all</Link>}>
            <div className="db-tabs" role="tablist" aria-label="Expiry window">
                {TABS.map((item) => (
                    <button
                        key={item.key}
                        type="button"
                        role="tab"
                        aria-selected={tab === item.key}
                        className={`db-tab${tab === item.key ? ' active' : ''}`}
                        onClick={() => setTab(item.key)}
                    >
                        {item.label} <span className="db-tab-count">{buckets?.[item.key]?.length ?? 0}</span>
                    </button>
                ))}
            </div>
            {rows.length === 0 ? (
                <Empty>Nothing expiring in this window.</Empty>
            ) : (
                <ul className="db-list">
                    {rows.map((item) => (
                        <li key={item.id} className="db-row">
                            <Link to={`/items?open=${item.id}`} className="db-row-main">
                                <span className="db-row-title">{item.title}</span>
                                <small className="db-row-sub">
                                    {item.client_name} · {formatDate(item.due_date)} ·{' '}
                                    <span className={dueTone(item.days_until_due)}>{dueText(item.days_until_due)}</span>
                                </small>
                            </Link>
                            <button type="button" className="db-done" onClick={() => onRenew(item)}>
                                <CalendarClock size={17} /> Renew
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    )
}

export function WorkloadPanel({ rows }) {
    const data = rows.map((row) => ({
        name: row.user?.name ?? 'Unknown',
        Open: Math.max((row.open_tasks ?? 0) - (row.overdue_tasks ?? 0), 0),
        Overdue: row.overdue_tasks ?? 0,
    }))
    return (
        <Panel title="Workload by person">
            {data.length === 0 ? (
                <Empty>No open tasks assigned yet.</Empty>
            ) : (
                <div className="db-chart" role="img" aria-label="Open and overdue tasks per person">
                    <ResponsiveContainer width="100%" height={Math.max(180, data.length * 38)}>
                        <BarChart data={data} layout="vertical" margin={{ left: 8, right: 16 }}>
                            <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke="var(--border)" />
                            <XAxis type="number" allowDecimals={false} stroke="var(--muted)" />
                            <YAxis type="category" dataKey="name" width={110} stroke="var(--muted)" />
                            <Tooltip cursor={{ fill: 'var(--brand-soft)' }} />
                            <Legend />
                            <Bar dataKey="Open" stackId="a" fill="var(--brand)" />
                            <Bar dataKey="Overdue" stackId="a" fill="var(--danger)" />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            )}
        </Panel>
    )
}

export function RankedPanel({ title, rows, labelOf }) {
    const max = Math.max(1, ...rows.map((row) => (row.open_tasks ?? 0) + (row.active_items ?? 0)))
    return (
        <Panel title={title}>
            {rows.length === 0 ? (
                <Empty>No data yet.</Empty>
            ) : (
                <ul className="db-rank">
                    {rows.map((row, index) => {
                        const total = (row.open_tasks ?? 0) + (row.active_items ?? 0)
                        return (
                            <li key={index}>
                                <div className="db-rank-head">
                                    <span>{labelOf(row)}</span>
                                    <small>{row.open_tasks ?? 0} tasks · {row.active_items ?? 0} items</small>
                                </div>
                                <div className="db-bar"><span style={{ width: `${(total / max) * 100}%` }} /></div>
                            </li>
                        )
                    })}
                </ul>
            )}
        </Panel>
    )
}

const VERBS = {
    created: 'created',
    updated: 'updated',
    deleted: 'deleted',
    completed: 'completed',
    renewed: 'renewed',
    restored: 'restored',
    suspended: 'suspended',
    activated: 'activated',
    'status-changed': 'changed the status of',
    'due-date-changed': 'changed the due date of',
    'comment-added': 'commented on',
}

function entityLabel(type) {
    return String(type ?? '').split('\\').pop().toLowerCase() || 'record'
}

export function ActivityPanel({ entries }) {
    return (
        <Panel title="Recent activity" className="db-wide">
            {entries.length === 0 ? (
                <Empty>No activity yet.</Empty>
            ) : (
                <ul className="db-activity">
                    {entries.map((entry) => (
                        <li key={entry.id}>
                            <Avatar user={entry.actor ?? { name: 'System' }} size="sm" />
                            <p>
                                <strong>{entry.actor?.name ?? 'System'}</strong>{' '}
                                {VERBS[entry.action] ?? String(entry.action).replace(/[-_]/g, ' ')}{' '}
                                {entityLabel(entry.entity_type)}
                                {entry.entity_title ? <> <em>{entry.entity_title}</em></> : null}
                                <small>{timeAgo(entry.created_at)}</small>
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    )
}
