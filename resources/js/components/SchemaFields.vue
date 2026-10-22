<script setup>
defineProps({ fields: { type: Array, default: () => [] }, model: { type: Object, required: true }, disabled: Boolean });
const options = field => (field.options || []).map(option => typeof option === 'object' ? option : { value: option, label: option });
</script>
<template>
  <div class="stack-field schema-fields">
    <label v-for="field in fields" :key="field.key" :class="{ checkline: field.type === 'boolean' }">
      <input v-if="field.type === 'boolean'" type="checkbox" v-model="model[field.key]" :disabled="disabled" />
      {{ field.label || field.key }}
      <select v-if="field.type === 'select'" v-model="model[field.key]" :disabled="disabled"><option v-for="option in options(field)" :key="option.value" :value="option.value">{{ option.label }}</option></select>
      <textarea v-else-if="field.type === 'textarea'" v-model="model[field.key]" :maxlength="field.max" rows="5" :disabled="disabled" />
      <input v-else-if="field.type === 'number'" type="number" v-model.number="model[field.key]" :min="field.min" :max="field.max" :disabled="disabled" />
      <input v-else-if="field.type !== 'boolean'" v-model="model[field.key]" type="text" :maxlength="field.max" :required="field.required" :disabled="disabled" />
      <small v-if="field.help">{{ field.help }}</small>
    </label>
  </div>
</template>
