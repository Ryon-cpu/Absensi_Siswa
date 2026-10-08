<script setup>
import { useRoute } from 'vue-router';
import AppShell from './components/AppShell.vue';
import { useAuth } from './auth';
import { useNotification } from './notifications';
const route = useRoute();
const auth = useAuth();
const notification = useNotification();
</script>
<template>
    <AppShell v-if="auth.user && route.meta.auth">
        <RouterView />
    </AppShell>
    <RouterView v-else />
    <Transition name="fade">
        <div v-if="notification.message" class="toast" :class="{ error: notification.type === 'error' }" role="status">
            {{ notification.message }}
        </div>
    </Transition>
</template>
<style scoped>
.fade-enter-active,
.fade-leave-active { transition: opacity .2s ease, transform .2s ease; }
.fade-enter-from,
.fade-leave-to { opacity: 0; transform: translateY(8px); }
</style>