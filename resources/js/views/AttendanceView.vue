<script setup>
import { computed, onMounted, ref } from 'vue';
import { useAuth } from '../auth';
import PaginationControls from '../components/PaginationControls.vue';
import PageState from '../components/PageState.vue';
import { notify } from '../notifications';
import {
    createAttendanceSession,
    createClassSubject,
    deleteAttendance,
    getAttendance,
    getAttendanceSheet,
    getClasses,
    getClassSubjects,
    getTeacherClasses,
    saveAttendanceSheet,
    updateAttendance,
} from '../services/api';

const auth = useAuth();
const classes = ref([]);
const subjects = ref([]);
const attendance = ref([]);
const pagination = ref(null);
const currentPage = ref(1);
const loading = ref(true);
const errorMessage = ref('');
const sheet = ref([]);
const activeSession = ref(null);
const selectedClassId = ref('');
const selectedSubjectId = ref('');
const selectedDate = ref(localDateString());
const loadedClassId = ref('');
const loadedSubjectId = ref('');
const loadedDate = ref('');
const sheetLoading = ref(false);
const sheetSaving = ref(false);
const subjectSaving = ref(false);
const sheetError = ref('');
const newSubjectName = ref('');
const filters = ref({ date: '', class_id: '', status: '' });
const statuses = ['hadir', 'sakit', 'izin', 'alpa'];
const isAdmin = computed(() => auth.user?.role === 'admin');
const sheetTotals = computed(() => statuses.reduce((totals, status) => {
    totals[status] = sheet.value.filter((student) => student.status === status).length;
    return totals;
}, {}));
const sheetHasChanges = computed(() => sheet.value.some((student) => student.status !== student.savedStatus));
const historyTotals = computed(() => statuses.reduce((totals, status) => {
    totals[status] = attendance.value.filter((record) => record.status === status).length;
    return totals;
}, {}));

function localDateString() {
    const currentDate = new Date();
    const year = currentDate.getFullYear();
    const month = String(currentDate.getMonth() + 1).padStart(2, '0');
    const day = String(currentDate.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

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
        errorMessage.value = 'Data riwayat absensi tidak dapat dimuat. Periksa koneksi lalu coba lagi.';
    } finally {
        loading.value = false;
    }
}

async function loadSheet() {
    if (!selectedClassId.value || !selectedSubjectId.value || !selectedDate.value) {
        sheet.value = [];
        activeSession.value = null;
        return;
    }

    sheetLoading.value = true;
    sheetError.value = '';
    try {
        const session = await createAttendanceSession({
            subject_id: Number(selectedSubjectId.value),
            date: selectedDate.value,
        });
        activeSession.value = session;
        const response = await getAttendanceSheet({
            attendance_session_id: session.id,
        });
        sheet.value = response.students.map((student) => ({
            ...student,
            status: student.status ?? 'hadir',
            savedStatus: student.status ?? 'hadir',
        }));
        loadedClassId.value = selectedClassId.value;
        loadedSubjectId.value = selectedSubjectId.value;
        loadedDate.value = selectedDate.value;
    } catch (error) {
        sheet.value = [];
        activeSession.value = null;
        sheetError.value = error.response?.data?.message
            ?? 'Lembar absensi tidak dapat dimuat. Periksa koneksi lalu coba lagi.';
    } finally {
        sheetLoading.value = false;
    }
}

async function changeSheetSelection() {
    if (sheetHasChanges.value && !window.confirm('Perubahan absensi belum disimpan. Ganti pertemuan dan buang perubahan?')) {
        selectedClassId.value = activeSession.value?.subject?.class_id ?? loadedClassId.value;
        selectedSubjectId.value = activeSession.value?.subject_id ?? loadedSubjectId.value;
        selectedDate.value = activeSession.value?.date ?? loadedDate.value;
        return false;
    }

    sheet.value = [];
    activeSession.value = null;

    return true;
}

async function changeClass() {
    const previousClassId = loadedClassId.value;
    if (! await changeSheetSelection()) {
        selectedClassId.value = previousClassId;
        return;
    }

    try {
        subjects.value = selectedClassId.value
            ? await getClassSubjects(selectedClassId.value)
            : [];
        selectedSubjectId.value = subjects.value[0]?.id ?? '';
        loadedClassId.value = selectedClassId.value;
    } catch (error) {
        subjects.value = [];
        selectedSubjectId.value = '';
        sheetError.value = error.response?.data?.message ?? 'Daftar mata pelajaran tidak dapat dimuat.';
    }
}

