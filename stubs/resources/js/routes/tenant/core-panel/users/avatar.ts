import { callableAction } from '@/routes/_wayfinder'

export default {
    destroy: callableAction('delete'),
    store: callableAction('post'),
}
