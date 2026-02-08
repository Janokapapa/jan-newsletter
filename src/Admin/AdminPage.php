<?php

namespace JanNewsletter\Admin;

/**
 * Admin page - WordPress integrated menu with React components
 */
class AdminPage {
    /**
     * Menu items configuration
     */
    private array $menu_items = [
        'jan-newsletter' => [
            'title' => 'Dashboard',
            'page' => 'dashboard',
        ],
        'jan-newsletter-subscribers' => [
            'title' => 'Subscribers',
            'page' => 'subscribers',
        ],
        'jan-newsletter-lists' => [
            'title' => 'Lists',
            'page' => 'lists',
        ],
        'jan-newsletter-campaigns' => [
            'title' => 'Campaigns',
            'page' => 'campaigns',
        ],
        'jan-newsletter-queue' => [
            'title' => 'Email Queue',
            'page' => 'queue',
        ],
        'jan-newsletter-logs' => [
            'title' => 'Logs',
            'page' => 'logs',
        ],
        'jan-newsletter-settings' => [
            'title' => 'Settings',
            'page' => 'settings',
        ],
    ];

    /**
     * Initialize admin page
     */
    public function init(): void {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Add menu and submenus
     */
    public function add_menu(): void {
        // Main menu
        add_menu_page(
            __('Mail and Newsletter', 'jan-newsletter'),
            __('Mail', 'jan-newsletter'),
            'manage_options',
            'jan-newsletter',
            [$this, 'render_page'],
            'dashicons-email-alt',
            30
        );

        // Submenus
        foreach ($this->menu_items as $slug => $item) {
            add_submenu_page(
                'jan-newsletter',
                __($item['title'], 'jan-newsletter'),
                __($item['title'], 'jan-newsletter'),
                'manage_options',
                $slug,
                [$this, 'render_page']
            );
        }
    }

    /**
     * Get current page from URL
     */
    private function get_current_page(): string {
        $screen = $_GET['page'] ?? 'jan-newsletter';

        foreach ($this->menu_items as $slug => $item) {
            if ($slug === $screen) {
                return $item['page'];
            }
        }

        return 'dashboard';
    }

    /**
     * Render the admin page (React mount point)
     */
    public function render_page(): void {
        $current_page = $this->get_current_page();
        echo '<div id="jan-newsletter-app" data-page="' . esc_attr($current_page) . '"></div>';
    }

    /**
     * Check if we're on a newsletter admin page
     */
    private function is_newsletter_page(string $hook): bool {
        $valid_hooks = [
            'toplevel_page_jan-newsletter',
            'mail_page_jan-newsletter-subscribers',
            'mail_page_jan-newsletter-lists',
            'mail_page_jan-newsletter-campaigns',
            'mail_page_jan-newsletter-queue',
            'mail_page_jan-newsletter-logs',
            'mail_page_jan-newsletter-settings',
        ];

        return in_array($hook, $valid_hooks);
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts(string $hook): void {
        // Only load on our admin pages
        if (!$this->is_newsletter_page($hook)) {
            return;
        }

        // Check if built assets exist
        $manifest_path = JAN_NEWSLETTER_PATH . 'assets/dist/.vite/manifest.json';

        if (file_exists($manifest_path)) {
            // Production build
            $manifest = json_decode(file_get_contents($manifest_path), true);

            if (isset($manifest['index.html'])) {
                $entry = $manifest['index.html'];

                // CSS
                if (!empty($entry['css'])) {
                    foreach ($entry['css'] as $index => $css_file) {
                        wp_enqueue_style(
                            'jan-newsletter-' . $index,
                            JAN_NEWSLETTER_URL . 'assets/dist/' . $css_file,
                            [],
                            JAN_NEWSLETTER_VERSION
                        );
                    }
                }

                // JS
                wp_enqueue_script(
                    'jan-newsletter-app',
                    JAN_NEWSLETTER_URL . 'assets/dist/' . $entry['file'],
                    ['wp-element', 'wp-i18n'],
                    JAN_NEWSLETTER_VERSION,
                    true
                );
            }
        } else {
            // Development mode - load from Vite dev server
            wp_enqueue_script(
                'jan-newsletter-vite',
                'http://localhost:5173/@vite/client',
                [],
                null,
                true
            );

            wp_enqueue_script(
                'jan-newsletter-app',
                'http://localhost:5173/src/main.tsx',
                ['wp-element', 'wp-i18n'],
                null,
                true
            );
        }

        // Localize script with necessary data
        wp_localize_script('jan-newsletter-app', 'janNewsletter', [
            'apiUrl' => rest_url('jan-newsletter/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'adminUrl' => admin_url(),
            'siteUrl' => home_url(),
            'siteName' => get_bloginfo('name'),
            'adminEmail' => get_option('admin_email'),
            'currentPage' => $this->get_current_page(),
            'menuUrls' => [
                'dashboard' => admin_url('admin.php?page=jan-newsletter'),
                'subscribers' => admin_url('admin.php?page=jan-newsletter-subscribers'),
                'lists' => admin_url('admin.php?page=jan-newsletter-lists'),
                'campaigns' => admin_url('admin.php?page=jan-newsletter-campaigns'),
                'queue' => admin_url('admin.php?page=jan-newsletter-queue'),
                'logs' => admin_url('admin.php?page=jan-newsletter-logs'),
                'settings' => admin_url('admin.php?page=jan-newsletter-settings'),
            ],
        ]);

        // Set script as module type for Vite
        add_filter('script_loader_tag', function ($tag, $handle, $src) {
            if (in_array($handle, ['jan-newsletter-app', 'jan-newsletter-vite'])) {
                return '<script type="module" src="' . esc_url($src) . '"></script>';
            }
            return $tag;
        }, 10, 3);

        // Hide admin notices on our page
        remove_all_actions('admin_notices');
    }
}
