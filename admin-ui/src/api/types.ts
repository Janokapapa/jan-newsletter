export interface Subscriber {
  id: number;
  email: string;
  first_name: string;
  last_name: string;
  status: 'subscribed' | 'unsubscribed' | 'bounced' | 'pending';
  source: string;
  bounce_status: 'none' | 'soft' | 'hard' | 'complaint';
  bounce_count?: number;
  custom_fields?: Record<string, unknown>;
  ip_address?: string;
  confirmed_at?: string;
  lists: { id: number; name: string }[];
  created_at: string;
  updated_at?: string;
}

export interface SubscriberList {
  id: number;
  name: string;
  slug: string;
  description: string;
  double_optin: boolean;
  subscriber_count: number;
  created_at: string;
}

export interface Campaign {
  id: number;
  name: string;
  subject: string;
  body_html?: string;
  body_text?: string;
  from_name: string;
  from_email: string;
  list_id?: number;
  list_name?: string;
  status: 'draft' | 'scheduled' | 'sending' | 'sent' | 'paused';
  scheduled_at?: string;
  started_at?: string;
  finished_at?: string;
  total_recipients: number;
  sent_count: number;
  open_count: number;
  click_count: number;
  open_rate: number;
  click_rate: number;
  created_at: string;
  updated_at?: string;
}

export interface QueuedEmail {
  id: number;
  to_email: string;
  from_email: string;
  from_name: string;
  subject: string;
  status: 'pending' | 'processing' | 'sent' | 'failed' | 'cancelled';
  priority: number;
  priority_label: string;
  attempts: number;
  max_attempts: number;
  error_message?: string;
  source: string;
  subscriber_id?: number;
  campaign_id?: number;
  scheduled_at?: string;
  sent_at?: string;
  created_at: string;
}

export interface EmailLog {
  id: number;
  queue_id?: number;
  to_email: string;
  from_email?: string;
  from_name?: string;
  subject: string;
  body_html?: string;
  body_text?: string;
  headers?: string;
  status: string;
  smtp_response?: string;
  source: string;
  campaign_id?: number;
  sent_at: string;
}

export interface DashboardStats {
  subscribers: {
    total: number;
    subscribed: number;
    unsubscribed: number;
    bounced: number;
    pending: number;
    new_last_30_days: number;
  };
  queue: {
    pending: number;
    processing: number;
    sent: number;
    failed: number;
    sent_today: number;
    sent_this_week: number;
  };
  emails: {
    total_sent: number;
    total_opens: number;
    total_clicks: number;
    sent_today: number;
    sent_this_week: number;
    sent_this_month: number;
    avg_open_rate: number;
    avg_click_rate: number;
  };
  lists: {
    total: number;
  };
  campaigns: {
    total: number;
    draft: number;
    sending: number;
    sent: number;
  };
  recent_campaigns: Campaign[];
}

export interface Settings {
  from_name: string;
  from_email: string;
  default_list_id?: number;
  double_optin: boolean;
  smtp_enabled: boolean;
  smtp_host: string;
  smtp_port: number;
  smtp_encryption: 'tls' | 'ssl' | 'none';
  smtp_auth: boolean;
  smtp_username: string;
  smtp_password: string;
  smtp_password_masked?: string;
  intercept_wp_mail: boolean;
  queue_batch_size: number;
  queue_interval: number;
  track_opens: boolean;
  track_clicks: boolean;
  mailgun_signing_key: string;
  sendgrid_signing_key: string;
  api_enabled: boolean;
  api_key: string;
  api_key_masked?: string;
  getresponse_api_key: string;
  getresponse_api_key_masked?: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    total: number;
    page: number;
    per_page: number;
    total_pages: number;
  };
}

export interface ApiResponse<T> {
  message: string;
  [key: string]: T | string;
}
