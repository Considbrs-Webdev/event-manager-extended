<?php

declare(strict_types=1);

namespace EventManagerExtended\ExternalContent;

use WP_Post;

/**
 * Accept/deny buttons on the Event post list (edit.php).
 *
 * Shown only when the post type is mapped to schema type Event and the review
 * feature is enabled. Applies to pending posts waiting for review.
 */
class ImportReviewList
{
    private const COLUMN_KEY = 'eme_event_review';

    private const QUERY_ACTION = 'eme_event_review';

    private const QUERY_POST = 'eme_review_post';

    private const ACTION_ACCEPT = 'accept';

    private const ACTION_DENY = 'deny';

    private const NOTICE_QUERY = 'eme_event_review_notice';

    private static bool $hooksRegistered = false;

    private static bool $columnRendererRegistered = false;

    /**
     * @var array<int, true>
     */
    private static array $renderedPostIds = [];

    public function __construct()
    {
        if (!EventSchemaSettings::isReviewEnabled() || !is_admin()) {
            return;
        }

        if (self::$hooksRegistered) {
            return;
        }

        self::$hooksRegistered = true;

        add_action('admin_init', [$this, 'handleReviewAction']);
        add_action('current_screen', [$this, 'registerColumnRenderer']);
        add_action('admin_notices', [$this, 'renderNotice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueStyles']);
    }

    /**
     * Bind the list-table column on the current Event post type screen.
     *
     * Uses manage_{post_type}_posts_custom_column so hierarchical CPTs
     * (evenemang) render once, unlike manage_posts/pages_custom_column.
     *
     * @param \WP_Screen $screen
     */
    public function registerColumnRenderer($screen): void
    {
        if (!is_object($screen) || ($screen->base ?? '') !== 'edit') {
            return;
        }

        $postType = (string) ($screen->post_type ?? '');
        if (!$this->shouldShowForPostType($postType)) {
            return;
        }

        if (self::$columnRendererRegistered) {
            return;
        }

        self::$columnRendererRegistered = true;

        add_filter("manage_{$postType}_posts_columns", [$this, 'addColumn']);
        add_action("manage_{$postType}_posts_custom_column", [$this, 'renderColumn'], 10, 2);
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function addColumn(array $columns): array
    {
        $columns[self::COLUMN_KEY] = __('Granskning', 'event-manager-extended');

        return $columns;
    }

    /**
     * @param string $columnName
     * @param int    $postId
     */
    public function renderColumn($columnName, $postId): void
    {
        if ($columnName !== self::COLUMN_KEY) {
            return;
        }

        $postId = (int) $postId;
        if (isset(self::$renderedPostIds[$postId])) {
            return;
        }

        self::$renderedPostIds[$postId] = true;

        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            return;
        }

        if (!$this->shouldShowForPostType($post->post_type)) {
            return;
        }

        if ($post->post_status !== 'pending') {
            echo '—';

            return;
        }

        echo $this->renderButtons($post);
    }

