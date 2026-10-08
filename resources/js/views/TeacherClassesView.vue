<script setup>
import { onMounted, ref } from 'vue';
import PageState from '../components/PageState.vue';
import PaginationControls from '../components/PaginationControls.vue';
import { getTeacherClasses } from '../services/api';
const classes = ref([]);
const pagination = ref(null);
const loading = ref(true);
const errorMessage = ref('');
const currentPage = ref(1);
async function loadClasses(page = 1) {
    currentPage.value = page;
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await getTeacherClasses({ page: currentPage.value });
        classes.value = response.data;
        pagination.value = response;
    } catch {
        errorMessage.value = 'Daftar kelas tidak dapat dimuat. Periksa koneksi lalu coba lagi.';
    } finally {
        loading.value = false;
    }
}
onMounted(() => loadClasses());
</script>
<template>
    <div class="page-heading">
        <div><h1>Kelas yang diajar</h1><p>Daftar kelas yang ditugaskan kepadamu.</p></div>
    </div>
    <PageState :loading="loading" :error="errorMessage" :empty="classes.length === 0" empty-message="Belum ada kelas yang ditugaskan.">
        <section class="stat-grid">
            <article v-for="schoolClass in classes" :key="schoolClass.id" class="card stat-card">
                <div class="stat-top"><span>Kelas</span><span class="stat-icon">▦</span></div>
                <div class="stat-value" style="font-size: 22px;">{{ schoolClass.name }}</div>
                <div class="stat-note">{{ schoolClass.students_count }} siswa terdaftar</div>
            </article>
        </section>
    </PageState>
    <PaginationControls :meta="pagination" @change="loadClasses" />
</template>