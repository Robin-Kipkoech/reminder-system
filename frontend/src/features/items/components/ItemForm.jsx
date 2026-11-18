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
import MultiPick from '../../tasks/componenets/MultiPick'
import { useUserOptions } from '../../tasks/hooks/useTasks'
import { offsetLabel } from '../../tasks/utils'
import { useItemMutations } from '../hooks/useItems'

const schema = z.object({
  title: z.string().trim().min(1, 'Item name is required.').max(255, 'Name is too long.'),
  description: z.string().trim().max(5000),
  client_id: z.string().min(1, 'Choose a client.'),
  department_ids: z.array(z.number()).min(1, 'Choose at least one department.'),
  owner_ids: z.array(z.number()).min(1, 'Choose at least one person in charge.'),
  due_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Choose a due date.'),
  reminder_period_ids: z.array(z.number()),
  is_recurring: z.boolean(),
  recurrence_type: z.string(),
  recurrence_interval: z.string(),
}).superRefine((values, ctx) => {
  if (!values.is_recurring) return
  if (!values.recurrence_type) {
    ctx.addIssue({ code: 'custom', path: ['recurrence_type'], message: 'Choose how often it renews.' })
  }
  if (values.recurrence_type === 'custom_days') {
    const n = Number(values.recurrence_interval)
    if (!Number.isInteger(n) || n < 1 || n > 365) {
      ctx.addIssue({ code: 'custom', path: ['recurrence_interval'], message: 'Enter 1 to 365 days.' })
    }
  }
})

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

const merge = (list, extra = []) => {
  const out = [...list]
  for (const item of extra) if (!out.some((o) => o.id === item.id)) out.push(item)
  return out
}

