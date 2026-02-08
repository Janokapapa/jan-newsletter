# Mail and Newsletter

Complete email marketing platform for WordPress with SMTP queue, subscriber management, campaign editor, analytics, and GetResponse sync.

**Version:** 1.0.0 | **Requires:** WordPress 6.0+, PHP 8.1+ | **License:** GPL v2+

## Features

### Email Queue & SMTP
- Priority-based queue (critical → high → medium → bulk)
- Custom SMTP transport with TLS/SSL, authentication, multipart MIME
- Configurable batch processing via WP cron (default: 50 emails / 2 min)
- `wp_mail()` interception — routes all WordPress emails through the queue
- Auto-detects priority from subject (password reset = critical, invoice = high, etc.)
- Fallback to native `wp_mail()` if SMTP disabled

### Subscriber Management
- Subscribe, confirm (double opt-in), unsubscribe flows
- Multiple subscriber lists with many-to-many relationships
- Status tracking: subscribed, unsubscribed, bounced, pending
- Bounce tracking (type + count)
- Extensible metadata system (custom fields, GetResponse data)
- Bulk operations: delete, add to list
- Search & filter with pagination

### Campaign Builder
- HTML editor with campaign creation and scheduling
- Personalization tags: `{{email}}`, `{{first_name}}`, `{{last_name}}`, `{{name}}`
- Tracking pixel injection (open tracking)
- Link wrapping (click tracking)
- Automatic unsubscribe link
- Test send, preview, pause/resume
- Status flow: draft → scheduled → sending → sent/paused

### Analytics & Tracking
- Open tracking (1x1 transparent pixel)
- Click tracking (URL wrapping with redirect)
- Campaign stats: sends, opens, clicks, bounces, unsubscribes
- Open rate and click-through rate calculation
- Per-link click breakdown
- Daily timeline aggregation
- Subscriber activity history
- Global statistics across all campaigns

### GetResponse Integration
- API v3 connection with token auth
- Sync lists (campaigns) from GetResponse
- Full contact import with pagination
- Custom field, tag, geolocation, engagement score sync
- Per-list or full account sync modes

### Import/Export
- CSV import with flexible column detection
- Skip or update existing subscribers
- CSV export filtered by list and status (up to 100K)

### Bounce & Complaint Handling
- Mailgun webhook support (HMAC-SHA256 verification)
- SendGrid webhook support (Base64 signature verification)
- Auto-unsubscribe on hard bounce or complaint
- Manual bounce management

### WPForms Integration
- Auto-subscribe on form submission
- List assignment support

## Database Tables

8 custom tables created on activation (prefix: `jan_nl_`):

| Table | Purpose |
|-------|---------|
| `subscribers` | All subscriber data, status, bounce tracking |
| `lists` | Subscriber lists with double opt-in settings |
| `list_subscriber` | Many-to-many list-subscriber mapping |
| `campaigns` | Campaign content, status, counters |
| `queue` | Priority email queue with retry tracking |
| `logs` | Full delivery log with SMTP responses (30-day retention) |
| `stats` | Campaign analytics events (sent, open, click, bounce) |
| `subscriber_meta` | Flexible key-value metadata per subscriber |

## REST API

Base URL: `/wp-json/jan-newsletter/v1`

All endpoints require `manage_options` capability unless noted.

### Subscribers
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/subscribers` | List (paginated, searchable, filterable) |
| POST | `/subscribers` | Create subscriber |
| GET | `/subscribers/{id}` | Get with metadata |
| PUT | `/subscribers/{id}` | Update |
| DELETE | `/subscribers/{id}` | Delete |
| POST | `/subscribers/bulk-delete` | Batch delete |
| POST | `/subscribers/bulk-add-to-list` | Batch add to list |
| GET | `/subscribers/export` | Export CSV |
| POST | `/subscribers/import` | Import CSV |
| POST | `/public/subscribe` | Public subscription (no auth) |

### Campaigns
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/campaigns` | List with status filter |
| POST | `/campaigns` | Create draft |
| GET | `/campaigns/{id}` | Get details |
| PUT | `/campaigns/{id}` | Update |
| DELETE | `/campaigns/{id}` | Delete |
| POST | `/campaigns/{id}/send` | Send immediately |
| POST | `/campaigns/{id}/schedule` | Schedule |
| POST | `/campaigns/{id}/pause` | Pause |
| POST | `/campaigns/{id}/resume` | Resume |
| POST | `/campaigns/{id}/test` | Test send |
| GET | `/campaigns/{id}/stats` | Analytics |
| GET | `/campaigns/{id}/preview` | HTML preview |

### Lists
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/lists` | List all |
| POST | `/lists` | Create |
| PUT | `/lists/{id}` | Update |
| DELETE | `/lists/{id}` | Delete |

### Queue
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/queue` | View queue |
| POST | `/queue/process` | Manual trigger |
| GET | `/queue/stats` | Statistics |

