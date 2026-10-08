<script setup>
defineProps({
    meta: { type: Object, default: null },
});
const emit = defineEmits(['change']);
</script>
<template>
    <div v-if="meta && meta.last_page > 1" class="pagination">
        <span>Halaman {{ meta.current_page }} dari {{ meta.last_page }} · {{ meta.total }} data</span>
        <button
            class="button button-secondary button-small"
            :disabled="meta.current_page <= 1"
            @click="emit('change', meta.current_page - 1)"
        >
            Sebelumnya
        </button>
        <button
            class="button button-secondary button-small"
            :disabled="meta.current_page >= meta.last_page"
            @click="emit('change', meta.current_page + 1)"
        >
            Berikutnya
        </button>
    </div>
</template>
