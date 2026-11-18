import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { ChevronDown, ChevronUp, Pencil, Plus, Power, RotateCcw, Search, Trash2 } from 'lucide-react'
import Button from '../../../components/Button'
import Input from '../../../components/Input'
import Modal from '../../../components/Modal'
import ConfirmDialog from '../../../components/ConfirmDialog'
import { useToast } from '../../../components/Toast'
import { errorMessage } from '../../../lib/api'
import { unwrapList } from '../api/masterDataApi'
import { useResourceList, useResourceMutations } from '../hooks/useResource'

function plural(count, word) {
    return `${count} ${word}${count === 1 ? '' : 's'}`
}

// If the API reports how many open tasks / active items are attached, mention it.
function usageNote(meta) {
    const parts = []
    if (meta?.open_tasks) parts.push(plural(meta.open_tasks, 'open task'))
    if (meta?.active_items) parts.push(plural(meta.active_items, 'active item'))
    return parts.length ? ` It still has ${parts.join(' and ')}.` : ''
}

function toFormValues(row, defaults) {
    const values = {}
    for (const key of Object.keys(defaults)) {
        values[key] = typeof defaults[key] === 'boolean' ? Boolean(row[key]) : String(row[key] ?? '')
    }
    return values
}

function ResourceForm({ row, noun, fields, schema, defaults, toPayload, mutations, onClose }) {
    const toast = useToast()
    const isNew = !row
    const {
        register, handleSubmit, setError, formState: { errors, isSubmitting },
    } = useForm({
        resolver: zodResolver(schema),
        defaultValues: row ? toFormValues(row, defaults) : defaults,
    })

    async function onSubmit(values) {
        const cleaned = Object.fromEntries(
            Object.entries(values).map(([key, value]) => [key, value === '' ? null : value]),
        )
        const payload = toPayload(cleaned)
        try {
            if (isNew) await mutations.create.mutateAsync(payload)
            else await mutations.update.mutateAsync({ id: row.id, data: payload })
            toast.success(`${noun} ${isNew ? 'created' : 'updated'}.`)
            onClose()
        } catch (error) {
            const serverErrors = error.response?.data?.errors
            if (error.response?.status === 422 && serverErrors) {
                Object.entries(serverErrors).forEach(([name, messages]) =>
                    setError(name, { message: messages[0] }))
            } else {
                toast.error(errorMessage(error))
            }
        }
    }

    return (
        <form className="md-form" onSubmit={handleSubmit(onSubmit)} noValidate>
            {fields.map((field) => {
                const id = `field-${field.name}`
                if (field.type === 'checkbox') {
                    return (
                        <label key={field.name} className="login-remember">
                            <input type="checkbox" {...register(field.name)} />
                            <span>{field.label}</span>
                        </label>
                    )
                }
                if (field.type === 'textarea') {
                    return (
                        <div className="field" key={field.name}>
                            <label className="field-label" htmlFor={id}>{field.label}</label>
                            <div className={`field-control${errors[field.name] ? ' has-error' : ''}`}>
                                <textarea id={id} rows={3} className="field-input" {...register(field.name)} />
                            </div>
                            {errors[field.name] && <p className="field-error">{errors[field.name].message}</p>}
                        </div>
                    )
                }
                return (
                    <Input
                        key={field.name}
                        id={id}
                        label={field.label}
                        type={field.type ?? 'text'}
                        inputMode={field.inputMode}
                        placeholder={field.placeholder}
                        error={errors[field.name]?.message}
                        {...register(field.name)}
                    />
                )
            })}
            <div className="md-actions">
                <Button className="btn-secondary" onClick={onClose}>Cancel</Button>
                <Button type="submit" loading={isSubmitting}>{isNew ? `Create ${noun.toLowerCase()}` : 'Save changes'}</Button>
            </div>
        </form>
    )
}

