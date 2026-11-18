import { useMemo } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import Button from '../../../components/Button'
import Input from '../../../components/Input'
import { useToast } from '../../../components/Toast'
import { useAuth } from '../../../auth/useAuth'
import { errorMessage } from '../../../lib/api'
import {
    useClientOptions, useDepartmentOptions, useReminderPeriodOptions,
} from '../../master-data/hooks/useMasterOptions'
import { useTaskMutations, useUserOptions } from '../hooks/useTasks'
import { offsetLabel } from '../utils'
import MultiPick from './MultiPick'

const schema = z.object({
    title: z.string().trim().min(1, 'Task title is required.').max(255, 'Title is too long.'),
    description: z.string().trim().max(5000),
    department_id: z.string().min(1, 'Choose a department.'),
    client_id: z.string().min(1, 'Choose a client.'),
    due_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Choose a due date.'),
    visibility: z.enum(['everyone', 'involved_only']),
    assignee_ids: z.array(z.number()).min(1, 'Assign at least one person.'),
    reminder_period_ids: z.array(z.number()),
    is_recurring: z.boolean(),
    recurrence_type: z.string(),
    recurrence_interval: z.string(),
}).superRefine((values, ctx) => {
    if (!values.is_recurring) return
    if (!values.recurrence_type) {
        ctx.addIssue({ code: 'custom', path: ['recurrence_type'], message: 'Choose how often it repeats.' })
    }
    if (values.recurrence_type === 'custom_days') {
        const n = Number(values.recurrence_interval)
        if (!Number.isInteger(n) || n < 1 || n > 365) {
            ctx.addIssue({ code: 'custom', path: ['recurrence_interval'], message: 'Enter 1 to 365 days.' })
        }
    }
})

