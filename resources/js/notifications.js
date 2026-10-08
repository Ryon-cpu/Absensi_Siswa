import { reactive } from 'vue';
const notificationState = reactive({
    message: '',
    type: 'success',
});
let timeout;
export function notify(message, type = 'success') {
    notificationState.message = message;
    notificationState.type = type;
    window.clearTimeout(timeout);
    timeout = window.setTimeout(() => {
        notificationState.message = '';
    }, 3500);
}
export function useNotification() {
    return notificationState;
}