import { useQuery } from '@tanstack/react-query'
import { clientsApi, departmentsApi, reminderPeriodsApi, unwrapOptions } from '../api/masterDataApi'

const STALE = 5 * 60 * 1000

const make = (key, api) => () =>
    useQuery({ queryKey: [key, 'options'], queryFn: api.options, staleTime: STALE, select: unwrapOptions })

export const useClientOptions = make('clients', clientsApi)
export const useDepartmentOptions = make('departments', departmentsApi)
export const useReminderPeriodOptions = make('reminder-periods', reminderPeriodsApi)