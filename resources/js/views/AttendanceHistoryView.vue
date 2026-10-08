<script setup>
import { onMounted, ref } from 'vue';
import PageState from '../components/PageState.vue';
import PaginationControls from '../components/PaginationControls.vue';
import { getAttendance } from '../services/api';
const attendance = ref([]);
const pagination = ref(null);
const currentPage = ref(1);
const loading = ref(true);
const errorMessage = ref('');
const filters = ref({ date: '', status: '' });
const statuses = ['hadir', 'izin', 'sakit', 'alpa'];
async function loadHistory() {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await getAttendance({
            ...(filters.value.date ? { date: filters.value.date } : {}),
            ...(filters.value.status ? { status: filters.value.status } : {}),
            page: currentPage.value,
        });
        attendance.value = response.data;
        pagination.value = response;
    } catch {
        errorMessage.value = 'Riwayat absensi tidak dapat dimuat. Periksa koneksi lalu coba lagi.';
    } finally {
        loading.value = false;
    }
}
function applyFilters() {
    currentPage.value = 1;
    loadHistory();
}
onMounted(loadHistory);
</script>
<template>
    <div class="page-heading">
        <div><h1>Riwayat absensi</h1><p>Pantau catatan kehadiran pribadimu.</p></div>
    </div>
    <section class="card table-card">
        <div class="table-tools">
            <div class="filter-row">
                <div class="field-group"><label for="history-date">Tanggal</label><input id="history-date" v-model="filters.date" class="field-control" type="date" @change="applyFilters"></div>
                <div class="field-group">
                    <label for="history-status">Status</label>
                    <select id="history-status" v-model="filters.status" class="field-control" @change="applyFilters">
                        <option value="">Semua status</option>
                        <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                    </select>
                </div>
            </div>
            <button class="button button-secondary button-small" @click="filters = { date: '', status: '' }; applyFilters()">Reset filter</button>
        </div>
        <PageState :loading="loading" :error="errorMessage" :empty="attendance.length === 0" empty-message="Belum ada riwayat absensi.">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Tanggal</th><th>Kelas</th><th>Status</th><th>Dicatat oleh</th></tr></thead>
                    <tbody>
                        <tr v-for="record in attendance" :key="record.id">
                            <td>{{ record.date }}</td>
                            <td>{{ record.student.school_class?.name ?? '—' }}</td>
                            <td><span class="badge" :class="record.status">{{ record.status }}</span></td>
                            <td>{{ record.recorder?.name ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </PageState>
        <PaginationControls :meta="pagination" @change="currentPage = $event; loadHistory()" />
    </section>
</template>