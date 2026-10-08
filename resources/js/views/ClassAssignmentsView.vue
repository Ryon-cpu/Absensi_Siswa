<script setup>
import { onMounted, ref } from 'vue';
import ModalDialog from '../components/ModalDialog.vue';
import PaginationControls from '../components/PaginationControls.vue';
import PageState from '../components/PageState.vue';
import { notify } from '../notifications';
import {
    createClassAssignment,
    deleteClassAssignment,
    getClassAssignments,
    getClasses,
    getTeachers,
} from '../services/api';
const assignments = ref([]);
const pagination = ref(null);
const currentPage = ref(1);
const classes = ref([]);
const teachers = ref([]);
const loading = ref(true);
const errorMessage = ref('');
const modalOpen = ref(false);
const saving = ref(false);
const formError = ref('');
const form = ref({ class_id: '', teacher_id: '' });
async function loadData() {
    loading.value = true;
    errorMessage.value = '';
    try {
        const [assignmentPage, classPage, teacherPage] = await Promise.all([
            getClassAssignments({ page: currentPage.value }),
            getClasses(),
            getTeachers(),
        ]);
        assignments.value = assignmentPage.data;
        pagination.value = assignmentPage;
        classes.value = classPage.data;
        teachers.value = teacherPage.data;
    } catch {
        errorMessage.value = 'Data penugasan tidak dapat dimuat. Periksa koneksi lalu coba lagi.';
    } finally {
        loading.value = false;
    }
}
async function saveAssignment() {
    saving.value = true;
    formError.value = '';
    try {
        await createClassAssignment({
            class_id: Number(form.value.class_id),
            teacher_id: Number(form.value.teacher_id),
        });
        modalOpen.value = false;
        form.value = { class_id: '', teacher_id: '' };
        notify('Penugasan guru berhasil ditambahkan.');
        await loadData();
    } catch (error) {
        formError.value = Object.values(error.response?.data?.errors ?? {}).flat()[0]
            ?? error.response?.data?.message
            ?? 'Penugasan tidak dapat disimpan.';
    } finally {
        saving.value = false;
    }
}
async function removeAssignment(assignment) {
    if (!window.confirm(`Hapus penugasan ${assignment.teacher.name} dari ${assignment.school_class.name}?`)) return;
    try {
        await deleteClassAssignment(assignment.id);
        notify('Penugasan berhasil dihapus.');
        await loadData();
    } catch (error) {
        notify(error.response?.data?.message ?? 'Penugasan tidak dapat dihapus.', 'error');
    }
}
onMounted(loadData);
</script>
<template>
    <div class="page-heading">
        <div><h1>Penugasan guru</h1><p>Hubungkan guru dengan kelas yang menjadi tanggung jawabnya.</p></div>
        <button class="button button-primary" @click="modalOpen = true">＋ Tugaskan guru</button>
    </div>
    <section class="card table-card">
        <PageState :loading="loading" :error="errorMessage" :empty="assignments.length === 0" empty-message="Belum ada penugasan guru.">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Guru</th><th>Kelas</th><th>Aksi</th></tr></thead>
                    <tbody>
                        <tr v-for="assignment in assignments" :key="assignment.id">
                            <td>
                                <div class="person-cell">
                                    <span class="person-mini">{{ assignment.teacher.name.split(' ').map((part) => part[0]).slice(0, 2).join('').toUpperCase() }}</span>
                                    <span><span class="person-primary">{{ assignment.teacher.name }}</span><span class="person-secondary">Guru</span></span>
                                </div>
                            </td>
                            <td><span class="badge">{{ assignment.school_class.name }}</span></td>
                            <td><button class="button button-danger button-small" @click="removeAssignment(assignment)">Hapus</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </PageState>
        <PaginationControls :meta="pagination" @change="currentPage = $event; loadData()" />
    </section>
    <ModalDialog v-if="modalOpen" title="Tugaskan guru" description="Pilih guru dan kelas untuk membuat penugasan." @close="modalOpen = false">
        <form @submit.prevent="saveAssignment">
            <div v-if="formError" class="notice-error" role="alert">{{ formError }}</div>
            <div class="field-group">
                <label for="assignment-teacher">Guru</label>
                <select id="assignment-teacher" v-model="form.teacher_id" class="field-control" required>
                    <option value="" disabled>Pilih guru</option>
                    <option v-for="teacher in teachers" :key="teacher.id" :value="teacher.id">{{ teacher.name }}</option>
                </select>
            </div>
            <div class="field-group">
                <label for="assignment-class">Kelas</label>
                <select id="assignment-class" v-model="form.class_id" class="field-control" required>
                    <option value="" disabled>Pilih kelas</option>
                    <option v-for="schoolClass in classes" :key="schoolClass.id" :value="schoolClass.id">{{ schoolClass.name }}</option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="button button-secondary" @click="modalOpen = false">Batal</button>
                <button class="button button-primary" type="submit" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Simpan penugasan' }}</button>
            </div>
        </form>
    </ModalDialog>
</template>