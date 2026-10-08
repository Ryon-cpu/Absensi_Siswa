<script setup>
import { onMounted, ref } from 'vue';
import PageState from '../components/PageState.vue';
import { getAttendanceReport, getClasses } from '../services/api';
const report = ref(null);
const classes = ref([]);
const loading = ref(true);
const errorMessage = ref('');
const filters = ref({ from: '', to: '', class_id: '' });
const reportStatuses = [
    { label: 'Hadir', key: 'hadir', icon: '✓' },
    { label: 'Izin', key: 'izin', icon: '↗' },
    { label: 'Sakit', key: 'sakit', icon: '♡' },
    { label: 'Alpa', key: 'alpa', icon: '◷' },
];
async function loadReport() {
    loading.value = true;
    errorMessage.value = '';
    try {
        report.value = await getAttendanceReport({
            ...(filters.value.from ? { from: filters.value.from } : {}),
            ...(filters.value.to ? { to: filters.value.to } : {}),
            ...(filters.value.class_id ? { class_id: filters.value.class_id } : {}),
        });
    } catch (error) {
        errorMessage.value = Object.values(error.response?.data?.errors ?? {}).flat()[0]
            ?? 'Laporan tidak dapat dimuat. Periksa filter dan koneksi lalu coba lagi.';
    } finally {
        loading.value = false;
    }
}
onMounted(async () => {
    try {
        const response = await getClasses();
        classes.value = response.data;
    } catch {
        errorMessage.value = 'Daftar kelas tidak dapat dimuat.';
        loading.value = false;
        return;
    }
    await loadReport();
});
</script>
<template>
    <div class="page-heading">
        <div><h1>Laporan kehadiran</h1><p>Ringkasan statistik kehadiran berdasarkan rentang tanggal dan kelas.</p></div>
    </div>
    <section class="card" style="padding: 18px 20px;">
        <form class="filter-row" @submit.prevent="loadReport">
            <div class="field-group"><label for="report-from">Dari tanggal</label><input id="report-from" v-model="filters.from" class="field-control" type="date"></div>
            <div class="field-group"><label for="report-to">Sampai tanggal</label><input id="report-to" v-model="filters.to" class="field-control" type="date"></div>
            <div class="field-group">
                <label for="report-class">Kelas</label>
                <select id="report-class" v-model="filters.class_id" class="field-control">
                    <option value="">Semua kelas</option>
                    <option v-for="schoolClass in classes" :key="schoolClass.id" :value="schoolClass.id">{{ schoolClass.name }}</option>
                </select>
            </div>
            <button class="button button-primary" type="submit" :disabled="loading">Terapkan filter</button>
        </form>
    </section>
    <PageState :loading="loading" :error="errorMessage">
        <section v-if="report" class="report-grid">
            <article class="card report-card"><span>Total catatan</span><strong>{{ report.total }}</strong></article>
            <article v-for="status in reportStatuses" :key="status.key" class="card report-card">
                <span>{{ status.label }}</span><strong>{{ report[status.key] }}</strong>
            </article>
        </section>
    </PageState>
</template>