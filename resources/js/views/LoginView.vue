<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { setCurrentUser } from '../auth';
import { login } from '../services/api';
const route = useRoute();
const router = useRouter();
const email = ref('');
const password = ref('');
const showPassword = ref(false);
const errorMessage = ref('');
const submitting = ref(false);
async function submitLogin() {
    errorMessage.value = '';
    submitting.value = true;
    try {
        const user = await login({ email: email.value, password: password.value });
        setCurrentUser(user);
        await router.replace(typeof route.query.redirect === 'string' ? route.query.redirect : '/dashboard');
    } catch (error) {
        errorMessage.value = error.response?.data?.errors?.email?.[0]
            ?? error.response?.data?.message
            ?? 'Tidak dapat terhubung ke server. Periksa koneksi lalu coba lagi.';
    } finally {
        submitting.value = false;
    }
}
</script>
<template>
    <main class="login-page">
        <section class="login-panel">
            <div class="login-brand">
                <span class="brand-mark">AS</span>
                <span>Sistem Absensi<small>Portal kehadiran siswa</small></span>
            </div>
            <h1>Selamat datang</h1>
            <p class="login-intro">Masuk untuk melanjutkan ke dashboard dan mengelola informasi kehadiran.</p>
            <div v-if="errorMessage" class="notice-error" role="alert">{{ errorMessage }}</div>
            <form @submit.prevent="submitLogin">
                <div class="field-group">
                    <label for="email">Email</label>
                    <input
                        id="email"
                        v-model.trim="email"
                        class="field-control"
                        type="email"
                        autocomplete="username"
                        placeholder="nama@sekolah.sch.id"
                        required>
                </div>
                <div class="field-group">
                    <label for="password">Kata sandi</label>
                    <div class="password-input-wrap">
                        <input
                            id="password"
                            v-model="password"
                            class="field-control"
                            :type="showPassword ? 'text' : 'password'"
                            autocomplete="current-password"
                            placeholder="Masukkan kata sandi"
                            required>
                        <button
                            class="password-toggle"
                            type="button"
                            :aria-label="showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                            :aria-pressed="showPassword"
                            @click="showPassword = !showPassword">
                            <svg v-if="showPassword" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M9.9 5.2A10.8 10.8 0 0112 5c5 0 8.5 4.2 9.5 7a10.8 10.8 0 01-3.1 4.5M6.2 6.2C3.9 7.7 2.8 10 2.5 12c.4 1.2 1.4 2.8 3.2 4.3A9.8 9.8 0 0012 19c1 0 2-.2 2.9-.5" />
                            </svg>
                            <svg v-else viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M2.5 12S6 5 12 5s9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7z" />
                                <circle cx="12" cy="12" r="2.5" />
                            </svg>
                        </button>
                    </div>
                </div>
                <button class="button button-primary button-block" type="submit" :disabled="submitting">
                    {{ submitting ? 'Memeriksa akun...' : 'Masuk ke akun' }}
                </button>
            </form>
            <p style="margin: 25px 0 0; color: #87928c; font-size: 11px; text-align: center;">
                Gunakan akun yang telah diberikan oleh administrator.
            </p>
        </section>
        <aside class="login-art" aria-label="Informasi sistem absensi">
            <div class="art-grid"></div>
            <div class="art-content">
                <div class="art-tag"><span></span> Satu sistem, kehadiran lebih teratur</div>
                <h2>Hadir hari ini,<br>siap untuk esok.</h2>
                <p>Catat kehadiran, pantau kelas, dan lihat perkembangan siswa dari satu tempat yang mudah digunakan.</p>
            </div>
        </aside>
    </main>
</template>