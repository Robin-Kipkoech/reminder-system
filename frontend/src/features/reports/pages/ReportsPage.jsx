import { useMemo, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Download } from 'lucide-react'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import Button from '../../../components/Button'
import Input from '../../../components/Input'
import { useToast } from '../../../components/Toast'
import { errorMessage } from '../../../lib/api'
import { addDays, toISO, todayISO, fromISO } from '../../calendar/dateUtils'
import { ITEM_STATUS_LABELS } from '../../items/utils'
import { formatDate } from '../../tasks/utils'
import { reportsApi } from '../api/reportsApi'

/* ---------- date presets ---------- */
function presetRange(key) {
  const today = fromISO(todayISO())
  if (key === 'this_month') {
    return {
      from: toISO(new Date(today.getFullYear(), today.getMonth(), 1)),
      to: toISO(new Date(today.getFullYear(), today.getMonth() + 1, 0)),
    }
  }
  if (key === 'last_month') {
    return {
      from: toISO(new Date(today.getFullYear(), today.getMonth() - 1, 1)),
      to: toISO(new Date(today.getFullYear(), today.getMonth(), 0)),
    }
  }
  return { from: toISO(addDays(today, -29)), to: toISO(today) }
}

const PRESETS = [
  { key: 'this_month', label: 'This month' },
  { key: 'last_month', label: 'Last month' },
  { key: 'last_30', label: 'Last 30 days' },
]

const nameOf = (value) => {
  if (value == null) return '—'
  if (Array.isArray(value)) return value.map(nameOf).filter((v) => v !== '—').join(', ') || '—'
  if (typeof value === 'object') return value.name ?? value.label ?? '—'
  return String(value)
}
const num = (value) => Number(value) || 0

/* ---------- report definitions ---------- */
const TABS = {
  person: {
    label: 'Tasks by person',
    columns: [
      { label: 'Person', cell: (r) => nameOf(r.user ?? r.name) },
      { label: 'Assigned', num: true, cell: (r) => num(r.assigned) },
      { label: 'Completed', num: true, cell: (r) => num(r.completed) },
      { label: 'On time', num: true, cell: (r) => num(r.completed_on_time) },
      { label: 'Late', num: true, cell: (r) => num(r.completed_late) },
      { label: 'Open & overdue', num: true, danger: true, cell: (r) => num(r.open_overdue) },
    ],
    chart: (rows) => ({
      data: rows.map((r) => ({
        name: nameOf(r.user ?? r.name),
        'Completed on time': num(r.completed_on_time),
        'Completed late': num(r.completed_late),
        'Open & overdue': num(r.open_overdue),
      })),
      bars: [
        ['Completed on time', 'var(--brand)'],
        ['Completed late', '#f59e0b'],
        ['Open & overdue', 'var(--danger)'],
      ],
    }),
  },
  items: {
    label: 'Items expiring',
    columns: [
      { label: 'Item', cell: (r) => r.title },
      { label: 'Client', cell: (r) => nameOf(r.client ?? r.client_name) },
      { label: 'Departments', cell: (r) => nameOf(r.departments) },
      { label: 'In charge', cell: (r) => nameOf(r.owners) },
      { label: 'Due', cell: (r) => (r.due_date ? formatDate(r.due_date) : '—') },
      { label: 'Days left', num: true, cell: (r) => (r.days_until_due ?? '—'), dangerIf: (r) => r.days_until_due < 0 },
      { label: 'Status', cell: (r) => ITEM_STATUS_LABELS[r.status] ?? r.status ?? '—' },
    ],
    chart: (rows) => {
      const buckets = { Overdue: 0, '0–7 days': 0, '8–30 days': 0, '31–60 days': 0, '60+ days': 0 }
      for (const r of rows) {
        const d = Number(r.days_until_due)
        if (Number.isNaN(d)) continue
        if (d < 0) buckets.Overdue += 1
        else if (d <= 7) buckets['0–7 days'] += 1
        else if (d <= 30) buckets['8–30 days'] += 1
        else if (d <= 60) buckets['31–60 days'] += 1
        else buckets['60+ days'] += 1
      }
      return {
        data: Object.entries(buckets).map(([name, Items]) => ({ name, Items })),
        bars: [['Items', 'var(--brand)']],
      }
    },
  },
  clients: {
    label: 'Tasks by client',
    columns: [
      { label: 'Client', cell: (r) => nameOf(r.client ?? r.name) },
      { label: 'Open', num: true, cell: (r) => num(r.open) },
      { label: 'Overdue', num: true, danger: true, cell: (r) => num(r.overdue) },
      { label: 'Completed', num: true, cell: (r) => num(r.completed) },
    ],
    chart: (rows) => ({
      data: rows.map((r) => ({
        name: nameOf(r.client ?? r.name),
        Open: num(r.open),
        Overdue: num(r.overdue),
        Completed: num(r.completed),
      })),
      bars: [['Open', 'var(--brand)'], ['Overdue', 'var(--danger)'], ['Completed', '#22c55e']],
    }),
  },
}

