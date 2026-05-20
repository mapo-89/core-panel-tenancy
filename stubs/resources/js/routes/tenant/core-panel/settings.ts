import { action, callableAction } from '@/routes/_wayfinder'

export default {
    index: action('get'),
    logo: {
        destroy: callableAction('delete'),
        store: callableAction('post'),
    },
    styles: callableAction('put'),
    update: callableAction('put'),
}
