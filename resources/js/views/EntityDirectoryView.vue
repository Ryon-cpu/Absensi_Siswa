<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import ModalDialog from '../components/ModalDialog.vue';
import PaginationControls from '../components/PaginationControls.vue';
import PageState from '../components/PageState.vue';
import { notify } from '../notifications';
import {
    createClass,
    createStudent,
    createTeacher,
    deleteClass,
    deleteStudent,
    deleteTeacher,
    getClasses,
    getStudents,
    getTeachers,
    updateClass,
    updateStudent,
    updateTeacher,
} from '../services/api';
const route = useRoute();
const resource = computed(() => route.meta.resource);
const config = computed(() => ({
    students: {
        title: 'Data siswa',
        description: 'Kelola identitas siswa dan penempatannya di kelas.',
        singular: 'siswa',
        fields: [
            { name: 'student_number', label: 'Nomor induk siswa', type: 'text', required: true },
            { name: 'name', label: 'Nama siswa', type: 'text', required: true },
            { name: 'class_id', label: 'Kelas', type: 'select', required: true },
        ],
        columns: ['Nomor induk', 'Nama siswa', 'Kelas'],
    },
    classes: {
        title: 'Data kelas',
        description: 'Lihat dan kelola kelas yang tersedia di sekolah.',
        singular: 'kelas',
        fields: [{ name: 'name', label: 'Nama kelas', type: 'text', required: true }],
        columns: ['Nama kelas', 'Jumlah siswa', 'Jumlah guru'],
    },
    teachers: {
        title: 'Data guru',
        description: 'Kelola profil guru yang memiliki akun untuk masuk ke sistem.',
        singular: 'guru',
        fields: [
            { name: 'name', label: 'Nama guru', type: 'text', required: true },
            { name: 'email', label: 'Email akun', type: 'email', required: true },
            { name: 'password', label: 'Kata sandi awal', type: 'password', required: true },
        ],
        columns: ['Nama guru', 'Email', 'Kelas diajar'],
    },
}[resource.value] ?? {}));
const rows = ref([]);
const classes = ref([]);
const pagination = ref(null);
const currentPage = ref(1);
const loading = ref(true);
const errorMessage = ref('');
const search = ref('');
const modalOpen = ref(false);
const editingItem = ref(null);
const saving = ref(false);
const formError = ref('');
const form = ref({});
const visibleRows = computed(() => {
    const term = search.value.trim().toLocaleLowerCase('id-ID');
    if (!term) return rows.value;
    return rows.value.filter((row) => JSON.stringify(row).toLocaleLowerCase('id-ID').includes(term));
});
function rowName(row) {
    if (resource.value === 'students') return row.name;
    if (resource.value === 'teachers') return row.name;
    return row.name;
}
function rowInitials(row) {
    return rowName(row)?.split(' ').map((part) => part[0]).slice(0, 2).join('').toUpperCase() ?? '—';
}
function fieldValue(field, row) {
    if (field === 'Nomor induk') return row.student_number;
    if (field === 'Nama siswa' || field === 'Nama guru' || field === 'Nama kelas') return row.name;
    if (field === 'Kelas') return row.school_class?.name ?? 'Belum ditentukan';
    if (field === 'Jumlah siswa') return row.students_count ?? 0;
    if (field === 'Jumlah guru') return row.teachers_count ?? 0;
    if (field === 'Email') return row.user?.email ?? '—';
    if (field === 'Kelas diajar') return row.school_classes?.map((schoolClass) => schoolClass.name).join(', ') || 'Belum ditugaskan';
    return '—';
}
async function loadRows() {
    loading.value = true;
    errorMessage.value = '';
    try {
        if (resource.value === 'students') {
            const [studentsPage, classesPage] = await Promise.all([
                getStudents({ page: currentPage.value }),
                getClasses(),
            ]);
            rows.value = studentsPage.data;
            classes.value = classesPage.data;
            pagination.value = studentsPage;
        } else if (resource.value === 'classes') {
            const classesPage = await getClasses({ page: currentPage.value });
            rows.value = classesPage.data;
            pagination.value = classesPage;
        } else {
            const teachersPage = await getTeachers({ page: currentPage.value });
            rows.value = teachersPage.data;
            pagination.value = teachersPage;
        }
    } catch {
        errorMessage.value = 'Data tidak dapat dimuat. Periksa koneksi lalu coba lagi.';
    } finally {
        loading.value = false;
    }
}
function openCreateForm() {
    editingItem.value = null;
    form.value = Object.fromEntries(config.value.fields.map((field) => [field.name, '']));
    formError.value = '';
    modalOpen.value = true;
}
function openEditForm(row) {
    editingItem.value = row;
    form.value = Object.fromEntries(config.value.fields.map((field) => {
        if (field.name === 'class_id') return [field.name, row.class_id];
        if (field.name === 'password') return [field.name, ''];
        if (field.name === 'email') return [field.name, row.user?.email ?? ''];
        return [field.name, row[field.name] ?? ''];
    }));
    formError.value = '';
    modalOpen.value = true;
}
async function saveItem() {
    saving.value = true;
    formError.value = '';
    try {
        const payload = { ...form.value };
        if (resource.value === 'students' && !payload.class_id) {
            throw new Error('Pilih kelas siswa terlebih dahulu.');
        }
        if (resource.value === 'teachers' && editingItem.value && !payload.password) {
            delete payload.password;
        }
        if (resource.value === 'students') {
            payload.class_id = Number(payload.class_id);
        }
        const actions = {
            students: editingItem.value ? updateStudent : createStudent,
            classes: editingItem.value ? updateClass : createClass,
            teachers: editingItem.value ? updateTeacher : createTeacher,
        };
        const action = actions[resource.value];
        if (editingItem.value) {
            await action(editingItem.value.id, payload);
        } else {
            await action(payload);
        }
        modalOpen.value = false;
        notify(`${config.value.singular} berhasil ${editingItem.value ? 'diperbarui' : 'ditambahkan'}.`);
        await loadRows();
    } catch (error) {
        if (error.response?.data?.errors) {
            formError.value = Object.values(error.response.data.errors).flat()[0];
        } else if (error.response?.data?.message) {
            formError.value = error.response.data.message;
        } else {
            formError.value = error.message || 'Data tidak dapat disimpan. Periksa isian lalu coba lagi.';
        }
    } finally {
        saving.value = false;
    }
}
async function removeItem(row) {
    if (!window.confirm(`Hapus ${config.value.singular} "${rowName(row)}"?`)) return;
    try {
        const actions = { students: deleteStudent, classes: deleteClass, teachers: deleteTeacher };
        await actions[resource.value](row.id);
        notify(`${config.value.singular} berhasil dihapus.`);
        await loadRows();
    } catch (error) {
        notify(error.response?.data?.message ?? 'Data tidak dapat dihapus.', 'error');
    }
}
onMounted(loadRows);
watch(resource, () => {
    currentPage.value = 1;
    loadRows();
});
</script>
<template>
    <div class="page-heading">
        <div><h1>{{ config.title }}</h1><p>{{ config.description }}</p></div>
        <div class="page-actions">
            <button class="button button-primary" @click="openCreateForm">＋ Tambah {{ config.singular }}</button>
        </div>
    </div>
    <section class="card table-card">
        <div class="table-tools">
            <div class="search-control">
                <span aria-hidden="true">⌕</span>
                <input v-model="search" class="field-control" type="search" :placeholder="`Cari ${config.singular}...`">
            </div>
            <span class="person-secondary">{{ rows.length }} data di halaman ini</span>
        </div>
        <PageState :loading="loading" :error="errorMessage" :empty="visibleRows.length === 0" empty-message="Belum ada data. Tambahkan data pertama untuk memulai.">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th v-for="column in config.columns" :key="column">{{ column }}</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in visibleRows" :key="row.id">
                            <td>
                                <div class="person-cell">
                                    <span class="person-mini">{{ rowInitials(row) }}</span>
                                    <span>
                                        <span class="person-primary">{{ resource === 'students' ? row.student_number : row.name }}</span>
                                        <span class="person-secondary">{{ resource === 'students' ? row.name : `ID #${row.id}` }}</span>
                                    </span>
                                </div>
                            </td>
                            <td v-for="column in config.columns.slice(1)" :key="column">{{ fieldValue(column, row) }}</td>
                            <td>
                                <div class="row-actions">
                                    <button class="button button-secondary button-small" @click="openEditForm(row)">Ubah</button>
                                    <button class="button button-danger button-small" @click="removeItem(row)">Hapus</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="visibleRows.length === 0" class="empty-state">Tidak ada data yang cocok dengan pencarian.</div>
        </PageState>
        <PaginationControls :meta="pagination" @change="currentPage = $event; loadRows()" />
    </section>
    <ModalDialog
        v-if="modalOpen"
        :title="`${editingItem ? 'Ubah' : 'Tambah'} ${config.singular}`"
        description="Isi informasi dengan benar sebelum menyimpan."
        @close="modalOpen = false">
        <form @submit.prevent="saveItem">
            <div v-if="formError" class="notice-error" role="alert">{{ formError }}</div>
            <div v-for="field in config.fields" :key="field.name" class="field-group">
                <label :for="`field-${field.name}`">{{ field.label }}</label>
                <select
                    v-if="field.type === 'select'"
                    :id="`field-${field.name}`"
                    v-model="form[field.name]"
                    class="field-control"
                    :required="field.required">
                    <option value="" disabled>Pilih kelas</option>
                    <option v-for="schoolClass in classes" :key="schoolClass.id" :value="schoolClass.id">{{ schoolClass.name }}</option>
                </select>
                <input
                    v-else
                    :id="`field-${field.name}`"
                    v-model="form[field.name]"
                    class="field-control"
                    :type="field.type"
                    :required="field.required && !(editingItem && field.name === 'password')"
                    :placeholder="editingItem && field.name === 'password' ? 'Kosongkan jika tidak diubah' : field.label"
                    :autocomplete="field.name === 'password' ? 'new-password' : 'off'">
            </div>
            <div class="modal-actions">
                <button class="button button-secondary" type="button" @click="modalOpen = false">Batal</button>
                <button class="button button-primary" type="submit" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
            </div>
        </form>
    </ModalDialog>
</template>