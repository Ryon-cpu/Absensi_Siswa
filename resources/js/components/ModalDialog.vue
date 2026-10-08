<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
});
const emit = defineEmits(['close']);
</script>
<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <section class="modal" role="dialog" aria-modal="true" :aria-label="title">
            <header class="modal-header">
                <div>
                    <h2>{{ title }}</h2>
                    <p v-if="description">{{ description }}</p>
                </div>
                <button class="icon-button" aria-label="Tutup dialog" @click="emit('close')">×</button>
            </header>
            <slot />
        </section>
    </div>
</template>
