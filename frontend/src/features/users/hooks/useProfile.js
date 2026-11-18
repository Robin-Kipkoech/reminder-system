import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { profileApi } from '../api/profileApi'

export function useUserSummary() {
  return useQuery({
    queryKey: ['users', 'summary'],
    queryFn: profileApi.summary,
    staleTime: 30 * 1000,
  })
}

export function useUserProfile(id) {
  return useQuery({
    queryKey: ['users', 'profile', id],
    queryFn: () => profileApi.userProfile(id),
    enabled: id != null,
  })
}

export function usePreferences() {
  return useQuery({ queryKey: ['auth', 'preferences'], queryFn: profileApi.preferences })
}

export function useSavePreferences() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: profileApi.updatePreferences,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['auth', 'preferences'] }),
  })
}
