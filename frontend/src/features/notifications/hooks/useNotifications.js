import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { notificationsApi } from '../api/notificationsApi'

export function useUnreadCount() {
  return useQuery({
    queryKey: ['notifications', 'unread-count'],
    queryFn: notificationsApi.unreadCount,
    refetchInterval: 60 * 1000,
    refetchOnWindowFocus: true,
    staleTime: 15 * 1000,
  })
}

export function useNotificationList(params, enabled = true) {
  return useQuery({
    queryKey: ['notifications', 'list', params],
    queryFn: () => notificationsApi.list(params),
    enabled,
    placeholderData: keepPreviousData,
  })
}

export function useNotificationMutations() {
  const queryClient = useQueryClient()
  const onSuccess = () => queryClient.invalidateQueries({ queryKey: ['notifications'] })
  return {
    markRead: useMutation({ mutationFn: notificationsApi.markRead, onSuccess }),
    markAll: useMutation({ mutationFn: notificationsApi.markAllRead, onSuccess }),
    remove: useMutation({ mutationFn: notificationsApi.remove, onSuccess }),
  }
}
