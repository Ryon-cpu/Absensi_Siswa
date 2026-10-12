<script setup>
import { onMounted, ref } from 'vue';
import PageState from '../components/PageState.vue';
import PaginationControls from '../components/PaginationControls.vue';
import { getAttendanceAuditLogs, getTeacherClasses } from '../services/api';

const auditLogs = ref([]);
const classes = ref([]);
const pagination = ref(null);
const currentPage = ref(1);
const loading = ref(true);
const errorMessage = ref('');
const filters = ref({ from: '', to: '', class_id: '', action: '' });
const actions = [
    { value: 'created', label: 'Absensi dibuat' },
    { value: 'updated', label: 'Status diubah' },
    { value: 'deleted', label: 'Absensi dihapus' },
];

async function loadAuditLogs() {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await getAttendanceAuditLogs({
            ...(filters.value.from ? { from: filters.value.from } : {}),
            ...(filters.value.to ? { to: filters.value.to } : {}),
            ...(filters.value.class_id ? { class_id: filters.value.class_id } : {}),
            ...(filters.value.action ? { action: filters.value.action } : {}),
            page: currentPage.value,
        });
        auditLogs.value = response.data;
        pagination.value = response;
    } catch (error) {
        errorMessage.value = Object.values(error.response?.data?.errors ?? {}).flat()[0]
            ?? 'Riwayat perubahan absensi tidak dapat dimuat. Periksa koneksi lalu coba lagi.';
    } finally {
        loading.value = false;
    }
}

async function loadPageData() {
    try {
        const response = await getTeacherClasses();
        classes.value = response.data;
    } catch {
        errorMessage.value = 'Daftar kelas tidak dapat dimuat.';
        loading.value = false;
        return;
    }

    await loadAuditLogs();
}

function applyFilters() {
    currentPage.value = 1;
    loadAuditLogs();
}

function actionLabel(action) {
    return actions.find((item) => item.value === action)?.label ?? action;
}

function formatTimestamp(timestamp) {
    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(timestamp.replace(' ', 'T')));
}

onMounted(loadPageData);
</script>

<template>
    <div class="page-heading">
        <div>
            <h1>Audit perubahan absensi</h1>
            <p>Riwayat siapa yang mencatat, mengubah, atau menghapus absensi.</p>
        </div>
    </div>
    <section class="card table-card">
        <form class="table-tools" @submit.prevent="applyFilters">
            <div class="filter-row">
                <div class="field-group"><label for="audit-from">Dari tanggal perubahan</label><input id="audit-from" v-model="filters.from" class="field-control" type="date"></div>
                <div class="field-group"><label for="audit-to">Sampai tanggal perubahan</label><input id="audit-to" v-model="filters.to" class="field-control" type="date"></div>
                <div class="field-group">
                    <label for="audit-class">Kelas</label>
                    <select id="audit-class" v-model="filters.class_id" class="field-control">
                        <option value="">Semua kelas</option>
                        <option v-for="schoolClass in classes" :key="schoolClass.id" :value="schoolClass.id">{{ schoolClass.name }}</option>
                    </select>
                </div>
                <div class="field-group">
                    <label for="audit-action">Aktivitas</label>
                    <select id="audit-action" v-model="filters.action" class="field-control">
                        <option value="">Semua aktivitas</option>
                        <option v-for="action in actions" :key="action.value" :value="action.value">{{ action.label }}</option>
                    </select>
                </div>
                <button class="button button-primary" type="submit" :disabled="loading">Terapkan filter</button>
                <button class="button button-secondary" type="button" :disabled="loading" @click="filters = { from: '', to: '', class_id: '', action: '' }; applyFilters()">Reset</button>
            </div>
        </form>
        <PageState :loading="loading" :error="errorMessage" :empty="auditLogs.length === 0" empty-message="Belum ada perubahan absensi yang tercatat.">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Waktu perubahan</th><th>Aktivitas</th><th>Siswa</th><th>Kelas</th><th>Tanggal absensi</th><th>Mapel / pertemuan</th><th>Status sebelumnya</th><th>Status baru</th><th>Dilakukan oleh</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="log in auditLogs" :key="log.id">
                            <td>{{ formatTimestamp(log.occurred_at) }}</td>
                            <td>{{ actionLabel(log.action) }}</td>
                            <td><div class="person-primary">{{ log.student_name }}</div><div class="person-secondary">{{ log.student_number }}</div></td>
                            <td>{{ log.class_name ?? '—' }}</td>
                            <td>{{ log.attendance_date }}</td>
                            <td>
                                <template v-if="log.subject_name">
                                    {{ log.subject_name }} · Pertemuan {{ log.meeting_number }}
                                </template>
                                <span v-else>Absensi harian lama</span>
                            </td>
                            <td><span v-if="log.previous_status" class="badge" :class="log.previous_status">{{ log.previous_status }}</span><span v-else>—</span></td>
                            <td><span v-if="log.new_status" class="badge" :class="log.new_status">{{ log.new_status }}</span><span v-else>—</span></td>
                            <td>{{ log.actor_name }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </PageState>
        <PaginationControls :meta="pagination" @change="currentPage = $event; loadAuditLogs()" />
    </section>
</template>
