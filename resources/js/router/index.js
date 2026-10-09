import { createRouter, createWebHistory } from 'vue-router';
import { clearCurrentUser, loadCurrentUser } from '../auth';
import axios from '../bootstrap';
import { notify } from '../notifications';
const routes = [
    { path: '/login', name: 'login', component: () => import('../views/LoginView.vue'), meta: { guest: true } },
    { path: '/', redirect: '/dashboard' },
    { path: '/dashboard', name: 'dashboard', component: () => import('../views/DashboardView.vue'), meta: { auth: true } },
    { path: '/students', name: 'students', component: () => import('../views/EntityDirectoryView.vue'), meta: { auth: true, roles: ['admin'], resource: 'students' } },
    { path: '/classes', name: 'classes', component: () => import('../views/EntityDirectoryView.vue'), meta: { auth: true, roles: ['admin'], resource: 'classes' } },
    { path: '/teachers', name: 'teachers', component: () => import('../views/EntityDirectoryView.vue'), meta: { auth: true, roles: ['admin'], resource: 'teachers' } },
    { path: '/class-assignments', name: 'assignments', component: () => import('../views/ClassAssignmentsView.vue'), meta: { auth: true, roles: ['admin'] } },
    { path: '/attendance', name: 'attendance', component: () => import('../views/AttendanceView.vue'), meta: { auth: true, roles: ['admin', 'guru'] } },
    { path: '/teacher/classes', name: 'teacher-classes', component: () => import('../views/TeacherClassesView.vue'), meta: { auth: true, roles: ['guru'] } },
    { path: '/history', name: 'history', component: () => import('../views/AttendanceHistoryView.vue'), meta: { auth: true, roles: ['siswa'] } },
    { path: '/reports', name: 'reports', component: () => import('../views/ReportsView.vue'), meta: { auth: true, roles: ['admin'] } },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('../views/NotFoundView.vue') },
];
const router = createRouter({
    history: createWebHistory(),
    routes,
});
axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const requestUrl = error.config?.url;
        if (
            error.response?.status === 401
            && requestUrl?.startsWith('/api/')
            && requestUrl !== '/api/login'
            && requestUrl !== '/api/me'
        ) {
            const redirect = router.currentRoute.value.fullPath;
            clearCurrentUser();
            notify('Sesi login telah berakhir. Silakan masuk kembali.', 'error');
            void router.replace({ name: 'login', query: { redirect } });
        }
        return Promise.reject(error);
    },
);
router.beforeEach(async (to) => {
    let user;
    try {
        user = await loadCurrentUser();
    } catch {
        notify('Tidak dapat memeriksa sesi akun. Periksa koneksi lalu coba lagi.', 'error');
        return false;
    }
    if (to.meta.guest && user) {
        return { name: 'dashboard' };
    }
    if (to.meta.auth && !user) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }
    if (to.meta.roles && user && !to.meta.roles.includes(user.role)) {
        return { name: 'dashboard' };
    }
    return true;
});
export default router;