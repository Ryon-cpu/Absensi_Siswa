<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { clearCurrentUser, useAuth } from '../auth';
import { logout } from '../services/api';
import { notify } from '../notifications';
const route = useRoute();
const router = useRouter();
const auth = useAuth();
const menuOpen = ref(false);
const loggingOut = ref(false);
const navigation = computed(() => {
    const common = [
        { label: 'Ringkasan', icon: '⌂', to: '/dashboard', roles: ['admin', 'guru', 'siswa'] },
        { label: 'Data siswa', icon: '♙', to: '/students', roles: ['admin'] },
        { label: 'Data kelas', icon: '▦', to: '/classes', roles: ['admin'] },
        { label: 'Data guru', icon: '♧', to: '/teachers', roles: ['admin'] },
        { label: 'Penugasan guru', icon: '↗', to: '/class-assignments', roles: ['admin'] },
        { label: 'Kelas yang diajar', icon: '▤', to: '/teacher/classes', roles: ['guru'] },
        { label: 'Pencatatan absensi', icon: '✓', to: '/attendance', roles: ['admin', 'guru'] },
        { label: 'Riwayat absensi', icon: '◷', to: '/history', roles: ['siswa'] },
        { label: 'Laporan', icon: '▥', to: '/reports', roles: ['admin'] },
    ];
    return common.filter((item) => item.roles.includes(auth.user?.role));
});
const mobileNavigation = computed(() => {
    const paths = auth.user?.role === 'admin'
        ? ['/dashboard', '/students', '/attendance']
        : auth.user?.role === 'guru'
            ? ['/dashboard', '/teacher/classes', '/attendance']
            : ['/dashboard', '/history'];
    return paths
        .map((path) => navigation.value.find((item) => item.to === path))
        .filter(Boolean);
});
const pageTitle = computed(() => navigation.value.find((item) => item.to === route.path)?.label ?? 'Absensi Siswa');
const initials = computed(() => auth.user?.name?.split(' ').map((part) => part[0]).slice(0, 2).join('').toUpperCase() ?? 'AS');
async function handleLogout() {
    loggingOut.value = true;
    try {
        await logout();
        clearCurrentUser();
        menuOpen.value = false;
        await router.replace({ name: 'login' });
        notify('Kamu berhasil keluar dari akun.');
    } catch {
        notify('Tidak dapat keluar sekarang. Silakan coba lagi.', 'error');
    } finally {
        loggingOut.value = false;
    }
}
</script>
<template>
    <div class="app-shell">
        <button
            class="mobile-scrim"
            :class="{ 'is-visible': menuOpen }"
            aria-label="Tutup navigasi"
            @click="menuOpen = false"
        ></button>
        <aside class="sidebar" :class="{ 'is-open': menuOpen }">
            <div class="sidebar-brand">
                <span class="brand-mark">AS</span>
                <span>Sistem Absensi<small>Portal sekolah</small></span>
            </div>
            <div class="nav-section-label">Menu utama</div>
            <nav class="nav-list" aria-label="Navigasi utama">
                <RouterLink
                    v-for="item in navigation"
                    :key="item.to"
                    :to="item.to"
                    class="nav-link"
                    @click="menuOpen = false"
                >
                    <span class="nav-icon" aria-hidden="true">{{ item.icon }}</span>
                    <span>{{ item.label }}</span>
                </RouterLink>
            </nav>
            <div class="sidebar-bottom">
                <div class="help-card">
                    <strong>Butuh bantuan?</strong>
                    <p>Hubungi administrator sekolah jika ada kendala pada data absensi.</p>
                </div>
            </div>
        </aside>
        <div class="main-column">
            <header class="topbar">
                <div class="topbar-actions">
                    <button class="icon-button menu-toggle" aria-label="Buka navigasi" @click="menuOpen = !menuOpen">☰</button>
                    <div class="breadcrumbs">Beranda <span aria-hidden="true">/</span> <strong>{{ pageTitle }}</strong></div>
                </div>
                <div class="topbar-actions">
                    <div class="profile">
                        <span class="avatar">{{ initials }}</span>
                        <span>
                            <span class="profile-name">{{ auth.user?.name }}</span>
                            <span class="profile-role">{{ auth.user?.role }}</span>
                        </span>
                    </div>
                    <button class="button button-secondary button-small" :disabled="loggingOut" @click="handleLogout">
                        {{ loggingOut ? 'Keluar...' : 'Keluar' }}
                    </button>
                </div>
            </header>
            <main class="page-content">
                <slot />
            </main>
        </div>
        <nav class="mobile-bottom-nav" aria-label="Navigasi cepat">
            <RouterLink
                v-for="item in mobileNavigation"
                :key="item.to"
                :to="item.to"
                class="mobile-nav-link"
            >
                <span class="nav-icon" aria-hidden="true">{{ item.icon }}</span>
                <span>{{ item.label }}</span>
            </RouterLink>
            <button
                class="mobile-nav-link"
                type="button"
                :aria-expanded="menuOpen"
                @click="menuOpen = !menuOpen"
            >
                <span class="nav-icon" aria-hidden="true">☷</span>
                <span>Menu</span>
            </button>
        </nav>
    </div>
</template>
