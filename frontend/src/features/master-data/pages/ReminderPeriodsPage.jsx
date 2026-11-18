import { z } from 'zod'
import { reminderPeriodsApi } from '../api/masterDataApi'
import ResourcePage from '../components/ResourcePage'

export function describeOffset(days) {
    const n = Number(days)
    if (n === 0) return 'On the due day'
    return `${n} day${n === 1 ? '' : 's'} before`
}

const schema = z.object({
    label: z.string().trim().min(1, 'Label is required.').max(100, 'Label is too long.'),
    offset_days: z.string().trim()
        .regex(/^\d+$/, 'Enter a whole number (0 means the due day).')
        .refine((value) => Number(value) <= 365, 'Maximum is 365 days.'),
    is_active: z.boolean(),
})

const defaults = { label: '', offset_days: '', is_active: true }

const fields = [
    { name: 'label', label: 'Label', placeholder: 'e.g. 7 days before' },
    { name: 'offset_days', label: 'Days before the due date', inputMode: 'numeric', placeholder: '0 = on the due day' },
    { name: 'is_active', label: 'Active', type: 'checkbox' },
]

const columns = [
    { key: 'label', label: 'Label', sortable: true },
    { key: 'offset_days', label: 'When', sortable: true, render: (row) => describeOffset(row.offset_days) },
]

export default function ReminderPeriodsPage() {
    return (
        <ResourcePage
            title="Reminder periods"
            description="When reminders are sent relative to a due date. Used in the reminder dropdown on tasks and items."
            noun="Reminder period"
            queryKey="reminder-periods"
            api={reminderPeriodsApi}
            columns={columns}
            fields={fields}
            schema={schema}
            defaults={defaults}
            defaultSort="offset_days"
            supportsDeleted={false}
            canRestore={false}
            toPayload={(values) => ({ ...values, offset_days: Number(values.offset_days) })}
        />
    )
}