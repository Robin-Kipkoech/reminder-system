export const ITEM_STATUS_LABELS = { active: 'Active', renewed: 'Renewed', expired: 'Expired', cancelled: 'Cancelled' }

export function itemDueLabel(item) {
    if (item.status === 'cancelled') return 'Cancelled'
    const days = item.days_until_due
    if (days == null) return ''
    if (days < 0) return `Expired ${-days} day${days === -1 ? '' : 's'} ago`
    if (days === 0) return 'Expires today'
    return `Expires in ${days} day${days === 1 ? '' : 's'}`
}

export function itemRecurrenceLabel(item) {
    if (!item.is_recurring) return 'One-off'
    const n = item.recurrence_interval || 1
    switch (item.recurrence_type) {
        case 'monthly': return 'Renews monthly'
        case 'quarterly': return 'Renews quarterly'
        case 'yearly': return 'Renews yearly'
        case 'custom_days': return `Renews every ${n} day${n === 1 ? '' : 's'}`
        default: return 'Recurring'
    }
}