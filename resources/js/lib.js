import axios from 'axios';

const api = axios.create({
  baseURL: '/api/v1',
  headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  withCredentials: true,
});

api.interceptors.request.use((config) => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  if (csrf) config.headers['X-CSRF-TOKEN'] = csrf;
  return config;
});
api.interceptors.response.use((response) => {
  const token = response.data?.csrf_token;
  const meta = document.querySelector('meta[name="csrf-token"]');
  if (typeof token === 'string' && meta) meta.content = token;
  return response;
});

export const request = async (method, path, data) => (await api({ method, url: path, data })).data;
export const get = (path) => request('get', path);
export const post = (path, data) => request('post', path, data);
export const put = (path, data) => request('put', path, data);
export const patch = (path, data) => request('patch', path, data);
export const remove = (path, data) => request('delete', path, data);
export const failure = (e) => {
  const issues = e.response?.data?.errors;
  return issues ? Object.values(issues).flat().join(' ') : (e.response?.data?.message || e.message || 'Something went wrong.');
};
export { uid, starterBlock, newSection } from './builder/registry';
