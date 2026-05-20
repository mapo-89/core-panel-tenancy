import { action, callableAction } from '@/routes/_wayfinder'

export default {
    create: action('get'),
    destroy: callableAction('delete'),
    edit: callableAction('get'),
    export: callableAction('get'),
    index: action('get'),
    preview: callableAction('get'),
    publish: callableAction('post'),
    store: action('post'),
    submissions: {
        export: callableAction('get'),
        index: callableAction('get'),
    },
    update: callableAction('put'),
}