export default function ResourcePage({
                                         title, description, noun, queryKey, api, columns, fields, schema, defaults,
                                         toPayload = (values) => values, defaultSort = 'name', supportsDeleted = true, canRestore = true,
                                     }) {
    const toast = useToast()
    const [params, setParams] = useSearchParams()
    const page = Number(params.get('page')) || 1
    const search = params.get('search') ?? ''
    const status = params.get('status') ?? 'all'
    const showDeleted = params.get('deleted') === '1'
    const sort = params.get('sort') ?? defaultSort
    const direction = params.get('direction') ?? 'asc'

    const [searchInput, setSearchInput] = useState(search)
    const [editing, setEditing] = useState(null) // null | 'new' | row
    const [confirm, setConfirm] = useState(null) // { type: 'delete' | 'deactivate', row }

    function setParam(changes) {
        setParams((previous) => {
            const next = new URLSearchParams(previous)
            Object.entries(changes).forEach(([key, value]) => {
                if (value === '' || value == null) next.delete(key)
                else next.set(key, value)
            })
            if (!('page' in changes)) next.delete('page')
            return next
        }, { replace: true })
    }

    useEffect(() => {
        const timer = setTimeout(() => {
            if (searchInput !== search) setParam({ search: searchInput })
        }, 300)
        return () => clearTimeout(timer)
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchInput])

    const { data, isLoading, isError, refetch } = useResourceList(queryKey, api, {
        page,
        per_page: 15,
        search: search || undefined,
        is_active: status === 'active' ? 1 : status === 'inactive' ? 0 : undefined,
        with_trashed: showDeleted ? 1 : undefined,
        sort,
        direction,
    })
    const { rows, lastPage, total } = unwrapList(data)
    const mutations = useResourceMutations(queryKey, api)

    function toggleSort(key) {
        if (sort === key) setParam({ sort: key, direction: direction === 'asc' ? 'desc' : 'asc', page })
        else setParam({ sort: key, direction: 'asc' })
    }

    async function run(promise, successMessage) {
        try {
            const body = await promise
            toast.success(successMessage + usageNote(body?.meta))
        } catch (error) {
            toast.error(errorMessage(error))
        }
    }

    function onToggle(row) {
        if (row.is_active) setConfirm({ type: 'deactivate', row })
        else run(mutations.toggle.mutateAsync({ id: row.id, active: true }), `${noun} activated.`)
    }

    async function onConfirm() {
        const { type, row } = confirm
        if (type === 'deactivate') {
            await run(mutations.toggle.mutateAsync({ id: row.id, active: false }), `${noun} deactivated.`)
        } else {
            await run(mutations.remove.mutateAsync(row.id), `${noun} deleted.`)
        }
        setConfirm(null)
    }

    const confirmBusy = mutations.toggle.isPending || mutations.remove.isPending
    const confirmName = confirm?.row?.name ?? confirm?.row?.label ?? ''

    return (
        <div className="md-page">
            <header className="md-page-header">
                <div>
                    <h1>{title}</h1>
                    <p>{description}</p>
                </div>
                <Button onClick={() => setEditing('new')}><Plus size={18} /> New {noun.toLowerCase()}</Button>
            </header>

            <div className="md-toolbar">
                <label className="md-search">
                    <Search size={17} aria-hidden="true" />
                    <input
                        type="search"
                        placeholder={`Search ${title.toLowerCase()}…`}
                        aria-label={`Search ${title.toLowerCase()}`}
                        value={searchInput}
                        onChange={(event) => setSearchInput(event.target.value)}
                    />
                </label>
                <select
                    className="md-select"
                    aria-label="Filter by status"
                    value={status}
                    onChange={(event) => setParam({ status: event.target.value === 'all' ? '' : event.target.value })}
                >
                    <option value="all">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                {supportsDeleted && (
                    <label className="login-remember">
                        <input
                            type="checkbox"
                            checked={showDeleted}
                            onChange={(event) => setParam({ deleted: event.target.checked ? '1' : '' })}
                        />
                        <span>Show deleted</span>
                    </label>
                )}
            </div>

            {isError ? (
                <div className="md-state">
                    <p>We couldn’t load {title.toLowerCase()}.</p>
                    <Button className="btn-secondary" onClick={() => refetch()}>Try again</Button>
                </div>
            ) : isLoading ? (
                <div className="md-skeletons" role="status" aria-label="Loading">
                    {Array.from({ length: 5 }, (_, index) => <div key={index} className="md-skeleton" />)}
                </div>
            ) : rows.length === 0 ? (
                <div className="md-state">
                    <p>No {title.toLowerCase()} found.</p>
                </div>
            ) : (
                <table className="md-table">
                    <thead>
                    <tr>
                        {columns.map((column) => (
                            <th
                                key={column.key}
                                aria-sort={sort === column.key ? (direction === 'asc' ? 'ascending' : 'descending') : undefined}
                            >
                                {column.sortable ? (
                                    <button type="button" className="md-sort" onClick={() => toggleSort(column.key)}>
                                        {column.label}
                                        {sort === column.key && (direction === 'asc' ? <ChevronUp size={14} /> : <ChevronDown size={14} />)}
                                    </button>
                                ) : column.label}
                            </th>
                        ))}
                        <th>Status</th>
                        <th><span className="sr-only">Actions</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    {rows.map((row) => {
                        const deleted = Boolean(row.deleted_at)
                        return (
                            <tr key={row.id}>
                                {columns.map((column) => (
                                    <td key={column.key} data-label={column.label}>
                                        {column.render ? column.render(row) : row[column.key] || '—'}
                                    </td>
                                ))}
                                <td data-label="Status">
                    <span className={`md-badge ${deleted ? 'deleted' : row.is_active ? 'active' : 'inactive'}`}>
                      {deleted ? 'Deleted' : row.is_active ? 'Active' : 'Inactive'}
                    </span>
                                </td>
                                <td data-label="Actions" className="md-row-actions">
                                    {deleted ? (
                                        canRestore && (
                                            <button
                                                type="button" className="md-icon-btn" title="Restore" aria-label={`Restore ${row.name ?? row.label}`}
                                                onClick={() => run(mutations.restore.mutateAsync(row.id), `${noun} restored.`)}
                                            >
                                                <RotateCcw size={17} />
                                            </button>
                                        )
                                    ) : (
                                        <>
                                            <button type="button" className="md-icon-btn" title="Edit" aria-label={`Edit ${row.name ?? row.label}`} onClick={() => setEditing(row)}>
                                                <Pencil size={17} />
                                            </button>
                                            <button
                                                type="button" className="md-icon-btn"
                                                title={row.is_active ? 'Deactivate' : 'Activate'}
                                                aria-label={`${row.is_active ? 'Deactivate' : 'Activate'} ${row.name ?? row.label}`}
                                                onClick={() => onToggle(row)}
                                            >
                                                <Power size={17} />
                                            </button>
                                            <button
                                                type="button" className="md-icon-btn md-icon-danger" title="Delete"
                                                aria-label={`Delete ${row.name ?? row.label}`}
                                                onClick={() => setConfirm({ type: 'delete', row })}
                                            >
                                                <Trash2 size={17} />
                                            </button>
                                        </>
                                    )}
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
                        <Button className="btn-secondary" disabled={page <= 1} onClick={() => setParam({ page: page - 1 })}>Previous</Button>
                        <Button className="btn-secondary" disabled={page >= lastPage} onClick={() => setParam({ page: page + 1 })}>Next</Button>
                    </div>
                </nav>
            )}

            <Modal
                open={editing !== null}
                title={editing === 'new' ? `New ${noun.toLowerCase()}` : `Edit ${noun.toLowerCase()}`}
                onClose={() => setEditing(null)}
            >
                {editing !== null && (
                    <ResourceForm
                        key={editing === 'new' ? 'new' : editing.id}
                        row={editing === 'new' ? null : editing}
                        noun={noun}
                        fields={fields}
                        schema={schema}
                        defaults={defaults}
                        toPayload={toPayload}
                        mutations={mutations}
                        onClose={() => setEditing(null)}
                    />
                )}
            </Modal>

            <ConfirmDialog
                open={confirm !== null}
                title={confirm?.type === 'delete' ? `Delete ${noun.toLowerCase()}?` : `Deactivate ${noun.toLowerCase()}?`}
                confirmLabel={confirm?.type === 'delete' ? 'Delete' : 'Deactivate'}
                danger={confirm?.type === 'delete'}
                busy={confirmBusy}
                onConfirm={onConfirm}
                onCancel={() => setConfirm(null)}
            >
                {confirm?.type === 'delete'
                    ? `“${confirmName}” will be removed from the system. Existing tasks and items keep their records.`
                    : `“${confirmName}” will no longer appear in dropdowns. Existing tasks and items are not affected.`}
            </ConfirmDialog>
        </div>
    )
}