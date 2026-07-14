import { action, callableAction } from '@/routes/_wayfinder'

export default {
    data: callableAction('get'),
    destroy: callableAction('delete'),
    dtApi: action('get'),
    edit: callableAction('get'),
    impersonate: callableAction('post'),
    index: action('get'),
    store: action('post'),
    update: callableAction('put'),
}
