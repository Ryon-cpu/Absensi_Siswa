<script setup>
defineProps({
    loading: { type: Boolean, default: false },
    error: { type: String, default: '' },
    empty: { type: Boolean, default: false },
    emptyMessage: { type: String, default: 'Belum ada data untuk ditampilkan.' },
});
</script>
<template>
    <div v-if="loading" class="loading-state">
        <div>
            <div class="spinner"></div>
            Memuat data...
        </div>
    </div>
    <div v-else-if="error" class="notice-error" role="alert">{{ error }}</div>
    <div v-else-if="empty" class="empty-state">{{ emptyMessage }}</div>
    <slot v-else />
</template>
