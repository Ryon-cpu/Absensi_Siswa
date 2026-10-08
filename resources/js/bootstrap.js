import axios from 'axios';
axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;
axios.defaults.headers.common.Accept = 'application/json';
axios.defaults.baseURL = import.meta.env.VITE_API_BASE_URL || window.location.origin;
export default axios;