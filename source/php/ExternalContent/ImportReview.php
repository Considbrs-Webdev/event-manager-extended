<?php

declare(strict_types=1);

namespace EventManagerExtended\ExternalContent;

use Municipio\SchemaData\ExternalContent\Rest\AjaxSync;

/**
 * Hold externally synced Event posts for review.
 *
 * Municipio External Content always inserts as publish. New posts and posts
 * that change after they were published are stored as pending instead.
 * Unchanged posts are skipped by Municipio checksum and keep their status.
 * Published schemaData is snapshotted before overwrite so special-field diffs
 * can be shown on the pending event.
 *
 * Applies to WP-Cron (`Municipio/ExternalContent/Sync`) and the admin button
 * (`wp_ajax_municipio_external_content_sync`), which calls SyncHandler directly.
 *
 * Toggle with EVENT_MANAGER_EXTENDED_IMPORT_REVIEW. Only post types mapped to
 * schema type Event (Settings → Post type schema settings) are affected.
 */
class ImportReview
{
    private const REVIEW_STATUS = 'pending';

    private const SYNC_START_PRIORITY = 2;

    private const SYNC_END_PRIORITY = 20;

    private const AJAX_SYNC_START_PRIORITY = 1;

    private const CAPABILITY_FILTER_PRIORITY = 20;

    private bool $syncing = false;

    public function __construct()
    {
        if (!EventSchemaSettings::isReviewEnabled()) {
            return;
        }

        add_action('Municipio/ExternalContent/Sync', [$this, 'startSync'], self::SYNC_START_PRIORITY);
        add_action('Municipio/ExternalContent/Sync', [$this, 'endSync'], self::SYNC_END_PRIORITY);
        add_action('wp_ajax_' . $this->ajaxAction(), [$this, 'startSync'], self::AJAX_SYNC_START_PRIORITY);
        add_action('shutdown', [$this, 'endSync']);
        add_filter('wp_insert_post_data', [$this, 'setReviewStatus'], 999, 4);
        add_action('wp_insert_post', [$this, 'enforcePendingAfterInsert'], 999, 3);
        add_filter('register_post_type_args', [$this, 'allowReviewCapabilities'], self::CAPABILITY_FILTER_PRIORITY, 2);
    }

    /**
     * Mark that an External Content sync is in progress.
     */
    public function startSync(): void
    {
        $this->syncing = true;
    }

    /**
     * Clear the in-progress sync flag.
     */
    public function endSync(): void
    {
        $this->syncing = false;
    }

    /**
     * Force pending status for new and previously published synced posts.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $postarr
     * @param array<string, mixed> $unsanitizedPostarr
     * @param bool                 $update
     * @return array<string, mixed>
     */
    public function setReviewStatus(array $data, array $postarr, array $unsanitizedPostarr = [], bool $update = false): array
    {
        if (!$this->shouldModerateInsert($data)) {
            return $data;
        }

        $postId = (int) ($postarr['ID'] ?? 0);
        $isUpdate = $update || $postId > 0;

        if (!$isUpdate) {
            $data['post_status'] = $this->reviewStatus();

            return $data;
        }

        $currentStatus = $postId > 0 ? get_post_status($postId) : false;

        if ($currentStatus === 'publish') {
            ImportReviewChanges::snapshot($postId);
            $data['post_status'] = $this->reviewStatus();

            return $data;
        }

        if ($currentStatus === false) {
            $data['post_status'] = $this->reviewStatus();

            return $data;
        }

        $data['post_status'] = $currentStatus;

        return $data;
    }

    /**
     * Second-layer safety: if a synced Event ends up as publish anyway
     * (some other filter overrode our post_status), rewrite the row
     * directly to pending. Direct SQL avoids re-firing wp_insert_post.
     *
     * Snapshot is already handled from setReviewStatus (which reads the
     * old schemaData before meta_input overwrites it).
     *
     * @param int      $postId
     * @param \WP_Post $post
     * @param bool     $update
     */
    public function enforcePendingAfterInsert(int $postId, $post, bool $update): void
    {
        if (!$this->isExternalContentSync()) {
            return;
        }

        if (!$post instanceof \WP_Post) {
            return;
        }

        if (!EventSchemaSettings::isEventPostType($post->post_type)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            ['post_status' => $this->reviewStatus()],
            ['ID' => $postId]
        );
        clean_post_cache($postId);
    }

    /**
     * Let editors review synced Event post types. Creating posts stays locked.
     *
     * @param array<string, mixed> $args
     */
    public function allowReviewCapabilities(array $args, string $postType): array
    {
        if (!EventSchemaSettings::isEventPostType($postType)) {
            return $args;
        }

        $capabilities = $args['capabilities'] ?? [];
        if (($capabilities['edit_post'] ?? '') !== 'do_not_allow') {
            return $args;
        }

        $args['capabilities'] = [
            'create_posts' => 'do_not_allow',
        ];

        return $args;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function shouldModerateInsert(array $data): bool
    {
        if (!$this->isExternalContentSync()) {
            return false;
        }

        $postType = $data['post_type'] ?? '';
        if ($postType === 'attachment' || $postType === 'revision') {
            return false;
        }

        if (!EventSchemaSettings::isEventPostType(is_string($postType) ? $postType : '')) {
            return false;
        }

        $postStatus = $data['post_status'] ?? '';

        return $postStatus !== 'inherit';
    }

    private function isExternalContentSync(): bool
    {
        if ($this->syncing) {
            return true;
        }

        return $this->isAjaxExternalContentSync();
    }

    private function isAjaxExternalContentSync(): bool
    {
        if (!defined('DOING_AJAX') || !DOING_AJAX) {
            return false;
        }

        $action = $_REQUEST['action'] ?? '';

        return $action === $this->ajaxAction();
    }

    private function ajaxAction(): string
    {
        if (class_exists(AjaxSync::class)) {
            return AjaxSync::$action;
        }

        return 'municipio_external_content_sync';
    }

    private function reviewStatus(): string
    {
        $status = apply_filters(
            'EventManagerExtended/ExternalContent/importReviewStatus',
            self::REVIEW_STATUS
        );

        if (!is_string($status) || $status === '') {
            return self::REVIEW_STATUS;
        }

        return $status;
    }
}
