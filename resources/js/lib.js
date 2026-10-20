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

export const request = async (method, path, data) => (await api({ method, url: path, data })).data;
export const get = (path) => request('get', path);
export const post = (path, data) => request('post', path, data);
export const put = (path, data) => request('put', path, data);
export const patch = (path, data) => request('patch', path, data);
export const remove = (path) => request('delete', path);
export const failure = (e) => {
  const issues = e.response?.data?.errors;
  return issues ? Object.values(issues).flat().join(' ') : (e.response?.data?.message || e.message || 'Something went wrong.');
};
export const uid = () => crypto.randomUUID();
export const starterBlock = (type) => {
  const options = {
    heading: { text: 'Your heading', level: 2 },
    text: { text: 'Write something meaningful here. Good stories deserve a beautiful home.' },
    image: { src: '', alt: 'Descriptive image alternative text', caption: '' },
    button: { text: 'Explore more', url: '/' },
    quote: { text: 'Something worth remembering.', by: 'Author' },
    callout: { title: 'A helpful note', text: 'Share an important idea with your readers.' },
    divider: {},
    list: { title: 'Key points', items: 'First point\nSecond point\nThird point' },
    stat: { value: '98%', label: 'Something remarkable' },
    faq: { question: 'What should I know?', answer: 'Here is a clear, helpful answer.' },
  };
  return { id: uid(), type, props: options[type] || {} };
};
export const newSection = (layout = 'stack') => ({
  id: uid(), layout, settings: { background: 'plain', width: 'normal' }, blocks: [],
});
export const labels = { heading: 'Heading', text: 'Rich text', image: 'Image', button: 'Button', quote: 'Quote', callout: 'Callout', divider: 'Divider', list: 'List', stat: 'Statistic', faq: 'FAQ' };
export const layouts = { stack: 'Stack', 'two-columns': 'Two columns', 'three-columns': 'Three columns', grid: 'Card grid', split: 'Split layout', hero: 'Hero section', feature: 'Feature panel' };
