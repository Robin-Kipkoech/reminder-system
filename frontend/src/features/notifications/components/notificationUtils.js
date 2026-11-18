export function notificationPath(notification) {
  if (notification.source_type === 'task' && notification.source_id) return `/tasks?open=${notification.source_id}`
  if (notification.source_type === 'item' && notification.source_id) return `/items?open=${notification.source_id}`
  return null
}
