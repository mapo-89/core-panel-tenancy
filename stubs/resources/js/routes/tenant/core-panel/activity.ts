import { action, callableAction } from '@/routes/_wayfinder'

export default {
    index: action('get'),
    show: callableAction('get'),
}
