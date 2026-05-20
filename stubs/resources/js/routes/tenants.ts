import { action, callableAction } from '@/routes/_wayfinder'

export const data = callableAction('get')
export const destroy = callableAction('delete')
export const dtApi = action('get')
export const edit = callableAction('get')
export const index = action('get')
export const store = action('post')
export const update = callableAction('put')

export default {
    data,
    destroy,
    dtApi,
    edit,
    index,
    store,
    update,
}
