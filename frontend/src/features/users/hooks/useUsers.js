import { useQuery } from '@tanstack/react-query'
import { fetchUserOptions, listUsers } from '../api/usersApi'

export function useUsers(params) {
  return useQuery({
    queryKey: ['users', params],
    queryFn: () => listUsers(params),
    placeholderData: (previousData) => previousData,
  })
}

export function useUserOptions(search = '') {
  return useQuery({
    queryKey: ['user-options', search.trim()],
    queryFn: () => fetchUserOptions(search),
    staleTime: 30_000,
  })
}
