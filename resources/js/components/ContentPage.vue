<script setup>
import SectionLayout from './SectionLayout.vue';
defineProps({ document: { type: Object, required: true } });
</script>
<template><div class="rendered-document"><SectionLayout v-for="section in (document.sections || [])" :key="section.id" :section="section" /></div></template>
