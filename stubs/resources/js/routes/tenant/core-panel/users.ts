import { action, callableAction } from '@/routes/_wayfinder'

export default {
    destroy: callableAction('delete'),
    edit: callableAction('get'),
    forceDelete: callableAction('delete'),
    index: action('get'),
    restore: callableAction('post'),
    show: callableAction('get'),
    store: action('post'),
    update: callableAction('put'),
}
