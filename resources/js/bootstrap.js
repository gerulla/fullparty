import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.axios.interceptors.response.use(
	response => response,
	error => {
		const status = error?.response?.status;

		if (status === 401 || status === 419) {
			window.location.assign('/auth/login');
		}

		return Promise.reject(error);
	}
);
