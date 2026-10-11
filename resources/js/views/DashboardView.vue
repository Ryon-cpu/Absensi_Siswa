<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import PageState from '../components/PageState.vue';
import { useAuth } from '../auth';
import { getAttendanceReport, getDashboard } from '../services/api';
const auth = useAuth();
const dashboard = ref(null);
const loading = ref(true);
const errorMessage = ref('');
const semesterReport = ref(null);
const semesterFilters = ref({ from: '', to: '' });
const semesterLoading = ref(true);
const semesterError = ref('');
const greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 11) return 'Selamat pagi';
    if (hour < 15) return 'Selamat siang';
    if (hour < 18) return 'Selamat sore';
    return 'Selamat malam';
});
const roleText = computed(() => ({
    admin: 'Pantau aktivitas sekolah dan kelola data kehadiran dari satu tempat.',
    guru: 'Berikut ringkasan kelas dan kehadiran siswa yang menjadi tanggung jawabmu.',
    siswa: 'Lihat ringkasan dan riwayat kehadiran pribadimu.',
}[auth.user?.role] ?? 'Pantau informasi kehadiran sekolah.'));
const stats = computed(() => {
    if (!dashboard.value) return [];
    const data = dashboard.value;
    if (auth.user?.role === 'admin') {
        return [
            { label: 'Total siswa', value: data.students, icon: '♙', note: 'Siswa terdaftar' },
            { label: 'Total kelas', value: data.classes, icon: '▦', note: 'Kelas aktif' },
            { label: 'Total guru', value: data.teachers, icon: '♧', note: 'Guru terdaftar' },
            { label: 'Absensi hari ini', value: data.today_attendance?.total ?? 0, icon: '✓', note: 'Catatan tercatat' },
        ];
    }
    if (auth.user?.role === 'guru') {
        return [
            { label: 'Kelas diajar', value: data.classes, icon: '▦', note: 'Kelas ditugaskan' },
            { label: 'Siswa', value: data.students, icon: '♙', note: 'Di kelas tanggung jawabmu' },
            { label: 'Hadir hari ini', value: data.today_attendance?.hadir ?? 0, icon: '✓', note: 'Catatan kehadiran' },
            { label: 'Belum hadir', value: (data.students ?? 0) - (data.today_attendance?.total ?? 0), icon: '◷', note: 'Belum tercatat hari ini' },
        ];
    }
    return [
        { label: 'Total catatan', value: data.attendance?.total ?? 0, icon: '▤', note: 'Riwayat kehadiranmu' },
        { label: 'Hadir', value: data.attendance?.hadir ?? 0, icon: '✓', note: 'Hari kehadiran' },
        { label: 'Izin dan sakit', value: (data.attendance?.izin ?? 0) + (data.attendance?.sakit ?? 0), icon: '♡', note: 'Dengan keterangan' },
        { label: 'Alpa', value: data.attendance?.alpa ?? 0, icon: '◷', note: 'Tanpa keterangan' },
    ];
});
const attendanceSummary = computed(() => auth.user?.role === 'admin'
    ? dashboard.value?.today_attendance
    : auth.user?.role === 'guru'
        ? dashboard.value?.today_attendance
        : dashboard.value?.attendance);