function ReportChart({ spec }) {
  if (spec.data.length === 0) return null
  return (
    <div className="db-chart rp-chart" role="img" aria-label="Report chart">
      <ResponsiveContainer width="100%" height={280}>
        <BarChart data={spec.data} margin={{ left: 0, right: 12, top: 8 }}>
          <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
          <XAxis dataKey="name" stroke="var(--muted)" interval={0} tick={{ fontSize: 12 }} />
          <YAxis allowDecimals={false} stroke="var(--muted)" />
          <Tooltip cursor={{ fill: 'var(--brand-soft)' }} />
          {spec.bars.length > 1 && <Legend />}
          {spec.bars.map(([key, fill]) => <Bar key={key} dataKey={key} fill={fill} radius={[4, 4, 0, 0]} />)}
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

export default function ReportsPage() {
  const toast = useToast()
  const [tab, setTab] = useState('person')
  const [range, setRange] = useState(() => presetRange('last_30'))
  const [exporting, setExporting] = useState(false)

  const invalid = !range.from || !range.to || range.from > range.to
  const params = { from: range.from, to: range.to }
  const report = TABS[tab]

  const { data: rows = [], isLoading, isFetching, isError, refetch } = useQuery({
    queryKey: ['reports', tab, params],
    queryFn: () => reportsApi.get(tab, params),
    enabled: !invalid,
    placeholderData: keepPreviousData,
  })

  const chart = useMemo(() => report.chart(rows), [report, rows])
  const activePreset = PRESETS.find((p) => {
    const r = presetRange(p.key)
    return r.from === range.from && r.to === range.to
  })?.key

  async function exportCsv() {
    setExporting(true)
    try {
      await reportsApi.downloadCsv(tab, params)
      toast.success('CSV downloaded.')
    } catch (error) {
      toast.error(errorMessage(error, 'The export failed. Please try again.'))
    } finally {
      setExporting(false)
    }
  }

  return (
    <div className="md-page">
      <header className="md-page-header">
        <div>
          <h1>Reports</h1>
          <p>See how work is going and export it for meetings or records.</p>
        </div>
        <Button className="btn-secondary" loading={exporting} disabled={invalid || rows.length === 0} onClick={exportCsv}>
          <Download size={17} /> Export CSV
        </Button>
      </header>

      <div className="rp-controls">
        <div className="rp-presets" role="group" aria-label="Date presets">
          {PRESETS.map((p) => (
            <button key={p.key} type="button" className={`up-chip${activePreset === p.key ? ' active' : ''}`}
              aria-pressed={activePreset === p.key} onClick={() => setRange(presetRange(p.key))}>
              {p.label}
            </button>
          ))}
        </div>
        <div className="rp-dates">
          <Input id="rp-from" label="From" type="date" value={range.from} max={range.to || undefined}
            onChange={(e) => setRange((r) => ({ ...r, from: e.target.value }))} />
          <Input id="rp-to" label="To" type="date" value={range.to} min={range.from || undefined}
            onChange={(e) => setRange((r) => ({ ...r, to: e.target.value }))} />
        </div>
      </div>
      {invalid && <p className="field-error">Choose a valid range: the start date must not be after the end date.</p>}

      <section className="db-panel">
        <div className="db-tabs" role="tablist" aria-label="Report type">
          {Object.entries(TABS).map(([key, t]) => (
            <button key={key} type="button" role="tab" aria-selected={tab === key}
              className={`db-tab${tab === key ? ' active' : ''}`} onClick={() => setTab(key)}>
              {t.label}
            </button>
          ))}
        </div>

        {isError ? (
          <div className="md-state">
            <p>We couldn’t load this report.</p>
            <Button className="btn-secondary" onClick={() => refetch()}>Try again</Button>
          </div>
        ) : isLoading ? (
          <div className="md-skeleton" style={{ height: 260 }} role="status" aria-label="Loading" />
        ) : rows.length === 0 ? (
          <p className="db-empty">No data for this period.</p>
        ) : (
          <div className={isFetching ? 'rp-refreshing' : ''}>
            <ReportChart spec={chart} />
            <table className="md-table rp-table">
              <thead>
                <tr>{report.columns.map((c) => <th key={c.label} className={c.num ? 'num-cell' : ''}>{c.label}</th>)}</tr>
              </thead>
              <tbody>
                {rows.map((row, index) => (
                  <tr key={row.id ?? index}>
                    {report.columns.map((c) => {
                      const value = c.cell(row)
                      const bad = (c.danger && Number(value) > 0) || c.dangerIf?.(row)
                      return (
                        <td key={c.label} data-label={c.label} className={`${c.num ? 'num-cell' : ''}${bad ? ' is-overdue' : ''}`}>
                          {value}
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  )
}