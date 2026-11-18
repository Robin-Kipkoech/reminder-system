const CHIPS = [
  { key: 'admin', label: 'admins', filter: { role: 'admin' }, value: (summary) => summary.by_role?.admin },
  { key: 'manager', label: 'managers', filter: { role: 'manager' }, value: (summary) => summary.by_role?.manager },
  { key: 'staff', label: 'staff', filter: { role: 'staff' }, value: (summary) => summary.by_role?.staff },
  { key: 'suspended', label: 'suspended', filter: { status: 'suspended' }, value: (summary) => summary.by_status?.suspended },
]

export default function UserSummaryChips({ summary, filters = {}, onSelect }) {
  if (!summary) return null

  function isActive(chip) {
    return Object.entries(chip.filter).every(([key, value]) => filters[key] === value)
  }

  function toggle(chip) {
    const [key, value] = Object.entries(chip.filter)[0]
    const next = { role: filters.role ?? '', status: filters.status ?? '' }
    next[key] = isActive(chip) ? '' : value
    onSelect(next)
  }

  return (
    <div className="up-chips" role="group" aria-label="Quick filters">
      <span className="up-chip-total">{summary.total} users</span>
      {CHIPS.map((chip) => (
        <button
          key={chip.key}
          type="button"
          className={`up-chip${isActive(chip) ? ' active' : ''}`}
          aria-pressed={isActive(chip)}
          onClick={() => toggle(chip)}
        >
          <strong>{chip.value(summary) ?? 0}</strong> {chip.label}
        </button>
      ))}
    </div>
  )
}
