export const STATUS_LABELS = { open: 'Open', in_progress: 'In progress', done: 'Done' }

export function parseDate(value) {
    if (!value) return null
    const [y, m, d] = String(value).slice(0, 10).split('-').map(Number)
    return new Date(y, m - 1, d)
}

export function formatDate(value) {
    const date = parseDate(value)
    return date
        ? date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
        : '—'
}

export function dueLabel(task) {
    if (task.status === 'done') return 'Completed'
    const days = task.days_until_due
    if (days == null) return ''
    if (days < 0) return `Overdue by ${-days} day${days === -1 ? '' : 's'}`
    if (days === 0) return 'Due today'
    return `Due in ${days} day${days === 1 ? '' : 's'}`
}

export function recurrenceLabel(task) {
    if (!task.is_recurring) return 'Does not repeat'
    const n = task.recurrence_interval || 1
    switch (task.recurrence_type) {
        case 'daily': return 'Repeats daily'
        case 'weekly': return 'Repeats weekly'
        case 'monthly': return 'Repeats monthly'
        case 'yearly': return 'Repeats yearly'
        case 'custom_days': return `Repeats every ${n} day${n === 1 ? '' : 's'}`
        default: return 'Repeats'
    }
}

export function offsetLabel(period) {
  const text = period?.label ?? period?.name
  if (text) return text
  const n = Number(period?.offset_days)
  if (!Number.isFinite(n)) return 'Reminder'
  return n === 0 ? 'On the due day' : `${n} day${n === 1 ? '' : 's'} before`
}