import { useEffect, useState } from 'react'
import { Plus, Repeat, Search } from 'lucide-react'
import { useSearchParams } from 'react-router-dom'
import Button from '../../../components/Button'
import ConfirmDialog from '../../../components/ConfirmDialog'
import Modal from '../../../components/Modal'
import { useToast } from '../../../components/Toast'
import { errorMessage } from '../../../lib/api'
import { asArray } from '../api/tasksApi'
import { useClientOptions, useDepartmentOptions } from '../../master-data/hooks/useMasterOptions'
import TaskDetail from '../components/TaskDetail'
import TaskForm from '../componenets/TaskForm'
import { useTaskList, useTaskMutations } from '../hooks/useTasks'
import { STATUS_LABELS, dueLabel, formatDate } from '../utils'

export default function TasksPage() {
    const toast = useToast()
    const [search, setSearch] = useState('')
    const [debounced, setDebounced] = useState('')
    const [searchParams, setSearchParams] = useSearchParams()
    const [status, setStatus] = useState(searchParams.get('status') ?? '')
    const [clientId, setClientId] = useState('')
    const [departmentId, setDepartmentId] = useState('')
    const [mine, setMine] = useState(searchParams.get('mine') === '1')
    const [sort, setSort] = useState('due_date')
    const [direction, setDirection] = useState('asc')
    const [page, setPage] = useState(1)

    const [viewId, setViewId] = useState(null)
    const [form, setForm] = useState(null) // null | 'new' | task
    const [toDelete, setToDelete] = useState(null)

    const openParam = searchParams.get('open')
    useEffect(() => {
        if (!openParam) return
        setViewId(Number(openParam))
        const next = new URLSearchParams(searchParams)
        next.delete('open')
        setSearchParams(next, { replace: true })
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [openParam])

    const clients = useClientOptions().data ?? []
    const departments = useDepartmentOptions().data ?? []
    const { remove } = useTaskMutations()

    useEffect(() => {
        const timer = setTimeout(() => { setDebounced(search); setPage(1) }, 300)
        return () => clearTimeout(timer)
    }, [search])

    const { data, isLoading, isError, refetch } = useTaskList({
        page, per_page: 15, sort, direction,
        search: debounced || undefined,
        status: status || undefined,
        client_id: clientId || undefined,
        department_id: departmentId || undefined,
        mine: mine ? 1 : undefined,
    })
    const rows = asArray(data)
    const lastPage = data?.meta?.last_page ?? 1
    const total = data?.meta?.total ?? rows.length

    const filter = (setter) => (event) => { setter(event.target.value); setPage(1) }

    function toggleSort(key) {
        if (sort === key) setDirection(direction === 'asc' ? 'desc' : 'asc')
        else { setSort(key); setDirection('asc') }
    }

    async function confirmDelete() {
        try {
            await remove.mutateAsync(toDelete.id)
            toast.success('Task deleted.')
            setToDelete(null)
            setViewId(null)
        } catch (error) {
            toast.error(errorMessage(error))
        }
    }

    return (
        <div className="md-page">
            <header className="md-page-header">
                <div>
                    <h1>Tasks</h1>
                    <p>Work for your clients, with the people responsible and when they are reminded.</p>
                </div>
                <Button onClick={() => setForm('new')}><Plus size={18} /> New task</Button>
            </header>

            <div className="md-toolbar">
                <label className="md-search">
                    <Search size={17} aria-hidden="true" />
                    <input type="search" placeholder="Search tasks…" aria-label="Search tasks"
                           value={search} onChange={(event) => setSearch(event.target.value)} />
                </label>
                <select className="md-select" aria-label="Filter by status" value={status} onChange={filter(setStatus)}>
                    <option value="">All statuses</option>
                    <option value="open">Open</option>
                    <option value="in_progress">In progress</option>
                    <option value="overdue">Overdue</option>
                    <option value="done">Done</option>
                </select>
                <select className="md-select" aria-label="Filter by client" value={clientId} onChange={filter(setClientId)}>
                    <option value="">All clients</option>
                    {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
                <select className="md-select" aria-label="Filter by department" value={departmentId} onChange={filter(setDepartmentId)}>
                    <option value="">All departments</option>
                    {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                </select>
                <label className="login-remember">
                    <input type="checkbox" checked={mine} onChange={(event) => { setMine(event.target.checked); setPage(1) }} />
                    <span>My tasks</span>
                </label>
            </div>

            {isError ? (
                <div className="md-state">
                    <p>We couldn’t load tasks.</p>
                    <Button className="btn-secondary" onClick={() => refetch()}>Try again</Button>
                </div>
            ) : isLoading ? (
                <div className="md-skeletons" role="status" aria-label="Loading">
                    {Array.from({ length: 5 }, (_, i) => <div key={i} className="md-skeleton" />)}
                </div>
            ) : rows.length === 0 ? (
                <div className="md-state"><p>No tasks match these filters.</p></div>
            ) : (
                <table className="md-table tk-table">
                    <thead>
                    <tr>
                        <th aria-sort={sort === 'title' ? (direction === 'asc' ? 'ascending' : 'descending') : undefined}>
                            <button type="button" className="md-sort" onClick={() => toggleSort('title')}>Task</button>
                        </th>
                        <th aria-sort={sort === 'due_date' ? (direction === 'asc' ? 'ascending' : 'descending') : undefined}>
                            <button type="button" className="md-sort" onClick={() => toggleSort('due_date')}>Due</button>
                        </th>
                        <th>Responsible</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    {rows.map((task) => {
                        const overdue = task.is_overdue && task.status !== 'done'
                        return (
                            <tr key={task.id} className="tk-row" tabIndex={0}
                                onClick={() => setViewId(task.id)}
                                onKeyDown={(event) => { if (event.key === 'Enter') setViewId(task.id) }}>
                                <td data-label="Task">
                                    <div className="tk-title">
                                        {task.title}
                                        {task.is_recurring && <Repeat size={14} aria-label="Repeats" />}
                                    </div>
                                    <small className="tk-sub">{task.client?.name} · {task.department?.name}</small>
                                </td>
                                <td data-label="Due">
                                    <div>{formatDate(task.due_date)}</div>
                                    <small className={`tk-sub${overdue ? ' is-overdue' : ''}`}>{dueLabel(task)}</small>
                                </td>
                                <td data-label="Responsible">{task.assignees?.map((a) => a.name).join(', ') || '—'}</td>
                                <td data-label="Status">
                                    <span className={`md-badge tk-status-${task.status}`}>{STATUS_LABELS[task.status]}</span>
                                </td>
                            </tr>
                        )
                    })}
                    </tbody>
                </table>
            )}

            {!isLoading && !isError && rows.length > 0 && (
                <nav className="md-pagination" aria-label="Pagination">
                    <span>Page {page} of {lastPage} · {total} total</span>
                    <div>
                        <Button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
                        <Button className="btn-secondary" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>Next</Button>
                    </div>
                </nav>
            )}

            <Modal open={viewId !== null} title="Task details" size="lg" onClose={() => setViewId(null)}>
                {viewId !== null && (
                    <TaskDetail
                        id={viewId}
                        onEdit={(task) => { setViewId(null); setForm(task) }}
                        onDelete={setToDelete}
                    />
                )}
            </Modal>

            <Modal open={form !== null} title={form === 'new' ? 'New task' : 'Edit task'} size="lg" onClose={() => setForm(null)}>
                {form !== null && (
                    <TaskForm
                        key={form === 'new' ? 'new' : form.id}
                        task={form === 'new' ? null : form}
                        onClose={() => setForm(null)}
                    />
                )}
            </Modal>

            <ConfirmDialog
                open={toDelete !== null}
                title="Delete task?"
                confirmLabel="Delete"
                danger
                busy={remove.isPending}
                onConfirm={confirmDelete}
                onCancel={() => setToDelete(null)}
            >
                “{toDelete?.title}” will be removed from the task list.
            </ConfirmDialog>
        </div>
    )
}