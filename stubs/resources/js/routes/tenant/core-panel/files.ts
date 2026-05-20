import { action, callableAction } from '@/routes/_wayfinder'

export default {
    destroy: callableAction('delete'),
    download: callableAction('get'),
    index: action('get'),
    preview: callableAction('get'),
    store: action('post'),
}
