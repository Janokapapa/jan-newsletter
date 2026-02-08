declare global {
  interface Window {
    janNewsletter: {
      apiUrl: string;
      nonce: string;
      adminUrl: string;
      siteUrl: string;
      siteName: string;
      adminEmail: string;
      currentPage: string;
      menuUrls: Record<string, string>;
    };
  }
}

const config = window.janNewsletter || {
  apiUrl: '/wp-json/jan-newsletter/v1',
  nonce: '',
  adminUrl: '/wp-admin/',
  siteUrl: '/',
  siteName: 'Site',
  adminEmail: '',
  currentPage: 'dashboard',
  menuUrls: {},
};

const DEFAULT_TIMEOUT = 10000; // 10 seconds

const fetchWithTimeout = async (url: string, options: RequestInit, timeout = DEFAULT_TIMEOUT): Promise<Response> => {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeout);

  try {
    const response = await fetch(url, {
      ...options,
      signal: controller.signal,
    });
    return response;
  } catch (error) {
    if (error instanceof Error && error.name === 'AbortError') {
      throw new Error('Request timeout');
    }
    throw error;
  } finally {
    clearTimeout(timeoutId);
  }
};

export const api = {
  get: async <T>(endpoint: string, timeout?: number): Promise<T> => {
    const response = await fetchWithTimeout(
      `${config.apiUrl}${endpoint}`,
      {
        headers: {
          'X-WP-Nonce': config.nonce,
        },
      },
      timeout
    );
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Request failed');
    }
    return response.json();
  },

  post: async <T>(endpoint: string, data?: unknown, timeout?: number): Promise<T> => {
    const response = await fetchWithTimeout(
      `${config.apiUrl}${endpoint}`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': config.nonce,
        },
        body: data ? JSON.stringify(data) : undefined,
      },
      timeout
    );
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Request failed');
    }
    return response.json();
  },

  put: async <T>(endpoint: string, data: unknown, timeout?: number): Promise<T> => {
    const response = await fetchWithTimeout(
      `${config.apiUrl}${endpoint}`,
      {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': config.nonce,
        },
        body: JSON.stringify(data),
      },
      timeout
    );
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Request failed');
    }
    return response.json();
  },

  delete: async <T>(endpoint: string, timeout?: number): Promise<T> => {
    const response = await fetchWithTimeout(
      `${config.apiUrl}${endpoint}`,
      {
        method: 'DELETE',
        headers: {
          'X-WP-Nonce': config.nonce,
        },
      },
      timeout
    );
    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Request failed');
    }
    return response.json();
  },
};

export { config };
