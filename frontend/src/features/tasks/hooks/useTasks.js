import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { asArray, tasksApi } from '../api/tasksApi'

export function useTaskList(params) {
    return useQuery({
        queryKey: ['tasks', 'list', params],
        queryFn: () => tasksApi.list(params),
        placeholderData: keepPreviousData,
    })
}

export function useTask(id) {
    return useQuery({
        queryKey: ['tasks', 'detail', id],
        queryFn: () => tasksApi.show(id),
        enabled: id != null,
        select: (body) => body?.data ?? body,
    })
}

export function useTaskMutations() {
    const queryClient = useQueryClient()
    const onSuccess = () => queryClient.invalidateQueries({ queryKey: ['tasks'] })
    return {
        create: useMutation({ mutationFn: tasksApi.create, onSuccess }),
        update: useMutation({ mutationFn: ({ id, data }) => tasksApi.update(id, data), onSuccess }),
        remove: useMutation({ mutationFn: tasksApi.remove, onSuccess }),
        setStatus: useMutation({ mutationFn: ({ id, status }) => tasksApi.setStatus(id, status), onSuccess }),
        complete: useMutation({ mutationFn: tasksApi.complete, onSuccess }),
    }
}

export function useComments(taskId) {
    return useQuery({
        queryKey: ['tasks', 'comments', taskId],
        queryFn: () => tasksApi.comments(taskId),
        enabled: taskId != null,
        select: asArray,
    })
}

export function useCommentMutations(taskId) {
    const queryClient = useQueryClient()
    const onSuccess = () => queryClient.invalidateQueries({ queryKey: ['tasks'] })
    return {
        add: useMutation({ mutationFn: (body) => tasksApi.addComment(taskId, body), onSuccess }),
        remove: useMutation({ mutationFn: (commentId) => tasksApi.removeComment(taskId, commentId), onSuccess }),
    }
}

export function useUserOptions() {
    return useQuery({
        queryKey: ['users', 'options'],
        queryFn: tasksApi.userOptions,
        staleTime: 5 * 60 * 1000,
        select: asArray,
    })
}