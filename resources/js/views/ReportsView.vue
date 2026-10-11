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
        ['Persentase hadir', `${report.value.attendance_rate}%`],
    ];
}
function getTrendRows() {
    return [
        ['Tanggal', 'Hadir', 'Izin', 'Sakit', 'Alpa', 'Total', 'Persentase hadir'],
        ...report.value.daily_trends.map((day) => [
            day.date,
            day.hadir,
            day.izin,
            day.sakit,
            day.alpa,
            day.total,
            `${day.attendance_rate}%`,
        ]),
    ];
}
function getFrequentStudentRows() {
    return [
        ['NIS', 'Nama siswa', 'Kelas', 'Alpa', 'Izin', 'Sakit', 'Total tidak hadir'],
        ...report.value.frequent_students.map((student) => [
            student.student_number,
            student.name,
            student.class_name,
            student.alpa,
            student.izin,
            student.sakit,
            student.absence_count,
        ]),
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
        XLSX.utils.book_append_sheet(workbook, XLSX.utils.aoa_to_sheet(getTrendRows()), 'Tren Harian');
        XLSX.utils.book_append_sheet(workbook, XLSX.utils.aoa_to_sheet(getFrequentStudentRows()), 'Siswa Sering Absen');
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
        let nextY = document.lastAutoTable.finalY + 12;
        nextY = addPdfSection(document, autoTable, 'Tren Kehadiran Harian', getTrendRows(), nextY);
        addPdfSection(document, autoTable, 'Siswa Sering Tidak Hadir', getFrequentStudentRows(), nextY);
        document.save(fileName);
    } catch {
        notify('File PDF tidak dapat dibuat. Coba lagi.', 'error');
    } finally {
        exporting.value = '';
    }
}
function addPdfSection(document, autoTable, title, rows, startY) {
    let sectionStartY = startY;
    if (sectionStartY > 250) {
        document.addPage();
        sectionStartY = 20;
    }
    document.setFontSize(12);
    document.text(title, 14, sectionStartY);
    autoTable(document, {
        startY: sectionStartY + 5,
        head: [rows[0]],
        body: rows.slice(1),
        styles: { fontSize: 8 },
    });
    return document.lastAutoTable.finalY + 12;
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
        <div><h1>Laporan kehadiran</h1><p>Atur tanggal awal dan akhir semester untuk melihat tren serta rekap siswa.</p></div>
    </div>
    <section class="card" style="padding: 18px 20px;">
        <form class="filter-row" @submit.prevent="loadReport">
            <div class="field-group"><label for="report-from">Awal semester</label><input id="report-from" v-model="filters.from" class="field-control" type="date"></div>
            <div class="field-group"><label for="report-to">Akhir semester</label><input id="report-to" v-model="filters.to" class="field-control" type="date"></div>
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
        <p class="field-hint report-filter-hint">Tentukan tanggal awal dan akhir semester. Jika dikosongkan, laporan menampilkan seluruh riwayat.</p>
    </section>
    <PageState :loading="loading" :error="errorMessage">
        <section v-if="report" class="report-grid">
            <article class="card report-card"><span>Total catatan</span><strong>{{ report.total }}</strong></article>
            <article v-for="status in reportStatuses" :key="status.key" class="card report-card">
                <span>{{ status.label }}</span><strong>{{ report[status.key] }}</strong>
            </article>
            <article class="card report-card"><span>Persentase hadir</span><strong>{{ report.attendance_rate }}%</strong></article>
        </section>
        <section v-if="report" class="report-insights-grid">
            <article class="card report-panel">
                <div class="report-panel-heading">
                    <div><h2>Tren kehadiran harian</h2><p>Persentase hadir dihitung dari catatan pada setiap tanggal.</p></div>
                </div>
                <div v-if="report.daily_trends.length" class="trend-list">
                    <div v-for="day in report.daily_trends" :key="day.date" class="trend-day">
                        <div class="trend-day-heading"><strong>{{ day.date }}</strong><span>{{ day.attendance_rate }}% hadir</span></div>
                        <div class="trend-track"><span class="trend-fill" :style="{ width: `${day.attendance_rate}%` }"></span></div>
                        <div class="trend-counts"><span>Hadir {{ day.hadir }}</span><span>Izin {{ day.izin }}</span><span>Sakit {{ day.sakit }}</span><span>Alpa {{ day.alpa }}</span></div>
                    </div>
                </div>
                <div v-else class="empty-state">Belum ada catatan absensi pada periode ini.</div>
            </article>
            <article class="card report-panel">
                <div class="report-panel-heading">
                    <div><h2>Siswa sering tidak hadir</h2><p>10 siswa teratas berdasarkan jumlah alpa, izin, dan sakit.</p></div>
                </div>
                <div v-if="report.frequent_students.length" class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Siswa</th><th>Kelas</th><th>Alpa</th><th>Izin</th><th>Sakit</th><th>Total</th></tr></thead>
                        <tbody>
                            <tr v-for="student in report.frequent_students" :key="student.student_id">
                                <td><div class="person-cell"><div><div class="person-primary">{{ student.name }}</div><div class="person-secondary">{{ student.student_number }}</div></div></div></td>
                                <td>{{ student.class_name }}</td>
                                <td>{{ student.alpa }}</td>
                                <td>{{ student.izin }}</td>
                                <td>{{ student.sakit }}</td>
                                <td><strong>{{ student.absence_count }}</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="empty-state">Tidak ada data siswa yang alpa, izin, atau sakit pada periode ini.</div>
            </article>
        </section>
    </PageState>
</template>