<script setup>
import { ref } from 'vue';
import { post, failure } from '../lib';
const props = defineProps({ kind: { type: String, required: true } });
const emit = defineEmits(['installed']);
const file = ref(null), publisher = ref(''), signature = ref(''), sha256 = ref('');
const busy = ref(false), error = ref(''), success = ref(''), form = ref(null);
async function readMetadata(event) {
  error.value = '';
  const metadata = event.target.files?.[0];
  if (!metadata) return;
  try {
    if (metadata.size > 16384) throw new Error('Signature metadata must be smaller than 16 KB.');
    const value = JSON.parse(await metadata.text());
    if (typeof value.publisher !== 'string' || typeof value.signature !== 'string' || !/^[a-f0-9]{64}$/.test(value.sha256)) throw new Error('This file is not valid package signature metadata.');
    publisher.value = value.publisher; signature.value = value.signature; sha256.value = value.sha256;
  } catch (e) { error.value = e.message; }
}
async function install() {
  if (busy.value || !file.value) return;
  const body = new FormData();
  for (const [key, value] of Object.entries({ file: file.value, publisher: publisher.value, signature: signature.value, sha256: sha256.value.toLowerCase() })) body.append(key, value);
  busy.value = true; error.value = success.value = '';
  try {
    const result = await post(`/studio/${props.kind === 'theme' ? 'themes' : 'plugins'}/packages`, body);
    success.value = props.kind === 'theme' ? 'Theme installed. Select it above to preview its design and apply it.' : 'Plugin installed. Activate it from your library when ready.';
    emit('installed', result);
    file.value = null; publisher.value = signature.value = sha256.value = ''; form.value?.reset();
  } catch (e) { error.value = failure(e); }
  finally { busy.value = false; }
}
</script>
<template>
  <section class="admin-panel package-upload">
    <h2>Upload a {{ kind }} package</h2>
    <p>Install a ZIP from a publisher approved by this installation. You can import its signature file or enter the verification details.</p>
    <p v-if="error" class="alert error" role="alert">{{ error }}</p>
    <p v-if="success" class="alert" role="status">{{ success }}</p>
    <form ref="form" class="settings-form" :aria-busy="busy" @submit.prevent="install">
      <label>{{ kind === 'theme' ? 'Theme' : 'Plugin' }} package ZIP<input type="file" accept=".zip,application/zip" required :disabled="busy" @change="file = $event.target.files?.[0]" /></label>
      <label>Signature metadata (optional)<input type="file" accept=".json,application/json" :disabled="busy" @change="readMetadata" /></label>
      <label>Trusted publisher ID<input v-model.trim="publisher" required maxlength="80" :disabled="busy" /></label>
      <label>SHA-256 digest<input v-model.trim="sha256" required pattern="[a-fA-F0-9]{64}" :disabled="busy" /></label>
      <label>Detached signature (base64)<textarea v-model.trim="signature" required rows="3" maxlength="100" :disabled="busy" /></label>
      <button class="btn primary" :disabled="busy">{{ busy ? 'Verifying package…' : 'Verify and install' }}</button>
    </form>
  </section>
</template>
