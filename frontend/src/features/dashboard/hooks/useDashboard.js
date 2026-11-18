import { useQuery } from '@tanstack/react-query'
import { dashboardApi } from '../api/dashboardApi'

export function useDashboard() {
    return useQuery({
        queryKey: ['dashboard'],
        queryFn: dashboardApi.get,
        staleTime: 30 * 1000,
        refetchInterval: 2 * 60 * 1000,
    })
}
