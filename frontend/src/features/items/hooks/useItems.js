import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { itemsApi } from '../api/itemsApi'

export function useItemList(params) {
    return useQuery({
        queryKey: ['items', 'list', params],
        queryFn: () => itemsApi.list(params),
        placeholderData: keepPreviousData,
    })
}

export function useItem(id) {
    return useQuery({
        queryKey: ['items', 'detail', id],
        queryFn: () => itemsApi.show(id),
        enabled: id != null,
        select: (body) => body?.data ?? body,
    })
}

export function useItemMutations() {
    const queryClient = useQueryClient()
    const onSuccess = () => queryClient.invalidateQueries({ queryKey: ['items'] })
    return {
        create: useMutation({ mutationFn: itemsApi.create, onSuccess }),
        update: useMutation({ mutationFn: ({ id, data }) => itemsApi.update(id, data), onSuccess }),
        remove: useMutation({ mutationFn: itemsApi.remove, onSuccess }),
        renew: useMutation({ mutationFn: ({ id, dueDate }) => itemsApi.renew(id, dueDate), onSuccess }),
        setStatus: useMutation({ mutationFn: ({ id, status }) => itemsApi.setStatus(id, status), onSuccess }),
    }
}