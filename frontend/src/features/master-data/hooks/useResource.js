import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

export function useResourceList(queryKey, api, params) {
    return useQuery({
        queryKey: [queryKey, 'list', params],
        queryFn: () => api.list(params),
        placeholderData: keepPreviousData,
    })
}

export function useResourceMutations(queryKey, api) {
    const queryClient = useQueryClient()
    // Invalidating [queryKey] also refreshes the dropdown "options" queries.
    const onSuccess = () => queryClient.invalidateQueries({ queryKey: [queryKey] })

    return {
        create: useMutation({ mutationFn: api.create, onSuccess }),
        update: useMutation({ mutationFn: ({ id, data }) => api.update(id, data), onSuccess }),
        toggle: useMutation({ mutationFn: ({ id, active }) => api.setActive(id, active), onSuccess }),
        remove: useMutation({ mutationFn: api.remove, onSuccess }),
        restore: useMutation({ mutationFn: api.restore, onSuccess }),
    }
}