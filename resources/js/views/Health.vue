<script setup>
import { computed, onMounted, ref } from 'vue';
import { get, failure } from '../lib';
import StudioFrame from '../components/StudioFrame.vue';

const props = defineProps({ user: { type: Object, required: true } });
const emit = defineEmits(['logout']);
const report = ref(null);
const loading = ref(false);
const error = ref('');
const owner = computed(() => props.user.role === 'owner');
const names = { database: 'Database', storage: 'Storage', cache: 'Cache', php: 'PHP runtime', scheduler: 'Scheduler', queue: 'Queue', plugins: 'Plugins', environment: 'Environment' };
const statuses = { ok: 'Passed', warning: 'Needs attention', error: 'Failed' };
const checkedAt = computed(() => report.value?.checked_at ? new Date(report.value.checked_at).toLocaleString() : '');
async function refresh() {
  if (!owner.value || loading.value) return;
  loading.value = true;
  error.value = '';
  try { report.value = await get('/studio/health'); }
  catch (cause) { error.value = failure(cause); }
  finally { loading.value = false; }
}
onMounted(refresh);
</script>

<template>
  <StudioFrame :user="user" page-title="System health" subtitle="Runtime checks for this FastFind installation." @logout="emit('logout')">
    <template #toolbar><button v-if="owner" class="btn outline" :disabled="loading" @click="refresh">{{ loading ? 'Checking…' : 'Refresh checks' }}</button></template>
    <section v-if="!owner" class="admin-panel"><h2>Owner access required</h2><p>System health information is restricted to workspace owners.</p></section>
    <template v-else>
      <p v-if="error" class="alert error" role="alert">{{ error }} <button class="text-link" :disabled="loading" @click="refresh">Try again</button></p>
      <p v-if="loading" role="status" class="admin-loading">Checking the application environment…</p>
      <template v-if="report && !loading">
        <section class="admin-panel" aria-label="System health summary">
          <div class="panel-heading"><h2>{{ statuses[report.status] || report.status }}</h2><span class="state-pill">FastFind {{ report.version }}</span></div>
          <p>Last checked <time :datetime="report.checked_at">{{ checkedAt }}</time>.</p>
          <p>These checks report current runtime conditions. External queue worker liveness is not instrumented; a configured worker is not assumed to be running.</p>
        </section>
        <div class="plugin-grid">
          <section v-for="check in report.checks" :key="check.id" class="admin-panel" :aria-labelledby="`health-${check.id}`">
            <div class="panel-heading"><h2 :id="`health-${check.id}`">{{ names[check.id] || check.id }}</h2><span class="state-pill">{{ statuses[check.status] || check.status }}</span></div>
            <p>{{ check.detail }}</p>
            <p v-if="check.last_run">Last run: <time :datetime="check.last_run">{{ new Date(check.last_run).toLocaleString() }}</time></p>
            <p v-if="check.failed_jobs !== undefined">Failed jobs: {{ check.failed_jobs }}</p>
          </section>
        </div>
      </template>
    </template>
  </StudioFrame>
</template>
