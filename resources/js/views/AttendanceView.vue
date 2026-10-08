<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useAuth } from '../auth';
import ModalDialog from '../components/ModalDialog.vue';
import PaginationControls from '../components/PaginationControls.vue';
import PageState from '../components/PageState.vue';
import { notify } from '../notifications';
import {
    createAttendance,
    deleteAttendance,
    getAttendance,
    getClasses,
    getClassStudents,
    getTeacherClasses,
    updateAttendance,
} from '../services/api';
const auth = useAuth();
const classes = ref([]);
const students = ref([]);
const attendance = ref([]);
const pagination = ref(null);
const currentPage = ref(1);
const loading = ref(true);
const errorMessage = ref('');
const modalOpen = ref(false);
const saving = ref(false);
const formError = ref('');
const filters = ref({ date: '', class_id: '', status: '' });
const form = ref({ class_id: '', student_id: '', date: localDateString(), status: 'hadir' });
const statuses = ['hadir', 'izin', 'sakit', 'alpa'];
const isAdmin = computed(() => auth.user?.role === 'admin');
function localDateString() {
    const currentDate = new Date();
    const year = currentDate.getFullYear();
    const month = String(currentDate.getMonth() + 1).padStart(2, '0');
    const day = String(currentDate.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}
const statusTotals = computed(() => statuses.reduce((totals, status) => {
    totals[status] = attendance.value.filter((record) => record.status === status).length;
    return totals;
}, {}));
async function loadAttendance() {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await getAttendance({
            ...(filters.value.date ? { date: filters.value.date } : {}),
            ...(filters.value.class_id ? { class_id: filters.value.class_id } : {}),
            ...(filters.value.status ? { status: filters.value.status } : {}),
            page: currentPage.value,
        });
        attendance.value = response.data;
        pagination.value = response;
    } catch {
        errorMessage.value = 'Data absensi tidak dapat dimuat. Periksa koneksi lalu coba lagi.';
    } finally {
        loading.value = false;
    }
}
function applyFilters() {
    currentPage.value = 1;
    loadAttendance();
}
async function loadClasses() {
    const response = isAdmin.value ? await getClasses() : await getTeacherClasses();
    classes.value = response.data;
}
async function loadStudents(classId) {
    if (!classId) {
        students.value = [];
        return;
    }
    const response = await getClassStudents(classId);
    students.value = response.data;
}
function openForm() {
    formError.value = '';
    form.value = {
        class_id: '',
        student_id: '',
        date: localDateString(),
        status: 'hadir',
    };
    students.value = [];
    modalOpen.value = true;
}
async function saveAttendance() {
    saving.value = true;
    formError.value = '';
    try {
        await createAttendance({
            student_id: Number(form.value.student_id),
            date: form.value.date,
            status: form.value.status,
        });
        modalOpen.value = false;
        notify('Absensi berhasil dicatat.');
        await loadAttendance();
    } catch (error) {
        formError.value = Object.values(error.response?.data?.errors ?? {}).flat()[0]
            ?? error.response?.data?.message
            ?? 'Absensi tidak dapat disimpan.';
    } finally {
        saving.value = false;
    }
}
async function changeStatus(record, event) {
    const nextStatus = event.target.value;
    if (nextStatus === record.status) return;
    try {
        await updateAttendance(record.id, { status: nextStatus });
        notify('Status absensi berhasil diperbarui.');
        await loadAttendance();
    } catch (error) {
        event.target.value = record.status;
        notify(error.response?.data?.message ?? 'Status absensi tidak dapat diperbarui.', 'error');
    }
}
async function removeAttendance(record) {
    if (!window.confirm(`Hapus catatan absensi ${record.student.name} tanggal ${record.date}?`)) return;
    try {
        await deleteAttendance(record.id);
        notify('Catatan absensi berhasil dihapus.');
        await loadAttendance();
    } catch (error) {
        notify(error.response?.data?.message ?? 'Catatan absensi tidak dapat dihapus.', 'error');
    }
}
watch(() => form.value.class_id, async (classId) => {
    form.value.student_id = '';
    try {
        await loadStudents(classId);
    } catch (error) {
        formError.value = error.response?.data?.message ?? 'Daftar siswa kelas tidak dapat dimuat.';
    }
});
onMounted(async () => {
    try {
        await loadClasses();
        await loadAttendance();
    } catch {
        errorMessage.value = 'Data absensi tidak dapat dimuat. Periksa koneksi lalu coba lagi.';
        loading.value = false;
    }
});
</script>
<template>
    <div class="page-heading">
        <div><h1>{{ isAdmin ? 'Data absensi' : 'Pencatatan absensi' }}</h1><p>Catat dan pantau kehadiran sesuai kelas yang menjadi tanggung jawabmu.</p></div>
        <button class="button button-primary" @click="openForm">＋ Catat absensi</button>
    </div>
    <section class="report-grid" style="margin: 0 0 17px;">
        <article v-for="status in statuses" :key="status" class="card report-card">
            <span>{{ status.charAt(0).toUpperCase() + status.slice(1) }}</span>
            <strong>{{ statusTotals[status] ?? 0 }}</strong>
        </article>
    </section>
    <p style="margin: -5px 0 14px; color: #87928c; font-size: 11px;">Ringkasan status di halaman absensi yang sedang ditampilkan.</p>
    <section class="card table-card">
        <div class="table-tools">
            <div class="filter-row">
                <div class="field-group">
                    <label for="attendance-date">Tanggal</label>
                    <input id="attendance-date" v-model="filters.date" class="field-control" type="date" @change="applyFilters">
                </div>
                <div class="field-group">
                    <label for="attendance-class">Kelas</label>
                    <select id="attendance-class" v-model="filters.class_id" class="field-control" @change="applyFilters">
                        <option value="">Semua kelas</option>
                        <option v-for="schoolClass in classes" :key="schoolClass.id" :value="schoolClass.id">{{ schoolClass.name }}</option>
                    </select>
                </div>
                <div class="field-group">
                    <label for="attendance-status">Status</label>
                    <select id="attendance-status" v-model="filters.status" class="field-control" @change="applyFilters">
                        <option value="">Semua status</option>
                        <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                    </select>
                </div>
            </div>
            <button class="button button-secondary button-small" @click="filters = { date: '', class_id: '', status: '' }; applyFilters()">Reset filter</button>
        </div>
        <PageState :loading="loading" :error="errorMessage" :empty="attendance.length === 0" empty-message="Belum ada catatan absensi untuk filter ini.">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Siswa</th><th>Kelas</th><th>Tanggal</th><th>Status</th><th>Dicatat oleh</th><th>Aksi</th></tr></thead>
                    <tbody>
                        <tr v-for="record in attendance" :key="record.id">
                            <td><div class="person-cell"><span class="person-mini">{{ record.student.name.split(' ').map((part) => part[0]).slice(0, 2).join('').toUpperCase() }}</span><span><span class="person-primary">{{ record.student.name }}</span><span class="person-secondary">{{ record.student.student_number }}</span></span></div></td>
                            <td>{{ record.student.school_class?.name ?? '—' }}</td>
                            <td>{{ record.date }}</td>
                            <td><span class="badge" :class="record.status">{{ record.status }}</span></td>
                            <td>{{ record.recorder?.name ?? '—' }}</td>
                            <td>
                                <div class="row-actions">
                                    <select class="field-control" style="min-width: 105px; min-height: 31px; padding: 5px 8px; font-size: 11px;" :value="record.status" aria-label="Ubah status absensi" @change="changeStatus(record, $event)">
                                        <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                                    </select>
                                    <button class="button button-danger button-small" @click="removeAttendance(record)">Hapus</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </PageState>
        <PaginationControls :meta="pagination" @change="currentPage = $event; loadAttendance()" />
    </section>
    <ModalDialog v-if="modalOpen" title="Catat absensi" description="Pilih kelas, siswa, tanggal, dan status kehadiran." @close="modalOpen = false">
        <form @submit.prevent="saveAttendance">
            <div v-if="formError" class="notice-error" role="alert">{{ formError }}</div>
            <div class="field-group">
                <label for="record-class">Kelas</label>
                <select id="record-class" v-model="form.class_id" class="field-control" required>
                    <option value="" disabled>Pilih kelas</option>
                    <option v-for="schoolClass in classes" :key="schoolClass.id" :value="schoolClass.id">{{ schoolClass.name }}</option>
                </select>
            </div>
            <div class="field-group">
                <label for="record-student">Siswa</label>
                <select id="record-student" v-model="form.student_id" class="field-control" required :disabled="!form.class_id">
                    <option value="" disabled>{{ form.class_id ? 'Pilih siswa' : 'Pilih kelas terlebih dahulu' }}</option>
                    <option v-for="student in students" :key="student.id" :value="student.id">{{ student.name }} · {{ student.student_number }}</option>
                </select>
            </div>
            <div class="field-group"><label for="record-date">Tanggal</label><input id="record-date" v-model="form.date" class="field-control" type="date" required></div>
            <div class="field-group">
                <label for="record-status">Status</label>
                <select id="record-status" v-model="form.status" class="field-control" required>
                    <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="button button-secondary" @click="modalOpen = false">Batal</button>
                <button class="button button-primary" type="submit" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Simpan absensi' }}</button>
            </div>
        </form>
    </ModalDialog>
</template>