export default function ItemForm({ item, onClose }) {
  const toast = useToast()
  const { user } = useAuth()
  const isNew = !item
  const { create, update } = useItemMutations()

  const clients = useClientOptions().data ?? []
  const departments = useDepartmentOptions().data ?? []
  const periods = useReminderPeriodOptions().data ?? []
  const users = useUserOptions().data ?? []

  const {
    register, handleSubmit, setError, watch, setValue, formState: { errors, isSubmitting },
  } = useForm({
    resolver: zodResolver(schema),
    defaultValues: item
      ? {
          title: item.title,
          description: item.description ?? '',
          client_id: String(item.client?.id ?? ''),
          department_ids: item.departments.map((d) => d.id),
          owner_ids: item.owners.map((o) => o.id),
          due_date: String(item.due_date).slice(0, 10),
          reminder_period_ids: item.reminder_periods.map((p) => p.id),
          is_recurring: Boolean(item.is_recurring),
          recurrence_type: item.recurrence_type ?? '',
          recurrence_interval: String(item.recurrence_interval ?? 1),
        }
      : {
          title: '', description: '', client_id: '', department_ids: [],
          owner_ids: user ? [user.id] : [], due_date: '', reminder_period_ids: [],
          is_recurring: false, recurrence_type: '', recurrence_interval: '1',
        },
  })

  const isRecurring = watch('is_recurring')
  const recurrenceType = watch('recurrence_type')
  const departmentIds = watch('department_ids')
  const ownerIds = watch('owner_ids')
  const reminderIds = watch('reminder_period_ids')

  const clientOptions = useMemo(
    () => merge(clients, item?.client ? [item.client] : []).map((c) => ({ value: String(c.id), label: c.name })),
    [clients, item],
  )
  const departmentOptions = useMemo(
    () => merge(departments, item?.departments).map((d) => ({ id: d.id, label: d.name })),
    [departments, item],
  )
  const ownerOptions = useMemo(
    () => merge(users, item?.owners).map((u) => ({ id: u.id, label: u.name, note: u.role })),
    [users, item],
  )
  const reminderOptions = useMemo(
    () => merge(periods, item?.reminder_periods)
      .map((p) => ({ id: p.id, label: offsetLabel(p), sort: p.offset_days ?? 0 }))
      .sort((a, b) => b.sort - a.sort),
    [periods, item],
  )

  async function onSubmit(values) {
    const payload = {
      title: values.title,
      description: values.description || null,
      client_id: Number(values.client_id),
      department_ids: values.department_ids,
      owner_ids: values.owner_ids,
      due_date: values.due_date,
      reminder_period_ids: values.reminder_period_ids,
      is_recurring: values.is_recurring,
      recurrence_type: values.is_recurring ? values.recurrence_type : null,
      recurrence_interval: values.is_recurring
        ? (values.recurrence_type === 'custom_days' ? Number(values.recurrence_interval) : 1)
        : null,
    }
    try {
      if (isNew) await create.mutateAsync(payload)
      else await update.mutateAsync({ id: item.id, data: payload })
      toast.success(isNew ? 'Item created.' : 'Item updated.')
      onClose()
    } catch (error) {
      const serverErrors = error.response?.data?.errors
      if (error.response?.status === 422 && serverErrors) {
        Object.entries(serverErrors).forEach(([name, messages]) =>
          setError(name.replace(/\.\d+$/, ''), { message: messages[0] }))
      } else {
        toast.error(errorMessage(error))
      }
    }
  }

  const setList = (name) => (ids) => setValue(name, ids, { shouldValidate: true, shouldDirty: true })

  return (
    <form className="md-form" onSubmit={handleSubmit(onSubmit)} noValidate>
      <Input id="item-title" label="Item" placeholder="e.g. Domain renewal, SSL certificate, Software licence"
        error={errors.title?.message} {...register('title')} />

      <div className="field">
        <label className="field-label" htmlFor="item-description">Description</label>
        <div className={`field-control${errors.description ? ' has-error' : ''}`}>
          <textarea id="item-description" rows={3} className="field-input" {...register('description')} />
        </div>
        {errors.description && <p className="field-error">{errors.description.message}</p>}
      </div>

      <div className="tk-grid-2">
        <SelectField id="item-client" label="Client" placeholder="Select a client…" options={clientOptions}
          error={errors.client_id?.message} {...register('client_id')} />
        <Input id="item-due" label="Due / expiry date" type="date" error={errors.due_date?.message} {...register('due_date')} />
      </div>

      <MultiPick label="Departments" options={departmentOptions} value={departmentIds}
        onChange={setList('department_ids')} error={errors.department_ids?.message}
        hint="An item can belong to more than one department." />

      <MultiPick label="People in charge" searchable options={ownerOptions} value={ownerIds}
        onChange={setList('owner_ids')} error={errors.owner_ids?.message}
        hint="These people receive the reminders." />

      <MultiPick label="Send reminders" options={reminderOptions} value={reminderIds}
        onChange={(ids) => setValue('reminder_period_ids', ids, { shouldDirty: true })}
        error={errors.reminder_period_ids?.message}
        hint="Choose when reminders go out before the due date." />

      <label className="login-remember">
        <input type="checkbox" {...register('is_recurring')} />
        <span>This item renews regularly</span>
      </label>

      {isRecurring && (
        <div className="tk-grid-2">
          <SelectField id="item-recurrence" label="Renews" placeholder="Choose…"
            options={[
              { value: 'monthly', label: 'Monthly' },
              { value: 'quarterly', label: 'Quarterly' },
              { value: 'yearly', label: 'Yearly' },
              { value: 'custom_days', label: 'Every N days' },
            ]}
            error={errors.recurrence_type?.message} {...register('recurrence_type')} />
          {recurrenceType === 'custom_days' && (
            <Input id="item-interval" label="Every how many days?" inputMode="numeric"
              error={errors.recurrence_interval?.message} {...register('recurrence_interval')} />
          )}
        </div>
      )}

      <div className="md-actions">
        <Button className="btn-secondary" onClick={onClose}>Cancel</Button>
        <Button type="submit" loading={isSubmitting}>{isNew ? 'Create item' : 'Save changes'}</Button>
      </div>
    </form>
  )
}