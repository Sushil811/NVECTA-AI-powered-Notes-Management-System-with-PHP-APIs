import axios from 'axios';

const api = axios.create({
  baseURL: 'https://nvecta-ai-powered-notes-management.onrender.com/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Inject Bearer token in headers dynamically
api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('token');
    if (token && config.headers) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

// Global response interceptor for API errors
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response && error.response.status === 401) {
      // Token is invalid or expired
      localStorage.removeItem('token');
      localStorage.removeItem('user');
      // Trigger a page refresh to send user to auth screen
      window.dispatchEvent(new Event('auth-failure'));
    }
    return Promise.reject(error);
  }
);

export default api;
