import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { emailSettingsApi } from '../api/emailSettingsApi'

export function useEmailSettings() {
  return useQuery({ queryKey: ['email-settings'], queryFn: emailSettingsApi.get })
}

export function useSaveEmailSettings() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: emailSettingsApi.save,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['email-settings'] }),
  })
}

export function useSendTestEmail() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: emailSettingsApi.test,
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['email-settings'] }),
  })
}

export function useEmailLogs(params) {
  return useQuery({
    queryKey: ['email-settings', 'logs', params],
    queryFn: () => emailSettingsApi.logs(params),
    placeholderData: keepPreviousData,
  })
}