async function addSubject() {
    if (!selectedClassId.value || !newSubjectName.value.trim()) {
        return;
    }

    if (! await changeSheetSelection()) {
        return;
    }

    subjectSaving.value = true;
    sheetError.value = '';
    try {
        const subject = await createClassSubject(selectedClassId.value, {
            name: newSubjectName.value.trim(),
        });
        subjects.value = [...subjects.value, subject].sort((left, right) => left.name.localeCompare(right.name));
        selectedSubjectId.value = subject.id;
        newSubjectName.value = '';
        notify('Mata pelajaran berhasil ditambahkan.');
    } catch (error) {
        sheetError.value = Object.values(error.response?.data?.errors ?? {}).flat()[0]
            ?? error.response?.data?.message
            ?? 'Mata pelajaran tidak dapat ditambahkan.';
    } finally {
        subjectSaving.value = false;
    }
}

async function loadClasses() {
    const response = isAdmin.value ? await getClasses() : await getTeacherClasses();
    classes.value = response.data ?? response;
}

async function saveSheet() {
    if (!activeSession.value || sheet.value.length === 0) {
        return;
    }

    sheetSaving.value = true;
    sheetError.value = '';
    try {
        const response = await saveAttendanceSheet({
            attendance_session_id: activeSession.value.id,
            attendance: sheet.value.map((student) => ({
                student_id: student.id,
                status: student.status,
            })),
        });
        notify(response.message);
        await Promise.all([loadSheet(), loadAttendance()]);
    } catch (error) {
        sheetError.value = Object.values(error.response?.data?.errors ?? {}).flat()[0]
            ?? error.response?.data?.message
            ?? 'Absensi kelas tidak dapat disimpan.';
    } finally {
        sheetSaving.value = false;
    }
}

function applyFilters() {
    currentPage.value = 1;
    loadAttendance();
}

async function changeStatus(record, event) {
    const nextStatus = event.target.value;
    if (nextStatus === record.status) {
        return;
    }

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
    if (!window.confirm(`Hapus catatan absensi ${record.student.name} tanggal ${record.date}?`)) {
        return;
    }

    try {
        await deleteAttendance(record.id);
        notify('Catatan absensi berhasil dihapus.');
        await loadAttendance();
    } catch (error) {
        notify(error.response?.data?.message ?? 'Catatan absensi tidak dapat dihapus.', 'error');
    }
}

onMounted(async () => {
    try {
        await loadClasses();
        selectedClassId.value = classes.value[0]?.id ?? '';
        if (selectedClassId.value) {
            subjects.value = await getClassSubjects(selectedClassId.value);
            selectedSubjectId.value = subjects.value[0]?.id ?? '';
            loadedClassId.value = selectedClassId.value;
        }
        await loadAttendance();
    } catch {
        sheetError.value = 'Data absensi tidak dapat dimuat. Periksa koneksi lalu coba lagi.';
        loading.value = false;
    }
});
</script>

