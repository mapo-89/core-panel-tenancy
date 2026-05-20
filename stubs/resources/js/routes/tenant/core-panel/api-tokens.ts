import { action, callableAction } from '@/routes/_wayfinder'

export default {
    destroy: callableAction('delete'),
    index: action('get'),
    store: action('post'),
}