function toLocalInputDate(date = new Date()) {
    const pad = (n) => String(n).padStart(2, '0')
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

function SelectField({ id, label, options, error, placeholder, ...props }) {
    return (
        <div className="field">
            <label className="field-label" htmlFor={id}>{label}</label>
            <div className={`field-control${error ? ' has-error' : ''}`}>
                <select id={id} className="field-input" aria-invalid={error ? 'true' : 'false'} {...props}>
                    {placeholder && <option value="">{placeholder}</option>}
                    {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
            </div>
            {error && <p className="field-error">{error}</p>}
        </div>
    )
}

const toOptions = (list) => list.map((item) => ({ value: String(item.id), label: item.name }))

// Keep an inactive record selectable when editing a task that already uses it.
function withCurrent(options, current) {
    if (!current || options.some((o) => String(o.id) === String(current.id))) return options
    return [...options, current]
}

export default function TaskForm({ task, onClose, onSaved }) {
    const toast = useToast()
    const { user } = useAuth()
    const isNew = !task
    const { create, update } = useTaskMutations()

    const clients = useClientOptions().data ?? []
    const departments = useDepartmentOptions().data ?? []
    const periods = useReminderPeriodOptions().data ?? []
    const users = useUserOptions().data ?? []

    const {
        register, handleSubmit, setError, watch, setValue, formState: { errors, isSubmitting },
    } = useForm({
        resolver: zodResolver(schema),
        defaultValues: task
            ? {
                title: task.title,
                description: task.description ?? '',
                department_id: String(task.department?.id ?? ''),
                client_id: String(task.client?.id ?? ''),
                due_date: String(task.due_date).slice(0, 10),
                visibility: task.visibility,
                assignee_ids: task.assignees.map((a) => a.id),
                reminder_period_ids: task.reminder_periods.map((p) => p.id),
                is_recurring: Boolean(task.is_recurring),
                recurrence_type: task.recurrence_type ?? '',
                recurrence_interval: String(task.recurrence_interval ?? 1),
            }
            : {
                title: '', description: '', department_id: '', client_id: '',
                due_date: toLocalInputDate(), visibility: 'everyone',
                assignee_ids: user ? [user.id] : [], reminder_period_ids: [],
                is_recurring: false, recurrence_type: '', recurrence_interval: '1',
            },
    })

    const isRecurring = watch('is_recurring')
    const recurrenceType = watch('recurrence_type')
    const assigneeIds = watch('assignee_ids')
    const reminderIds = watch('reminder_period_ids')

    const clientOptions = useMemo(
        () => toOptions(withCurrent(clients, task?.client)), [clients, task],
    )
    const departmentOptions = useMemo(
        () => toOptions(withCurrent(departments, task?.department)), [departments, task],
    )
    const reminderOptions = useMemo(() => {
        const merged = withCurrent(periods, null)
        const extra = (task?.reminder_periods ?? []).filter((p) => !merged.some((m) => m.id === p.id))
        return [...merged, ...extra]
            .map((p) => ({ id: p.id, label: offsetLabel(p), sort: p.offset_days ?? 0 }))
            .sort((a, b) => b.sort - a.sort)
    }, [periods, task])
    const peopleOptions = useMemo(() => {
        const merged = [...users]
        for (const a of task?.assignees ?? []) if (!merged.some((u) => u.id === a.id)) merged.push(a)
        return merged.map((u) => ({ id: u.id, label: u.name, note: u.role }))
    }, [users, task])

    async function onSubmit(values) {
        const payload = {
            title: values.title,
            description: values.description || null,
            department_id: Number(values.department_id),
            client_id: Number(values.client_id),
            due_date: values.due_date,
            visibility: values.visibility,
            assignee_ids: values.assignee_ids,
            reminder_period_ids: values.reminder_period_ids,
            is_recurring: values.is_recurring,
            recurrence_type: values.is_recurring ? values.recurrence_type : null,
            recurrence_interval: values.is_recurring
                ? (values.recurrence_type === 'custom_days' ? Number(values.recurrence_interval) : 1)
                : null,
        }
        try {
            if (isNew) await create.mutateAsync(payload)
            else await update.mutateAsync({ id: task.id, data: payload })
            toast.success(isNew ? 'Task created.' : 'Task updated.')
            onSaved?.()
            onClose()
        } catch (error) {
            const serverErrors = error.response?.data?.errors
            if (error.response?.status === 422 && serverErrors) {
                Object.entries(serverErrors).forEach(([name, messages]) => {
                    const field = name.replace(/\.\d+$/, '')
                    setError(field, { message: messages[0] })
                })
            } else {
                toast.error(errorMessage(error))
            }
        }
    }

    return (
        <form className="md-form" onSubmit={handleSubmit(onSubmit)} noValidate>
            <Input id="task-title" label="Task" placeholder="What needs to be done?" error={errors.title?.message} {...register('title')} />

            <div className="field">
                <label className="field-label" htmlFor="task-description">Description</label>
                <div className={`field-control${errors.description ? ' has-error' : ''}`}>
                    <textarea id="task-description" rows={3} className="field-input" {...register('description')} />
                </div>
                {errors.description && <p className="field-error">{errors.description.message}</p>}
            </div>

            <div className="tk-grid-2">
                <SelectField id="task-client" label="Client" placeholder="Select a client…" options={clientOptions}
                             error={errors.client_id?.message} {...register('client_id')} />
                <SelectField id="task-department" label="Department" placeholder="Select a department…" options={departmentOptions}
                             error={errors.department_id?.message} {...register('department_id')} />
            </div>

            <div className="tk-grid-2">
                <Input id="task-due" label="Due date" type="date" error={errors.due_date?.message} {...register('due_date')} />
                <SelectField id="task-visibility" label="Who can see this task?"
                             options={[
                                 { value: 'everyone', label: 'Everyone' },
                                 { value: 'involved_only', label: 'Only the people involved' },
                             ]}
                             {...register('visibility')} />
            </div>

            <MultiPick
                label="Responsible people"
                searchable
                options={peopleOptions}
                value={assigneeIds}
                onChange={(ids) => setValue('assignee_ids', ids, { shouldValidate: true, shouldDirty: true })}
                error={errors.assignee_ids?.message}
            />

            <MultiPick
                label="Send reminders"
                hint="Choose when people are reminded before this task is due."
                options={reminderOptions}
                value={reminderIds}
                onChange={(ids) => setValue('reminder_period_ids', ids, { shouldDirty: true })}
                error={errors.reminder_period_ids?.message}
            />

            <label className="login-remember">
                <input type="checkbox" {...register('is_recurring')} />
                <span>This task repeats</span>
            </label>

            {isRecurring && (
                <div className="tk-grid-2">
                    <SelectField id="task-recurrence" label="Repeats" placeholder="Choose…"
                                 options={[
                                     { value: 'daily', label: 'Daily' },
                                     { value: 'weekly', label: 'Weekly' },
                                     { value: 'monthly', label: 'Monthly' },
                                     { value: 'yearly', label: 'Yearly' },
                                     { value: 'custom_days', label: 'Every N days' },
                                 ]}
                                 error={errors.recurrence_type?.message} {...register('recurrence_type')} />
                    {recurrenceType === 'custom_days' && (
                        <Input id="task-interval" label="Every how many days?" inputMode="numeric"
                               error={errors.recurrence_interval?.message} {...register('recurrence_interval')} />
                    )}
                </div>
            )}

            <div className="md-actions">
                <Button className="btn-secondary" onClick={onClose}>Cancel</Button>
                <Button type="submit" loading={isSubmitting}>{isNew ? 'Create task' : 'Save changes'}</Button>
            </div>
        </form>
    )
}
