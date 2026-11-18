import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { CalendarClock, ClipboardList, Plus, Search } from 'lucide-react'
import { useAuth } from '../../auth/useAuth'
import Modal from '../../components/Modal'
import CommandPalette from '../search/components/CommandPalette'
import ItemForm from '../items/components/ItemForm'
import TaskForm from '../tasks/componenets/TaskForm'

const QuickContext = createContext(null)

export function useQuickActions() {
  const value = useContext(QuickContext)
  if (!value) throw new Error('useQuickActions must be used within QuickActionsProvider')
  return value
}

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/i.test(navigator.platform || navigator.userAgent)

export function QuickActionsProvider({ children }) {
  const { user } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [paletteOpen, setPaletteOpen] = useState(false)
  const [creating, setCreating] = useState(null) // null | 'task' | 'item'

  const canManage = user?.role === 'admin' || user?.role === 'manager'
  const isAdmin = user?.role === 'admin'

  const refresh = useCallback(() => {
    for (const key of ['dashboard', 'calendar', 'tasks', 'items']) {
      queryClient.invalidateQueries({ queryKey: [key] })
    }
  }, [queryClient])

  const closeCreate = useCallback(() => {
    setCreating(null)
    refresh()
  }, [refresh])

  // Ctrl+K / Cmd+K toggles the palette (ignored while another dialog is open).
  useEffect(() => {
    function onKeyDown(event) {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        setPaletteOpen((open) => {
          if (!open && document.querySelector('dialog[open]')) return open
          return !open
        })
      }
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [])

  function selectResult(group, row) {
    const paths = {
      tasks: `/tasks?open=${row.id}`,
      items: `/items?open=${row.id}`,
      clients: `/clients/${row.id}`,
      users: `/admin/users/${row.id}`,
    }
    setPaletteOpen(false)
    if (paths[group]) navigate(paths[group])
  }

  const value = useMemo(
    () => ({
      canManage,
      openSearch: () => setPaletteOpen(true),
      openNew: (kind) => setCreating(kind),
    }),
    [canManage],
  )

  return (
    <QuickContext.Provider value={value}>
      {children}

      <CommandPalette
        open={paletteOpen}
        onClose={() => setPaletteOpen(false)}
        onSelect={selectResult}
        canOpenUsers={isAdmin}
      />

      <Modal open={creating === 'task'} title="New task" size="lg" onClose={closeCreate}>
        {creating === 'task' && <TaskForm task={null} onClose={closeCreate} />}
      </Modal>
      <Modal open={creating === 'item'} title="New item" size="lg" onClose={closeCreate}>
        {creating === 'item' && <ItemForm item={null} onClose={closeCreate} />}
      </Modal>
    </QuickContext.Provider>
  )
}

/* ---------- Top bar buttons ---------- */
export function SearchButton() {
  const { openSearch } = useQuickActions()
  return (
    <button
      type="button"
      className="qa-search"
      onClick={openSearch}
      aria-label="Search"
      aria-keyshortcuts="Control+K Meta+K"
    >
      <Search size={17} aria-hidden="true" />
      <span className="qa-search-text">Search…</span>
      <kbd className="qa-kbd">{isMac ? '⌘K' : 'Ctrl K'}</kbd>
    </button>
  )
}

export function QuickAddButton() {
  const { canManage, openNew } = useQuickActions()
  const [open, setOpen] = useState(false)
  const rootRef = useRef(null)
  const buttonRef = useRef(null)

  useEffect(() => {
    if (!open) return undefined
    function onPointerDown(event) {
      if (rootRef.current && !rootRef.current.contains(event.target)) setOpen(false)
    }
    function onKeyDown(event) {
      if (event.key === 'Escape') {
        setOpen(false)
        buttonRef.current?.focus()
      }
    }
    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  if (!canManage) {
    return (
      <button type="button" className="qa-add" aria-label="New task" title="New task" onClick={() => openNew('task')}>
        <Plus size={18} aria-hidden="true" />
        <span className="qa-add-text">New</span>
      </button>
    )
  }

  return (
    <div className="qa-menu-wrap" ref={rootRef}>
      <button
        ref={buttonRef}
        type="button"
        className="qa-add"
        aria-label="Create new"
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
      >
        <Plus size={18} aria-hidden="true" />
        <span className="qa-add-text">New</span>
      </button>
      {open && (
        <div className="qa-menu" role="menu">
          <button type="button" role="menuitem" onClick={() => { setOpen(false); openNew('task') }}>
            <ClipboardList size={16} aria-hidden="true" /> New task
          </button>
          <button type="button" role="menuitem" onClick={() => { setOpen(false); openNew('item') }}>
            <CalendarClock size={16} aria-hidden="true" /> New item
          </button>
        </div>
      )}
    </div>
  )
}