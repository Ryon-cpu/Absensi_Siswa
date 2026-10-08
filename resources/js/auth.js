import { reactive } from 'vue';
import { getCurrentUser } from './services/api';
const authState = reactive({
    user: null,
    loaded: false,
});
let userRequest;
export async function loadCurrentUser() {
    if (authState.loaded) {
        return authState.user;
    }
    if (!userRequest) {
        userRequest = getCurrentUser()
            .then((user) => {
                authState.user = user;
                authState.loaded = true;
                return user;
            })
            .catch((error) => {
                if (error.response?.status !== 401) {
                    throw error;
                }
                authState.user = null;
                authState.loaded = true;
                return null;
            })
            .finally(() => {
                userRequest = null;
            });
    }
    return userRequest;
}
export function setCurrentUser(user) {
    authState.user = user;
    authState.loaded = true;
}
export function clearCurrentUser() {
    authState.user = null;
    authState.loaded = true;
}
export function useAuth() {
    return authState;
}