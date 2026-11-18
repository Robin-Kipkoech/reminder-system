import { useEffect, useMemo, useRef, useState } from 'react'
import { Building2, CalendarClock, ClipboardList, Search, Users } from 'lucide-react'
import { useDebounce } from '../../../hooks/useDebounce'
import { formatDate } from '../../tasks/utils'
import { useSearch } from '../hooks/useSearch'

const dueSub = (row) => [row.client_name, row.due_date ? formatDate(row.due_date) : null].filter(Boolean).join(' · ')

const GROUPS = [
  { key: 'tasks', label: 'Tasks', icon: ClipboardList, text: (r) => r.title, sub: dueSub },
  { key: 'items', label: 'Items', icon: CalendarClock, text: (r) => r.title, sub: dueSub },
  { key: 'clients', label: 'Clients', icon: Building2, text: (r) => r.name, sub: () => '' },
  { key: 'users', label: 'People', icon: Users, text: (r) => r.name, sub: (r) => r.role ?? '', adminOnly: true },
]

function Palette({ onSelect, canOpenUsers }) {
  const [query, setQuery] = useState('')
  const [active, setActive] = useState(0)
  const trimmed = query.trim()
  const debounced = useDebounce(trimmed, 250)
  const ready = debounced.length >= 2
  const { data, isFetching, isError } = useSearch(debounced)

  const sections = useMemo(() => {
    if (!ready || !data) return []
    const visible = GROUPS
      .filter((group) => !group.adminOnly || canOpenUsers)
      .map((group) => ({ ...group, rows: data[group.key] ?? [] }))
      .filter((section) => section.rows.length > 0)

    return visible.reduce((acc, section) => {
      const previous = acc[acc.length - 1]
      const start = previous ? previous.start + previous.rows.length : 0
      acc.push({ ...section, start })
      return acc
    }, [])
  }, [data, ready, canOpenUsers])

  const flat = useMemo(
    () => sections.flatMap((section) => section.rows.map((row) => ({ group: section.key, row }))),
    [sections],
  )
  const current = flat.length ? Math.min(active, flat.length - 1) : -1
  const pending = trimmed.length >= 2 && (debounced !== trimmed || isFetching)

  useEffect(() => {
    if (current >= 0) document.getElementById(`cp-opt-${current}`)?.scrollIntoView({ block: 'nearest' })
  }, [current])

  function onKeyDown(event) {
    if (event.key === 'ArrowDown' && flat.length) {
      event.preventDefault()
      setActive((current + 1) % flat.length)
    } else if (event.key === 'ArrowUp' && flat.length) {
      event.preventDefault()
      setActive((current - 1 + flat.length) % flat.length)
    } else if (event.key === 'Enter' && current >= 0) {
      event.preventDefault()
      onSelect(flat[current].group, flat[current].row)
    }
  }

  return (
    <div className="cp-body">
      <div className="cp-input-row">
        <Search size={18} aria-hidden="true" />
        <input
          autoFocus
          className="cp-input"
          type="text"
          placeholder="Search tasks, items, clients and people…"
          value={query}
          onChange={(event) => { setQuery(event.target.value); setActive(0) }}
          onKeyDown={onKeyDown}
          role="combobox"
          aria-label="Search"
          aria-expanded={flat.length > 0}
          aria-controls="cp-list"
          aria-activedescendant={current >= 0 ? `cp-opt-${current}` : undefined}
          aria-autocomplete="list"
          autoComplete="off"
          spellCheck={false}
        />
        <kbd>Esc</kbd>
      </div>

      <div className="cp-list" id="cp-list" role="listbox" aria-label="Search results">
        {trimmed.length < 2 && <p className="cp-state">Type at least 2 characters to search.</p>}
        {trimmed.length >= 2 && isError && !flat.length && <p className="cp-state">Search failed. Please try again.</p>}
        {trimmed.length >= 2 && !isError && pending && !flat.length && <p className="cp-state">Searching…</p>}
        {trimmed.length >= 2 && !isError && !pending && !flat.length && (
          <p className="cp-state">No results for “{debounced}”.</p>
        )}

        {sections.map((section) => {
          const Icon = section.icon
          return (
            <div key={section.key} role="group" aria-labelledby={`cp-group-${section.key}`}>
              <div className="cp-group-title" id={`cp-group-${section.key}`}>{section.label}</div>
              {section.rows.map((row, index) => {
                const position = section.start + index
                const sub = section.sub(row)
                return (
                  <div
                    key={`${section.key}-${row.id}`}
                    id={`cp-opt-${position}`}
                    role="option"
                    aria-selected={position === current}
                    className={`cp-option${position === current ? ' active' : ''}`}
                    onMouseEnter={() => setActive(position)}
                    onClick={() => onSelect(section.key, row)}
                  >
                    <span className="cp-icon"><Icon size={16} aria-hidden="true" /></span>
                    <span className="cp-text">
                      <strong>{section.text(row)}</strong>
                      {sub && <small>{sub}</small>}
                    </span>
                  </div>
                )
              })}
            </div>
          )
        })}
      </div>

      <div className="sr-only" aria-live="polite">{flat.length ? `${flat.length} results` : ''}</div>
      <footer className="cp-footer">
        <span><kbd>↑</kbd> <kbd>↓</kbd> navigate</span>
        <span><kbd>Enter</kbd> open</span>
        <span><kbd>Esc</kbd> close</span>
      </footer>
    </div>
  )
}

export default function CommandPalette({ open, onClose, onSelect, canOpenUsers }) {
  const ref = useRef(null)

  useEffect(() => {
    const dialog = ref.current
    if (!dialog) return
    if (open && !dialog.open) dialog.showModal()
    if (!open && dialog.open) dialog.close()
  }, [open])

  return (
    <dialog
      ref={ref}
      className="cp-dialog"
      aria-label="Search"
      onClose={onClose}
      onClick={(event) => { if (event.target === ref.current) onClose() }}
    >
      {open && <Palette onSelect={onSelect} canOpenUsers={canOpenUsers} />}
    </dialog>
  )
}