<template>
    <div class="page-heading">
        <div>
            <h1>{{ isAdmin ? 'Data absensi' : 'Pencatatan absensi' }}</h1>
            <p>Pilih kelas, mata pelajaran, dan tanggal pertemuan seperti pada buku absensi.</p>
        </div>
    </div>

    <section class="card attendance-sheet-card">
        <header class="card-heading">
            <div>
                <h2>Lembar absensi kelas</h2>
                <p>Setiap pertemuan memiliki mapel dan tanggal sendiri. Siswa otomatis berstatus Hadir.</p>
            </div>
        </header>

        <div class="attendance-sheet-toolbar">
            <div class="field-group">
                <label for="sheet-class">Kelas</label>
                <select id="sheet-class" v-model="selectedClassId" class="field-control" @change="changeClass">
                    <option value="" disabled>Pilih kelas</option>
                    <option v-for="schoolClass in classes" :key="schoolClass.id" :value="schoolClass.id">
                        {{ schoolClass.name }}
                    </option>
                </select>
            </div>
            <div class="field-group">
                <label for="sheet-subject">Mata pelajaran</label>
                <select
                    id="sheet-subject"
                    v-model="selectedSubjectId"
                    class="field-control"
                    :disabled="subjects.length === 0"
                    @change="changeSheetSelection"
                >
                    <option value="" disabled>{{ subjects.length ? 'Pilih mata pelajaran' : 'Tambahkan mata pelajaran' }}</option>
                    <option v-for="subject in subjects" :key="subject.id" :value="subject.id">{{ subject.name }}</option>
                </select>
            </div>
            <div class="field-group">
                <label for="sheet-date">Tanggal pertemuan</label>
                <input id="sheet-date" v-model="selectedDate" class="field-control" type="date" @change="changeSheetSelection">
            </div>
            <button
                class="button button-primary attendance-sheet-save"
                :disabled="sheetSaving || sheetLoading || !selectedSubjectId || (activeSession && sheet.length === 0)"
                @click="activeSession ? saveSheet() : loadSheet()"
            >
                {{ sheetSaving ? 'Menyimpan...' : activeSession ? 'Simpan absensi kelas' : 'Buka pertemuan' }}
            </button>
        </div>

        <form class="attendance-subject-form" @submit.prevent="addSubject">
            <div class="field-group">
                <label for="new-subject-name">Tambah mata pelajaran untuk kelas ini</label>
                <input
                    id="new-subject-name"
                    v-model="newSubjectName"
                    class="field-control"
                    maxlength="100"
                    placeholder="Contoh: Matematika"
                    :disabled="!selectedClassId || subjectSaving"
                >
            </div>
            <button
                class="button button-secondary"
                type="submit"
                :disabled="!selectedClassId || subjectSaving || !newSubjectName.trim()"
            >
                {{ subjectSaving ? 'Menambahkan...' : 'Tambah mapel' }}
            </button>
        </form>

        <p v-if="activeSession" class="attendance-session-label">
            {{ activeSession.subject.name }} · Pertemuan ke-{{ activeSession.meeting_number }} · {{ activeSession.date }}
        </p>

        <div v-if="sheet.length" class="attendance-sheet-summary">
            <span v-for="status in statuses" :key="status" class="attendance-sheet-total" :class="status">
                {{ status.charAt(0).toUpperCase() + status.slice(1) }}:
                <strong>{{ sheetTotals[status] }}</strong>
            </span>
        </div>

        <PageState
            :loading="sheetLoading"
            :empty="sheet.length === 0 && !sheetError"
            :error="sheetError"
            :empty-message="activeSession ? 'Kelas ini belum memiliki siswa untuk diabsen.' : 'Pilih kelas, mata pelajaran, dan tanggal, lalu tekan Buka pertemuan.'"
        >
            <div class="table-wrap attendance-sheet-table-wrap">
                <table class="data-table attendance-sheet-table">
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Nama siswa</th>
                            <th>NIS</th>
                            <th>Status kehadiran</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(student, index) in sheet" :key="student.id">
                            <td>{{ index + 1 }}</td>
                            <td class="person-primary">{{ student.name }}</td>
                            <td>{{ student.student_number }}</td>
                            <td>
                                <select
                                    v-model="student.status"
                                    class="field-control attendance-status-select"
                                    :aria-label="`Status kehadiran ${student.name}`"
                                >
                                    <option v-for="status in statuses" :key="status" :value="status">
                                        {{ status.charAt(0).toUpperCase() + status.slice(1) }}
                                    </option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </PageState>
    </section>

    <section class="report-grid attendance-history-summary">
        <article v-for="status in statuses" :key="status" class="card report-card" :class="status">
            <span>{{ status.charAt(0).toUpperCase() + status.slice(1) }} di riwayat</span>
            <strong>{{ historyTotals[status] ?? 0 }}</strong>
        </article>
    </section>

    <section class="card table-card">
        <div class="page-heading attendance-history-heading">
            <div>
                <h2>Riwayat absensi</h2>
                <p>Lihat dan kelola catatan absensi yang sudah tersimpan.</p>
            </div>
        </div>
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
                        <option v-for="schoolClass in classes" :key="schoolClass.id" :value="schoolClass.id">
                            {{ schoolClass.name }}
                        </option>
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
            <button
                class="button button-secondary button-small"
                @click="filters = { date: '', class_id: '', status: '' }; applyFilters()"
            >
                Reset filter
            </button>
        </div>
        <PageState
            :loading="loading"
            :error="errorMessage"
            :empty="attendance.length === 0"
            empty-message="Belum ada catatan absensi untuk filter ini."
        >
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Siswa</th>
                            <th>Kelas</th>
                            <th>Tanggal</th>
                            <th>Mapel / pertemuan</th>
                            <th>Status</th>
                            <th>Dicatat oleh</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="record in attendance" :key="record.id">
                            <td>
                                <div class="person-cell">
                                    <span class="person-mini">
                                        {{ record.student.name.split(' ').map((part) => part[0]).slice(0, 2).join('').toUpperCase() }}
                                    </span>
                                    <span>
                                        <span class="person-primary">{{ record.student.name }}</span>
                                        <span class="person-secondary">{{ record.student.student_number }}</span>
                                    </span>
                                </div>
                            </td>
                            <td>{{ record.student.school_class?.name ?? '—' }}</td>
                            <td>{{ record.date }}</td>
                            <td>
                                <template v-if="record.attendance_session">
                                    {{ record.attendance_session.subject?.name ?? '—' }}
                                    · Pertemuan {{ record.attendance_session.meeting_number }}
                                </template>
                                <span v-else>Absensi harian lama</span>
                            </td>
                            <td><span class="badge" :class="record.status">{{ record.status }}</span></td>
                            <td>{{ record.recorder?.name ?? '—' }}</td>
                            <td>
                                <div class="row-actions">
                                    <select
                                        class="field-control attendance-history-status"
                                        :value="record.status"
                                        aria-label="Ubah status absensi"
                                        @change="changeStatus(record, $event)"
                                    >
                                        <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                                    </select>
                                    <button class="button button-danger button-small" @click="removeAttendance(record)">
                                        Hapus
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </PageState>
        <PaginationControls :meta="pagination" @change="currentPage = $event; loadAttendance()" />
    </section>
