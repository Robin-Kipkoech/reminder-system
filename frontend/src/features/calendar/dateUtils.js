const pad = (n) => String(n).padStart(2, '0')

export const toISO = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`

export function fromISO(value) {
  const [y, m, d] = String(value).slice(0, 10).split('-').map(Number)
  return new Date(y, m - 1, d)
}

export function addDays(date, n) {
  const next = new Date(date.getFullYear(), date.getMonth(), date.getDate())
  next.setDate(next.getDate() + n)
  return next
}

// Weeks start on Monday.
export function startOfWeek(date) {
  const day = (date.getDay() + 6) % 7
  return addDays(date, -day)
}

export function monthGrid(cursor) {
  const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1)
  const last = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0)
  const start = startOfWeek(first)
  const end = addDays(startOfWeek(last), 6)
  const days = []
  for (let d = start; d <= end; d = addDays(d, 1)) days.push(d)
  return days
}

export function weekGrid(cursor) {
  const start = startOfWeek(cursor)
  return Array.from({ length: 7 }, (_, i) => addDays(start, i))
}

export function todayISO() {
  return new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Nairobi' })
}

export function monthTitle(cursor) {
  return cursor.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })
}

export function weekTitle(days) {
  const first = days[0]
  const last = days[days.length - 1]
  const sameMonth = first.getMonth() === last.getMonth()
  const left = first.toLocaleDateString('en-GB', sameMonth ? { day: 'numeric' } : { day: 'numeric', month: 'short' })
  const right = last.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
  return `${left} – ${right}`
}

export function longDay(date) {
  return date.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' })
}