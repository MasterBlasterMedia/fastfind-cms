<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { post, failure } from '../lib';

const props = defineProps({ resetPassword: { type: Function, required: true } });
const route = useRoute();
const router = useRouter();
const isReset = computed(() => route.path === '/studio/reset-password');
const email = ref('');
const token = ref('');
const password = ref('');
const confirmation = ref('');
const busy = ref(false);
const error = ref('');
const message = ref('');
const completed = ref(false);
const feedback = ref(null);
const validLink = computed(() => token.value.length > 0 && email.value.length > 0);
let generation = 0;
let mounted = true;
onBeforeUnmount(() => { mounted = false; generation++; });

watch(() => route.fullPath, () => {
  if (completed.value && isReset.value && !route.query.token && !route.query.email) return;
  generation++;
  busy.value = false;
  email.value = typeof route.query.email === 'string' ? route.query.email : '';
  token.value = typeof route.query.token === 'string' && route.query.token.length <= 256 ? route.query.token : '';
  password.value = confirmation.value = error.value = message.value = '';
  completed.value = false;
}, { immediate: true, flush: 'sync' });

const isCurrent = submission => mounted && generation === submission;
async function focusFeedback(submission) { await nextTick(); if (isCurrent(submission)) feedback.value?.focus(); }
async function submit() {
  if (busy.value || completed.value) return;
  const submission = generation;
  const resetting = isReset.value;
  error.value = message.value = '';
  if (resetting && (!validLink.value || password.value.length < 12 || password.value !== confirmation.value)) {
    error.value = !validLink.value ? 'This reset link is incomplete. Request a new link.'
      : password.value.length < 12 ? 'Use a password with at least 12 characters.' : 'The passwords do not match.';
    await focusFeedback(submission);
    return;
  }
  busy.value = true;
  try {
    // The parent completes session cleanup even if navigation unmounts this form.
    const result = await (resetting
      ? props.resetPassword({ email: email.value, token: token.value, password: password.value, password_confirmation: confirmation.value })
      : post('/password/forgot', { email: email.value }));
    if (!isCurrent(submission)) return;
    message.value = result.message;
    completed.value = true;
    if (resetting) {
      token.value = password.value = confirmation.value = '';
      await router.replace({ path: '/studio/reset-password', query: {} });
    }
  } catch (e) {
    if (isCurrent(submission)) error.value = e.response?.status === 429 ? 'Too many attempts. Please wait before trying again.' : failure(e);
  } finally {
    if (isCurrent(submission)) {
      busy.value = false;
      await focusFeedback(submission);
    }
  }
}
</script>

<template>
  <div class="login-panel">
    <span class="eyebrow">YOUR WORKSPACE</span>
    <h2>{{ isReset ? 'Choose a new password.' : 'Forgot your password?' }}</h2>
    <p>{{ isReset ? 'Use a unique password with at least 12 characters.' : 'Enter your account email to request a reset link.' }}</p>
    <p v-if="completed" ref="feedback" class="alert" role="status" tabindex="-1">{{ message }}</p>
    <template v-else>
      <p v-if="isReset && !validLink" class="alert error" role="alert">This reset link is incomplete. Request a new link.</p>
      <form v-else class="stack-field" @submit.prevent="submit" :aria-busy="busy">
        <label>Email address<input v-model.trim="email" type="email" autocomplete="username" maxlength="254" required :readonly="isReset" :disabled="busy" /></label>
        <template v-if="isReset">
          <label>New password<input v-model="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required :disabled="busy" /></label>
          <label>Confirm new password<input v-model="confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="72" required :disabled="busy" /></label>
        </template>
        <p v-if="error" ref="feedback" class="alert error" role="alert" tabindex="-1">{{ error }}</p>
        <button class="btn primary wide" :disabled="busy">{{ busy ? 'Please wait…' : isReset ? 'Reset password' : 'Send reset link' }}</button>
      </form>
      <p v-if="!isReset" class="footnote">Only active, existing accounts can receive a link. Check your inbox and spam folder.</p>
      <p v-if="isReset"><RouterLink to="/studio/forgot-password" class="text-link">Request a new reset link</RouterLink></p>
    </template>
    <p><RouterLink to="/studio" class="text-link">← Back to sign in</RouterLink></p>
  </div>
</template>
