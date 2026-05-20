import { action, callableAction } from '@/routes/_wayfinder'

export default {
    destroy: callableAction('delete'),
    index: action('get'),
    matrix: action('get'),
    permissions: {
        sync: callableAction('post'),
    },
    resync: action('post'),
    store: action('post'),
    update: callableAction('put'),
}