    /**
     * Accept or deny a pending Event from the list table.
     */
    public function handleReviewAction(): void
    {
        $action = isset($_GET[self::QUERY_ACTION]) ? sanitize_key((string) $_GET[self::QUERY_ACTION]) : '';
        if ($action !== self::ACTION_ACCEPT && $action !== self::ACTION_DENY) {
            return;
        }

        $postId = isset($_GET[self::QUERY_POST]) ? (int) $_GET[self::QUERY_POST] : 0;
        if ($postId < 1) {
            return;
        }

        if (!wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), $this->nonceAction($postId))) {
            wp_die(esc_html__('Ogiltig säkerhetsnyckel.', 'event-manager-extended'), '', ['response' => 403]);
        }

        $post = get_post($postId);
        if (!$post instanceof WP_Post || !$this->shouldShowForPostType($post->post_type)) {
            return;
        }

        if ($action === self::ACTION_ACCEPT) {
            $this->acceptPost($post);
            $this->redirectWithNotice($post->post_type, 'accepted');

            return;
        }

        $this->denyPost($post);
        $this->redirectWithNotice($post->post_type, 'denied');
    }

    public function renderNotice(): void
    {
        $notice = isset($_GET[self::NOTICE_QUERY]) ? sanitize_key((string) $_GET[self::NOTICE_QUERY]) : '';
        if ($notice === '') {
            return;
        }

        $messages = [
            'accepted' => __('Evenemanget är accepterat och publicerat.', 'event-manager-extended'),
            'denied' => __('Evenemanget är nekat och flyttat till papperskorgen.', 'event-manager-extended'),
            'error' => __('Kunde inte uppdatera evenemanget.', 'event-manager-extended'),
        ];

        if (!isset($messages[$notice])) {
            return;
        }

        $class = $notice === 'error' ? 'notice-error' : 'notice-success';

        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr($class),
            esc_html($messages[$notice])
        );
    }

    public function enqueueStyles(string $hook): void
    {
        if ($hook !== 'edit.php') {
            return;
        }

        $postType = isset($_GET['post_type']) ? sanitize_key((string) $_GET['post_type']) : 'post';
        if (!$this->shouldShowForPostType($postType)) {
            return;
        }

        wp_add_inline_style('common', $this->columnCss());
    }

    private function acceptPost(WP_Post $post): void
    {
        if (!current_user_can('publish_post', $post->ID)) {
            wp_die(esc_html__('Du har inte behörighet att acceptera evenemang.', 'event-manager-extended'), '', ['response' => 403]);
        }

        $result = wp_update_post([
            'ID' => $post->ID,
            'post_status' => 'publish',
        ], true);

        if (is_wp_error($result)) {
            $this->redirectWithNotice($post->post_type, 'error');
        }

        ImportReviewChanges::clear($post->ID);
    }

    private function denyPost(WP_Post $post): void
    {
        if (!current_user_can('delete_post', $post->ID)) {
            wp_die(esc_html__('Du har inte behörighet att neka evenemang.', 'event-manager-extended'), '', ['response' => 403]);
        }

        $result = wp_trash_post($post->ID);
        if ($result === false || $result === null) {
            $this->redirectWithNotice($post->post_type, 'error');
        }

        ImportReviewChanges::clear($post->ID);
    }

    private function renderButtons(WP_Post $post): string
    {
        $acceptUrl = $this->actionUrl($post, self::ACTION_ACCEPT);
        $denyUrl = $this->actionUrl($post, self::ACTION_DENY);
        $reviewUrl = (string) get_edit_post_link($post->ID);
        $denyConfirm = __('Neka evenemanget och flytta till papperskorgen?', 'event-manager-extended');

        return sprintf(
            '<div class="eme-event-review-actions">'
                . '<a class="button button-small button-primary" href="%s">%s</a>'
                . '<a class="button button-small" href="%s" onclick="return confirm(\'%s\');">%s</a>'
                . '<a class="button button-small" href="%s">%s</a>'
                . '</div>',
            esc_url($acceptUrl),
            esc_html__('Acceptera', 'event-manager-extended'),
            esc_url($denyUrl),
            esc_js($denyConfirm),
            esc_html__('Neka', 'event-manager-extended'),
            esc_url($reviewUrl),
            esc_html__('Granska', 'event-manager-extended')
        );
    }

    private function actionUrl(WP_Post $post, string $action): string
    {
        return wp_nonce_url(
            add_query_arg(
                [
                    self::QUERY_ACTION => $action,
                    self::QUERY_POST => $post->ID,
                    'post_type' => $post->post_type,
                ],
                admin_url('edit.php')
            ),
            $this->nonceAction($post->ID)
        );
    }

    private function nonceAction(int $postId): string
    {
        return 'eme_event_review_' . $postId;
    }

    private function redirectWithNotice(string $postType, string $notice): void
    {
        wp_safe_redirect(
            add_query_arg(
                [
                    'post_type' => $postType,
                    self::NOTICE_QUERY => $notice,
                ],
                admin_url('edit.php')
            )
        );
        exit;
    }

    private function shouldShowForPostType(string $postType): bool
    {
        if (!EventSchemaSettings::isReviewEnabled()) {
            return false;
        }

        return EventSchemaSettings::isEventPostType($postType);
    }

    private function columnCss(): string
    {
        return '.column-' . self::COLUMN_KEY . '{width:16em}'
            . '.eme-event-review-actions{display:flex;gap:4px;flex-wrap:wrap}';
    }
}
