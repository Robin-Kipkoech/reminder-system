export function timeAgo(value) {
    if (!value) return ''
    const seconds = Math.round((Date.now() - new Date(value).getTime()) / 1000)
    if (seconds < 45) return 'just now'
    const units = [
        ['year', 31536000], ['month', 2592000], ['week', 604800],
        ['day', 86400], ['hour', 3600], ['minute', 60],
    ]
    for (const [name, size] of units) {
        const count = Math.floor(seconds / size)
        if (count >= 1) return `${count} ${name}${count === 1 ? '' : 's'} ago`
    }
    return 'just now'
}

export function nairobiGreeting() {
    const hour = Number(
        new Intl.DateTimeFormat('en-GB', {
            hour: 'numeric',
            hour12: false,
            timeZone: 'Africa/Nairobi',
        }).format(new Date()),
    )
    if (hour < 12) return 'Good morning'
    if (hour < 17) return 'Good afternoon'
    return 'Good evening'
}

export function nairobiToday() {
    return new Date().toLocaleDateString('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'Africa/Nairobi',
    })
}

export function dueText(days) {
    if (days == null) return ''
    if (days < 0) return `Overdue by ${-days} day${days === -1 ? '' : 's'}`
    if (days === 0) return 'Due today'
    if (days === 1) return 'Due tomorrow'
    return `In ${days} days`
}

export function dueTone(days) {
    if (days == null) return ''
    if (days < 0) return 'is-overdue'
    if (days <= 3) return 'is-soon'
    return ''
}
