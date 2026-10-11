<script setup>
import { computed, onMounted, ref } from 'vue';
import PageState from '../components/PageState.vue';
import { getAttendanceReport, getClasses } from '../services/api';
import { notify } from '../notifications';
const report = ref(null);
const appliedFilters = ref(null);
const classes = ref([]);
const loading = ref(true);
const exporting = ref('');
const errorMessage = ref('');
const filters = ref({ from: '', to: '', class_id: '' });
const canExport = computed(() => Boolean(
    report.value
    && appliedFilters.value
    && Object.keys(filters.value).every((key) => String(filters.value[key]) === String(appliedFilters.value[key])),
));
const reportStatuses = [
    { label: 'Hadir', key: 'hadir', icon: '✓' },
    { label: 'Izin', key: 'izin', icon: '↗' },
    { label: 'Sakit', key: 'sakit', icon: '♡' },
    { label: 'Alpa', key: 'alpa', icon: '◷' },
];
function getExportDetails(extension) {
    const activeFilters = appliedFilters.value;
    const selectedClass = classes.value.find((schoolClass) => String(schoolClass.id) === String(activeFilters.class_id));
    const className = selectedClass?.name ?? 'Semua kelas';
    const safeClassName = className
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9_-]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .toLowerCase() || 'semua-kelas';
    const period = activeFilters.from || activeFilters.to
        ? `${activeFilters.from || 'awal'} s.d. ${activeFilters.to || 'akhir'}`
        : 'Semua tanggal';

    return {
        className,
        fileName: `laporan-absensi-${activeFilters.from || 'awal'}-${activeFilters.to || 'akhir'}-${safeClassName}.${extension}`,
        period,
    };
}
function getReportRows() {
    return [
        ['Status', 'Jumlah'],
        ...reportStatuses.map((status) => [status.label, report.value[status.key]]),
        ['Total catatan', report.value.total],
    ];
}
async function exportToExcel() {
    if (exporting.value) {
        return;
    }
    if (loading.value) {
        notify('Tunggu laporan selesai dimuat sebelum mengekspor.', 'error');
        return;
    }
    exporting.value = 'excel';
    try {
        if (!canExport.value) {
            const loaded = await loadReport();
            if (!loaded || !canExport.value) {
                notify('Laporan sesuai filter terbaru tidak dapat dimuat.', 'error');
                return;
            }
        }
        const XLSX = await import('@e965/xlsx');
        const { className, fileName, period } = getExportDetails('xlsx');
        const worksheet = XLSX.utils.aoa_to_sheet([
            ['Laporan Kehadiran Siswa'],
            ['Periode', period],
            ['Kelas', `'${className}`],
            [],
            ...getReportRows(),
        ]);
        worksheet['!cols'] = [{ wch: 24 }, { wch: 18 }];
        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, worksheet, 'Laporan Absensi');
        XLSX.writeFile(workbook, fileName);
    } catch {
        notify('File Excel tidak dapat dibuat. Coba lagi.', 'error');
    } finally {
        exporting.value = '';
    }
}
async function exportToPdf() {
    if (exporting.value) {
        return;
    }
    if (loading.value) {
        notify('Tunggu laporan selesai dimuat sebelum mengekspor.', 'error');
        return;
    }
    exporting.value = 'pdf';
    try {
        if (!canExport.value) {
            const loaded = await loadReport();
            if (!loaded || !canExport.value) {
                notify('Laporan sesuai filter terbaru tidak dapat dimuat.', 'error');
                return;
            }
        }
        const [{ jsPDF }, { default: autoTable }] = await Promise.all([
            import('jspdf'),
            import('jspdf-autotable'),
        ]);
        const { className, fileName, period } = getExportDetails('pdf');
        const document = new jsPDF();
        document.setFontSize(16);
        document.text('Laporan Kehadiran Siswa', 14, 18);
        document.setFontSize(10);
        document.text(`Periode: ${period}`, 14, 27);
        document.text(`Kelas: ${className}`, 14, 33);
        const reportRows = getReportRows();
        autoTable(document, {
            startY: 40,
            head: [reportRows[0]],
            body: reportRows.slice(1),
        });
        document.save(fileName);
    } catch {
        notify('File PDF tidak dapat dibuat. Coba lagi.', 'error');
    } finally {
        exporting.value = '';
    }
}
async function loadReport() {
    loading.value = true;
    report.value = null;
    appliedFilters.value = null;
    errorMessage.value = '';
    try {
        const requestedFilters = { ...filters.value };
        const query = {
            ...(requestedFilters.from ? { from: requestedFilters.from } : {}),
            ...(requestedFilters.to ? { to: requestedFilters.to } : {}),
            ...(requestedFilters.class_id ? { class_id: requestedFilters.class_id } : {}),
        };
        report.value = await getAttendanceReport(query);
        appliedFilters.value = requestedFilters;
        return true;
    } catch (error) {
        errorMessage.value = Object.values(error.response?.data?.errors ?? {}).flat()[0]
            ?? 'Laporan tidak dapat dimuat. Periksa filter dan koneksi lalu coba lagi.';
        return false;
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
            <button class="button button-secondary" type="button" :disabled="Boolean(exporting)" @click="exportToExcel">{{ exporting === 'excel' ? 'Menyiapkan Excel...' : 'Ekspor Excel' }}</button>
            <button class="button button-primary" type="button" :disabled="Boolean(exporting)" @click="exportToPdf">{{ exporting === 'pdf' ? 'Menyiapkan PDF...' : 'Ekspor PDF' }}</button>
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