### Settings
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/settings` | Get all settings |
| POST | `/settings` | Update settings |

### Stats
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/stats` | Global statistics |
| GET | `/stats/daily` | Daily aggregation |

### Webhooks
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/webhooks/mailgun` | Mailgun bounce/delivery events |
| POST | `/webhooks/sendgrid` | SendGrid bounce/delivery events |

## Public Endpoints

Custom rewrite rules (no auth required):

| URL | Purpose |
|-----|---------|
| `/jan-newsletter/confirm/{token}` | Double opt-in confirmation |
| `/jan-newsletter/unsubscribe/{email}/{token}` | Unsubscribe with confirmation form |
| `/jan-newsletter/track/open/{data}` | Open tracking pixel |
| `/jan-newsletter/track/click/{data}` | Click tracking redirect |

## Settings

Stored in `jan_newsletter_settings` option:

```php
from_name         // Sender name (default: site name)
from_email        // Sender email (default: admin email)
default_list_id   // Default list for new subscribers
double_optin      // Require email confirmation (default: true)
smtp_enabled      // Use custom SMTP (default: false)
smtp_host         // SMTP server hostname
smtp_port         // SMTP port (default: 587)
smtp_encryption   // tls, ssl, or none (default: tls)
smtp_auth         // Require authentication (default: true)
smtp_username     // SMTP username
smtp_password     // SMTP password
intercept_wp_mail // Route all wp_mail() through queue (default: false)
queue_batch_size  // Emails per cron run (default: 50)
queue_interval    // Cron interval in minutes (default: 2)
track_opens       // Open tracking (default: true)
track_clicks      // Click tracking (default: true)
api_enabled       // REST API access (default: false)
```

The `intercept_wp_mail` feature is controlled by a separate option: `jan_newsletter_intercept_wp_mail`.

## WP Cron Jobs

| Hook | Interval | Purpose |
|------|----------|---------|
| `jan_newsletter_process_queue` | 2 minutes | Process email queue batch |
| `jan_newsletter_cleanup_logs` | Daily | Delete logs older than 30 days |

## Installation

### Via Composer (recommended)
```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "git@github.com:Janokapapa/jan-newsletter.git"
    }
  ],
  "require": {
    "composer/installers": "^2.0",
    "jandev/jan-newsletter": "^1.0"
  },
  "extra": {
    "installer-paths": {
      "wp-content/plugins/{$name}/": ["type:wordpress-plugin"]
    }
  }
}
```

### Manual
Copy the plugin folder to `wp-content/plugins/jan-newsletter/` and activate.

## Admin UI

React + TypeScript + Tailwind CSS admin dashboard built with Vite.

Pages: Dashboard, Campaigns, Campaign Editor, Subscribers, Lists, Queue, Logs, Settings

Pre-built assets are in `assets/dist/`. To rebuild:

```bash
cd admin-ui
npm install
npm run build
```

## File Structure

```
jan-newsletter/
├── jan-newsletter.php          # Main plugin file, autoloader, hooks
├── composer.json               # Package definition
├── uninstall.php               # Cleanup on uninstall
├── src/
│   ├── Plugin.php              # Singleton, component initialization
│   ├── Activator.php           # DB table creation, cron scheduling
│   ├── Models/
│   │   ├── Subscriber.php
│   │   ├── SubscriberList.php
│   │   ├── Campaign.php
│   │   └── QueuedEmail.php
│   ├── Repositories/
│   │   ├── SubscriberRepository.php
│   │   ├── ListRepository.php
│   │   ├── CampaignRepository.php
│   │   ├── QueueRepository.php
│   │   └── StatsRepository.php
│   ├── Services/
│   │   ├── SubscriberService.php
│   │   ├── CampaignService.php
│   │   ├── ImportExportService.php
│   │   ├── BounceService.php
│   │   └── GetResponseService.php
│   ├── Mail/
│   │   ├── Mailer.php
│   │   ├── SmtpTransport.php
│   │   ├── QueueProcessor.php
│   │   ├── WpMailInterceptor.php
│   │   ├── TrackingPixel.php
│   │   └── LogCleaner.php
│   ├── Api/
│   │   ├── SubscribersController.php
│   │   ├── CampaignsController.php
│   │   ├── ListsController.php
│   │   ├── QueueController.php
│   │   ├── StatsController.php
│   │   ├── SettingsController.php
│   │   └── WebhooksController.php
│   ├── Endpoints/
│   │   ├── ConfirmEndpoint.php
│   │   ├── UnsubscribeEndpoint.php
│   │   └── TrackingEndpoint.php
│   ├── Integrations/
│   │   └── WPFormsIntegration.php
│   └── Admin/
│       └── AdminPage.php
├── admin-ui/                   # React admin interface source
│   ├── src/
│   │   ├── App.tsx
│   │   ├── pages/
│   │   ├── components/
│   │   └── api/
│   └── vite.config.ts
└── assets/dist/                # Built admin UI assets
```