</template>

<style scoped>
.page-heading h1,
.card-heading h2,
.attendance-history-heading h2 {
    color: #2f2f49;
}

.page-heading p,
.card-heading p,
.attendance-history-heading p {
    color: #9394a8;
}

.attendance-sheet-card,
.table-card,
.attendance-history-summary .report-card {
    border-color: #efeff6;
    border-radius: 14px;
    box-shadow: var(--shadow-card);
}

.attendance-sheet-card {
    margin-bottom: 22px;
    padding: 22px;
}

.card-heading {
    margin-bottom: 20px;
}

.card-heading h2,
.attendance-history-heading h2 {
    margin: 0;
    font-size: 17px;
}

.card-heading p,
.attendance-history-heading p {
    margin: 6px 0 0;
    font-size: 12px;
}

.attendance-sheet-toolbar {
    display: grid;
    grid-template-columns: repeat(3, minmax(150px, 1fr)) auto;
    align-items: end;
    gap: 14px;
    margin-bottom: 16px;
}

.attendance-sheet-toolbar .field-group {
    margin-bottom: 0;
}

.attendance-sheet-save {
    min-height: 46px;
    margin-bottom: 0;
    white-space: nowrap;
}

.attendance-subject-form {
    display: flex;
    align-items: end;
    gap: 12px;
    max-width: 620px;
    margin: 0 0 16px;
}

.attendance-subject-form .field-group {
    flex: 1;
    margin-bottom: 0;
}

.attendance-session-label {
    margin: 0 0 15px;
    color: #5144bd;
    font-size: 12px;
    font-weight: 700;
}

.attendance-sheet-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 9px;
    margin: 0 0 15px;
}

.attendance-sheet-total {
    padding: 7px 10px;
    border-radius: 7px;
    color: #55566f;
    background: #f7f7fc;
    font-size: 11px;
}

.attendance-sheet-total.hadir,
.attendance-history-summary .report-card.hadir strong {
    color: #6759d6;
    background: #f0efff;
}

.attendance-sheet-total.izin,
.attendance-history-summary .report-card.izin strong {
    color: #bd7c2e;
    background: #fff5e8;
}

.attendance-sheet-total.sakit,
.attendance-history-summary .report-card.sakit strong {
    color: #568bd7;
    background: #edf4ff;
}

.attendance-sheet-total.alpa,
.attendance-history-summary .report-card.alpa strong {
    color: #d96a83;
    background: #fff0f3;
}

.attendance-sheet-table-wrap {
    max-height: 62vh;
}

.attendance-sheet-table th {
    color: #77788f;
    background: #fafaff;
}

.attendance-sheet-table td {
    border-top-color: #f0eff9;
    color: #55566f;
}

.attendance-sheet-table tr:hover td {
    background: #fafaff;
}

.attendance-sheet-table th:first-child,
.attendance-sheet-table td:first-child {
    width: 60px;
    color: var(--color-muted);
    text-align: center;
}

.attendance-status-select {
    width: min(190px, 100%);
    min-height: 38px;
    padding: 7px 10px;
    text-transform: capitalize;
}

.attendance-history-summary {
    margin: 0 0 17px;
}

.attendance-history-summary .report-card span {
    color: #82839a;
}

.attendance-history-summary .report-card strong {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 8px;
}

.attendance-history-heading {
    align-items: center;
    margin: 0 0 14px;
    padding: 0 20px;
}

.attendance-history-status {
    min-width: 105px;
    min-height: 31px;
    padding: 5px 8px;
    font-size: 11px;
}

@media (max-width: 700px) {
    .attendance-sheet-toolbar {
        grid-template-columns: 1fr;
    }

    .attendance-sheet-save {
        width: 100%;
    }

    .attendance-subject-form {
        align-items: stretch;
        flex-direction: column;
    }

    .attendance-sheet-card {
        padding: 16px;
    }

    .attendance-history-heading {
        padding-right: 16px;
        padding-left: 16px;
    }
}
</style>