const attendanceRows = computed(() => {
    const summary = attendanceSummary.value ?? {};
    return [
        { label: 'Hadir', key: 'hadir', value: summary.hadir ?? 0, className: '' },
        { label: 'Izin', key: 'izin', value: summary.izin ?? 0, className: 'izin' },
        { label: 'Sakit', key: 'sakit', value: summary.sakit ?? 0, className: 'sakit' },
        { label: 'Alpa', key: 'alpa', value: summary.alpa ?? 0, className: 'alpa' },
    ];
});
const shortcuts = computed(() => {
    if (auth.user?.role === 'admin') {
        return [
            { label: 'Kelola data siswa', icon: '♙', to: '/students' },
            { label: 'Catat kehadiran', icon: '✓', to: '/attendance' },
            { label: 'Lihat laporan', icon: '▥', to: '/reports' },
        ];
    }
    if (auth.user?.role === 'guru') {
        return [
            { label: 'Lihat kelas yang diajar', icon: '▦', to: '/teacher/classes' },
            { label: 'Catat kehadiran siswa', icon: '✓', to: '/attendance' },
        ];
    }
    return [{ label: 'Lihat riwayat absensi', icon: '◷', to: '/history' }];
});
const todayLabel = new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
}).format(new Date());
const attendanceRate = computed(() => {
    const total = attendanceSummary.value?.total ?? 0;
    const present = attendanceSummary.value?.hadir ?? 0;
    return total > 0 ? Math.round((present / total) * 100) : 0;
});
async function loadDashboard() {
    loading.value = true;
    errorMessage.value = '';
    try {
        dashboard.value = await getDashboard();
    } catch {
        errorMessage.value = 'Ringkasan belum dapat dimuat. Periksa koneksi lalu coba lagi.';
    } finally {
        loading.value = false;
    }
}
async function loadSemesterReport() {
    semesterLoading.value = true;
    semesterError.value = '';
    try {
        const query = {
            ...(semesterFilters.value.from ? { from: semesterFilters.value.from } : {}),
            ...(semesterFilters.value.to ? { to: semesterFilters.value.to } : {}),
        };
        semesterReport.value = await getAttendanceReport(query);
    } catch (error) {
        semesterError.value = Object.values(error.response?.data?.errors ?? {}).flat()[0]
            ?? 'Rekap semester tidak dapat dimuat. Periksa koneksi lalu coba lagi.';
    } finally {
        semesterLoading.value = false;
    }
}
onMounted(() => {
    void loadDashboard();
    if (auth.user?.role === 'admin') {
        void loadSemesterReport();
    }
});
</script>
<template>
    <PageState :loading="loading" :error="errorMessage">
        <template v-if="dashboard">
            <header class="dashboard-welcome">
                <div>
                    <span class="dashboard-eyebrow">RINGKASAN SEKOLAH</span>
                    <h1>{{ greeting }}, {{ auth.user?.name?.split(' ')[0] }} <span aria-hidden="true">👋</span></h1>
                    <p>{{ roleText }}</p>
                </div>
                <div class="dashboard-date">
                    <span class="date-icon" aria-hidden="true">▦</span>
                    <span><small>Hari ini</small><strong>{{ todayLabel }}</strong></span>
                </div>
            </header>
            <section class="stat-grid dashboard-stat-grid">
                <article v-for="(stat, index) in stats" :key="stat.label" class="card stat-card dashboard-stat-card" :class="`stat-accent-${index + 1}`">
                    <div class="stat-top">
                        <span>{{ stat.label }}</span>
                        <span class="stat-icon" aria-hidden="true">{{ stat.icon }}</span>
                    </div>
                    <div class="stat-value">{{ stat.value ?? 0 }}</div>
                    <div class="stat-note">{{ stat.note }}</div>
                </article>
            </section>
            <section class="dashboard-insights">
                <article class="card attendance-card">
                    <header class="card-heading">
                        <div>
                            <span class="section-kicker">KEHADIRAN</span>
                            <h2>Ringkasan kehadiran</h2>
                            <p>{{ auth.user?.role === 'siswa' ? 'Statistik dari riwayat pribadimu' : 'Catatan kehadiran hari ini' }}</p>
                        </div>
                        <span class="badge">{{ attendanceSummary?.total ?? 0 }} catatan</span>
                    </header>
                    <div class="attendance-insight-body">
                        <div
                            class="attendance-donut"
                            :style="{ '--attendance-rate': `${attendanceRate}%` }"
                            role="img"
                            :aria-label="`${attendanceRate}% dari catatan kehadiran berstatus hadir`">
                            <div><strong>{{ attendanceRate }}%</strong><span>Hadir</span></div>
                        </div>
                        <div class="attendance-legend">
                            <div v-for="row in attendanceRows" :key="row.key" class="legend-row">
                                <span class="legend-label"><i :class="row.className"></i>{{ row.label }}</span>
                                <strong>{{ row.value }}</strong>
                            </div>
                        </div>
                    </div>
                </article>
                <article class="card quick-access-card">
                    <header class="card-heading">
                        <div>
                            <span class="section-kicker">MENU</span>
                            <h2>Akses cepat</h2>
                            <p>Langsung ke aktivitas utama</p>
                        </div>
                    </header>
                    <div class="card-body quick-list">
                        <RouterLink v-for="(item, index) in shortcuts" :key="item.to" class="quick-link" :to="item.to">
                            <span class="quick-icon" :class="`quick-icon-${index + 1}`" aria-hidden="true">{{ item.icon }}</span>
                            <span>{{ item.label }}</span>
                            <span class="quick-arrow" aria-hidden="true">→</span>
                        </RouterLink>
                    </div>
                </article>
            </section>
            <section v-if="auth.user?.role === 'admin'" class="card dashboard-semester-section">
                <header class="card-heading dashboard-semester-heading">
                    <div>
                        <span class="section-kicker">TREN KEHADIRAN</span>
                        <h2>Rekap semester</h2>
                        <p>Persentase hadir dan siswa yang paling sering tidak hadir.</p>
                    </div>
                </header>
                <form class="filter-row dashboard-semester-filter" @submit.prevent="loadSemesterReport">
                    <div class="field-group"><label for="dashboard-semester-from">Awal semester</label><input id="dashboard-semester-from" v-model="semesterFilters.from" class="field-control" type="date"></div>
                    <div class="field-group"><label for="dashboard-semester-to">Akhir semester</label><input id="dashboard-semester-to" v-model="semesterFilters.to" class="field-control" type="date"></div>
                    <button class="button button-primary" type="submit" :disabled="semesterLoading">Terapkan periode</button>
                </form>
                <p class="field-hint report-filter-hint">Tentukan tanggal awal dan akhir semester. Jika dikosongkan, rekap menampilkan seluruh riwayat.</p>
                <div v-if="semesterLoading" class="loading-state">Memuat rekap semester...</div>
                <div v-else-if="semesterError" class="notice-error dashboard-semester-error">{{ semesterError }}</div>
                <div v-else-if="semesterReport" class="report-insights-grid dashboard-semester-insights">
                    <article class="report-panel dashboard-semester-trends">
                        <div class="report-rate-summary"><span>Persentase hadir</span><strong>{{ semesterReport.attendance_rate }}%</strong><small>{{ semesterReport.total }} catatan absensi</small></div>
                        <div v-if="semesterReport.daily_trends.length" class="trend-list">
                            <div v-for="day in semesterReport.daily_trends" :key="day.date" class="trend-day">
                                <div class="trend-day-heading"><strong>{{ day.date }}</strong><span>{{ day.attendance_rate }}% hadir</span></div>
                                <div class="trend-track"><span class="trend-fill" :style="{ width: `${day.attendance_rate}%` }"></span></div>
                                <div class="trend-counts"><span>Hadir {{ day.hadir }}</span><span>Izin {{ day.izin }}</span><span>Sakit {{ day.sakit }}</span><span>Alpa {{ day.alpa }}</span></div>
                            </div>
                        </div>
                        <div v-else class="empty-state">Belum ada catatan absensi pada periode ini.</div>
                    </article>
                    <article class="report-panel dashboard-semester-students">
                        <div class="report-panel-heading">
                            <div><h2>Siswa sering tidak hadir</h2><p>Diurutkan berdasarkan jumlah alpa, izin, dan sakit.</p></div>
                        </div>
                        <div v-if="semesterReport.frequent_students.length" class="table-wrap">
                            <table class="data-table">
                                <thead><tr><th>Siswa</th><th>Kelas</th><th>Alpa</th><th>Izin</th><th>Sakit</th><th>Total</th></tr></thead>
                                <tbody>
                                    <tr v-for="student in semesterReport.frequent_students.slice(0, 5)" :key="student.student_id">
                                        <td><div class="person-primary">{{ student.name }}</div><div class="person-secondary">{{ student.student_number }}</div></td>
                                        <td>{{ student.class_name }}</td>
                                        <td>{{ student.alpa }}</td>
                                        <td>{{ student.izin }}</td>
                                        <td>{{ student.sakit }}</td>
                                        <td><strong>{{ student.absence_count }}</strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="empty-state">Tidak ada siswa yang alpa, izin, atau sakit pada periode ini.</div>
                    </article>
                </div>
            </section>
        </template>
    </PageState>
</template>