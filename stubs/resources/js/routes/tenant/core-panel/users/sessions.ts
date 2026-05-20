import { callableAction } from '@/routes/_wayfinder'

export default {
    destroy: callableAction('delete'),
    index: callableAction('get'),
}
