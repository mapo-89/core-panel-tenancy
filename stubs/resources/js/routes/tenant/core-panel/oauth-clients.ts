import { action, callableAction } from '@/routes/_wayfinder'

export default {
    destroy: callableAction('delete'),
    store: action('post'),
    update: callableAction('put'),
}
