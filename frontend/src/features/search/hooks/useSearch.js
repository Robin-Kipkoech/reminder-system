import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { searchApi } from '../api/searchApi'

export function useSearch(q) {
  return useQuery({
    queryKey: ['search', q],
    queryFn: () => searchApi.search(q),
    enabled: q.length >= 2,
    staleTime: 15 * 1000,
    placeholderData: keepPreviousData,
  })
}