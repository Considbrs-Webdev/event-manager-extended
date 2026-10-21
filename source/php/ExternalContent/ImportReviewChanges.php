<?php

declare(strict_types=1);

namespace EventManagerExtended\ExternalContent;

use WP_Post;

/**
 * Before/after table of Event schema fields for import review.
 *
 * Municipio overwrites `schemaData` on sync. The previous copy is snapshotted
 * during wp_insert_post_data (before meta_input runs) and written to
 * `_eme_review_previous_schema` right after the row is saved.
 *
 * The diff is rendered as a metabox on the Event edit screen for pending
 * posts. Description is omitted — it is already shown as the post content.
 */
class ImportReviewChanges
{
    public const PREVIOUS_SCHEMA_META = '_eme_review_previous_schema';

    private const METABOX_ID = 'eme_event_review_changes';

    /**
     * @var array<string, true>
     */
    private const EXCLUDED_TOP_LEVEL_KEYS = [
        'description' => true,
        '@id' => true,
        '@type' => true,
        '@context' => true,
        '@meta' => true,
        'id' => true,
        'checksum' => true,
        'text_match_info' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const COMPARE_IGNORE_NESTED_KEYS = [
        '@id' => true,
        '@meta' => true,
        'id' => true,
        'checksum' => true,
        'text_match_info' => true,
    ];

    /**
     * Previous schemaData captured during wp_insert_post_data, written after insert.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $queuedSchema = [];

    public function __construct()
    {
        if (!EventSchemaSettings::isReviewEnabled()) {
            return;
        }

        add_action('wp_insert_post', [self::class, 'persist'], 20, 1);

        if (is_admin()) {
            add_action('add_meta_boxes', [$this, 'registerMetabox'], 10, 2);
        }
    }

    /**
     * Capture published schemaData in memory. Do not write meta here —
     * update_post_meta inside wp_insert_post_data can abort the status change.
     */
    public static function snapshot(int $postId): void
    {
        if ($postId < 1) {
            return;
        }

        $schema = get_post_meta($postId, 'schemaData', true);
        if (!is_array($schema) || $schema === []) {
            return;
        }

        self::$queuedSchema[$postId] = $schema;
    }

    /**
     * Write the queued previous schema after the post row (and meta_input) is saved.
     */
    public static function persist(int $postId): void
    {
        if (!isset(self::$queuedSchema[$postId])) {
            return;
        }

        $schema = self::$queuedSchema[$postId];
        unset(self::$queuedSchema[$postId]);

        update_post_meta($postId, self::PREVIOUS_SCHEMA_META, $schema);
    }

    /**
     * Drop the stored previous schema after accept or deny.
     */
    public static function clear(int $postId): void
    {
        if ($postId < 1) {
            return;
        }

        delete_post_meta($postId, self::PREVIOUS_SCHEMA_META);
    }

    /**
     * Register the review changes metabox on the Event edit screen.
     *
     * @param string  $postType
     * @param \WP_Post $post
     */
    public function registerMetabox(string $postType, $post): void
    {
        if (!EventSchemaSettings::isEventPostType($postType)) {
            return;
        }

        if (!$post instanceof WP_Post || $post->post_status !== 'pending') {
            return;
        }

        add_meta_box(
            self::METABOX_ID,
            __('Ändringar sedan senaste import', 'event-manager-extended'),
            [$this, 'renderMetabox'],
            $postType,
            'normal',
            'high'
        );
    }

    /**
     * @param \WP_Post $post
     */
    public function renderMetabox($post): void
    {
        if (!$post instanceof WP_Post) {
            return;
        }

        $rows = self::changedRows($post->ID);

        if ($rows === []) {
            echo '<p>' . esc_html__('Inga specialfält har ändrats sedan senaste import.', 'event-manager-extended') . '</p>';

            return;
        }

        echo self::css();
        echo '<table class="eme-import-review-changes">';
        printf(
            '<thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead>',
            esc_html__('Fält', 'event-manager-extended'),
            esc_html__('Före', 'event-manager-extended'),
            esc_html__('Efter', 'event-manager-extended')
        );
        echo '<tbody>';

        foreach ($rows as $row) {
            printf(
                '<tr><th scope="row">%s</th>%s%s</tr>',
                esc_html($row['field']),
                self::renderCell($row['before'], 'before', $row['after']),
                self::renderCell($row['after'], 'after', $row['before'])
            );
        }

        echo '</tbody></table>';
    }

    /**
     * Render a diff cell. Empty values on the opposite side flag the field
     * as added/removed and drop the highlight from this side.
     */
    private static function renderCell(string $value, string $side, string $counterpart): string
    {
        $empty = $value === '' || $value === '—';
        $counterpartEmpty = $counterpart === '' || $counterpart === '—';

        $classes = ['eme-import-review-changes__cell'];

        if ($empty) {
            $classes[] = 'eme-import-review-changes__cell--empty';
        } elseif ($counterpartEmpty) {
            $classes[] = $side === 'before'
                ? 'eme-import-review-changes__cell--removed'
                : 'eme-import-review-changes__cell--added';
        } else {
            $classes[] = $side === 'before'
                ? 'eme-import-review-changes__cell--before'
                : 'eme-import-review-changes__cell--after';
        }

        return sprintf(
            '<td class="%s">%s</td>',
            esc_attr(implode(' ', $classes)),
            $empty ? '—' : nl2br(esc_html($value))
        );
    }

    private static function css(): string
    {
        return '<style>'
            . '.eme-import-review-changes{width:100%;border-collapse:collapse;}'
            . '.eme-import-review-changes th,.eme-import-review-changes td{padding:8px 12px;border-bottom:1px solid #dcdcde;vertical-align:top;text-align:left;}'
            . '.eme-import-review-changes thead th{background:#f6f7f7;font-weight:600;}'
            . '.eme-import-review-changes tbody th{font-weight:600;width:20%;background:#f6f7f7;}'
            . '.eme-import-review-changes__cell{width:40%;word-break:break-word;}'
            . '.eme-import-review-changes__cell--before{background:#fcf0f1;color:#8a1f1f;}'
            . '.eme-import-review-changes__cell--after{background:#edfaef;color:#0a5f1a;}'
            . '.eme-import-review-changes__cell--removed{background:#fcf0f1;color:#8a1f1f;}'
            . '.eme-import-review-changes__cell--added{background:#edfaef;color:#0a5f1a;font-weight:600;}'
            . '.eme-import-review-changes__cell--empty{color:#8c8f94;}'
            . '</style>';
    }

    /**
     * @return array<int, array{field: string, before: string, after: string}>
     */
    public static function changedRows(int $postId): array
    {
        $before = get_post_meta($postId, self::PREVIOUS_SCHEMA_META, true);
        $after = get_post_meta($postId, 'schemaData', true);

        if (!is_array($before) || !is_array($after)) {
            return [];
        }

        return self::diffSchema($before, $after);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<int, array{field: string, before: string, after: string}>
     */
    public static function diffSchema(array $before, array $after): array
    {
        $before = self::stripExcluded($before);
        $after = self::stripExcluded($after);
        $keys = array_unique([...array_keys($after), ...array_keys($before)]);
        $rows = [];

        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $beforeValue = $before[$key] ?? null;
            $afterValue = $after[$key] ?? null;

            if (!self::valuesDiffer($beforeValue, $afterValue)) {
                continue;
            }

            [$beforeStr, $afterStr] = self::formatChangedSideBySide($beforeValue, $afterValue);

            $rows[] = [
                'field' => self::labelForKey($key),
                'before' => $beforeStr,
                'after' => $afterStr,
            ];
        }

        return $rows;
    }

    /**
     * Format before/after where only sub-fields that differ are included.
     * Lists are matched by index, associative arrays by key.
     *
     * @return array{0: string, 1: string}
     */
    private static function formatChangedSideBySide(mixed $before, mixed $after): array
    {
        $beforeArray = is_array($before) ? self::maybeReduceImage($before) : $before;
        $afterArray = is_array($after) ? self::maybeReduceImage($after) : $after;

        if (!is_array($beforeArray) || !is_array($afterArray)) {
            return [self::formatValue($before), self::formatValue($after)];
        }

        $beforeIsList = array_is_list($beforeArray);
        $afterIsList = array_is_list($afterArray);

        if ($beforeIsList !== $afterIsList) {
            return [self::formatValue($before), self::formatValue($after)];
        }

        if ($beforeIsList) {
            return self::formatChangedList($beforeArray, $afterArray);
        }

        return self::formatChangedAssoc($beforeArray, $afterArray);
    }

    /**
     * @param list<mixed> $before
     * @param list<mixed> $after
     * @return array{0: string, 1: string}
     */
    private static function formatChangedList(array $before, array $after): array
    {
        $count = max(count($before), count($after));
        $useLabel = $count > 1;
        $beforeParts = [];
        $afterParts = [];

        for ($i = 0; $i < $count; $i++) {
            $beforeItem = $before[$i] ?? null;
            $afterItem = $after[$i] ?? null;

            if (!self::valuesDiffer($beforeItem, $afterItem)) {
                continue;
            }

            [$beforeStr, $afterStr] = self::formatChangedSideBySide($beforeItem, $afterItem);
            $label = $useLabel ? self::listItemLabel($afterItem ?? $beforeItem, $i) : '';

            if ($beforeStr !== '' && $beforeStr !== '—') {
                $beforeParts[] = $label . $beforeStr;
            }

            if ($afterStr !== '' && $afterStr !== '—') {
                $afterParts[] = $label . $afterStr;
            }
        }

        return [
            $beforeParts === [] ? '—' : implode("\n\n", $beforeParts),
            $afterParts === [] ? '—' : implode("\n\n", $afterParts),
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array{0: string, 1: string}
     */
    private static function formatChangedAssoc(array $before, array $after): array
    {
        $keys = array_unique([...array_keys($before), ...array_keys($after)]);
        $beforeLines = [];
        $afterLines = [];

        foreach ($keys as $key) {
            if (!is_string($key) || $key === '' || $key === '@type' || isset(self::COMPARE_IGNORE_NESTED_KEYS[$key])) {
                continue;
            }

            $beforeVal = $before[$key] ?? null;
            $afterVal = $after[$key] ?? null;

            if (!self::valuesDiffer($beforeVal, $afterVal)) {
                continue;
            }

            [$beforeStr, $afterStr] = self::formatChangedSideBySide($beforeVal, $afterVal);
            $label = self::friendlyLabelForKey($key);
            $prefix = $label !== null ? $label . ': ' : '';

            if ($beforeStr !== '' && $beforeStr !== '—') {
                $beforeLines[] = $prefix . $beforeStr;
            }

            if ($afterStr !== '' && $afterStr !== '—') {
                $afterLines[] = $prefix . $afterStr;
            }
        }

        return [
            $beforeLines === [] ? '—' : implode("\n", $beforeLines),
            $afterLines === [] ? '—' : implode("\n", $afterLines),
        ];
    }

    /**
     * Prefix for a list item — use a `name` if available, otherwise index.
     */
    private static function listItemLabel(mixed $item, int $index): string
    {
        if (is_array($item)) {
            $name = $item['name'] ?? null;
            if (is_string($name) && $name !== '') {
                return $name . ' — ';
            }
        }

        return '#' . ($index + 1) . ': ';
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function stripExcluded(array $schema): array
    {
        foreach (array_keys($schema) as $key) {
            if (!is_string($key) || !isset(self::EXCLUDED_TOP_LEVEL_KEYS[$key])) {
                continue;
            }

            unset($schema[$key]);
        }

        return $schema;
    }

    private static function valuesDiffer(mixed $before, mixed $after): bool
    {
        return wp_json_encode(self::normalizeValue($before)) !== wp_json_encode(self::normalizeValue($after));
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $value = self::maybeReduceImage($value);
        }

        if (!is_array($value)) {
            return self::normalizeScalar($value);
        }

        if ($value === []) {
            return null;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && isset(self::COMPARE_IGNORE_NESTED_KEYS[$key])) {
                continue;
            }

            $normalizedValue = self::normalizeValue($item);
            if ($normalizedValue === null) {
                continue;
            }

            $normalized[$key] = $normalizedValue;
        }

        if ($normalized === []) {
            return null;
        }

        if (!array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    private static function normalizeScalar(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim(html_entity_decode($value));

            return $trimmed === '' ? null : $trimmed;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>|string
     */
    private static function maybeReduceImage(array $value): array|string
    {
        $type = $value['@type'] ?? '';
        $types = is_array($type) ? $type : [$type];

        if (!in_array('ImageObject', $types, true)) {
            return $value;
        }

        $url = $value['url'] ?? $value['contentUrl'] ?? null;

        return is_string($url) && $url !== '' ? $url : $value;
    }

    private static function labelForKey(string $key): string
    {
        return self::friendlyLabelForKey($key) ?? $key;
    }

    /**
     * Swedish label for a schema key, or null if we do not translate it.
     * Untranslated intermediate keys (e.g. `contactPoint`) are hidden in the
     * nested diff — the leaf label carries the meaning.
     */
    private static function friendlyLabelForKey(string $key): ?string
    {
        $labels = [
            'name' => __('Namn', 'event-manager-extended'),
            'eventStatus' => __('Status', 'event-manager-extended'),
            'eventAttendanceMode' => __('Deltagande', 'event-manager-extended'),
            'url' => __('Länk', 'event-manager-extended'),
            'typicalAgeRange' => __('Ålder', 'event-manager-extended'),
            'startDate' => __('Startdatum', 'event-manager-extended'),
            'endDate' => __('Slutdatum', 'event-manager-extended'),
            'doorTime' => __('Dörrarna öppnas', 'event-manager-extended'),
            'location' => __('Plats', 'event-manager-extended'),
            'organizer' => __('Arrangör', 'event-manager-extended'),
            'offers' => __('Pris / biljetter', 'event-manager-extended'),
            'eventSchedule' => __('Tillfällen', 'event-manager-extended'),
            'image' => __('Bild', 'event-manager-extended'),
            'keywords' => __('Nyckelord', 'event-manager-extended'),
            'physicalAccessibilityFeatures' => __('Tillgänglighet', 'event-manager-extended'),
            'inLanguage' => __('Språk', 'event-manager-extended'),
            'isAccessibleForFree' => __('Gratis', 'event-manager-extended'),
            'maximumAttendeeCapacity' => __('Max antal deltagare', 'event-manager-extended'),
            'performer' => __('Medverkande', 'event-manager-extended'),
            'about' => __('Om', 'event-manager-extended'),
            'sameAs' => __('Samma som', 'event-manager-extended'),
            'previousStartDate' => __('Tidigare startdatum', 'event-manager-extended'),
            'duration' => __('Längd', 'event-manager-extended'),
            'video' => __('Video', 'event-manager-extended'),
            'audience' => __('Målgrupp', 'event-manager-extended'),
            'email' => __('E-post', 'event-manager-extended'),
            'telephone' => __('Telefon', 'event-manager-extended'),
            'price' => __('Pris', 'event-manager-extended'),
            'priceCurrency' => __('Valuta', 'event-manager-extended'),
            'availability' => __('Tillgänglighet', 'event-manager-extended'),
            'validFrom' => __('Gäller från', 'event-manager-extended'),
            'validThrough' => __('Gäller till', 'event-manager-extended'),
            'address' => __('Adress', 'event-manager-extended'),
            'streetAddress' => __('Gatuadress', 'event-manager-extended'),
            'postalCode' => __('Postnummer', 'event-manager-extended'),
            'addressLocality' => __('Ort', 'event-manager-extended'),
            'addressCountry' => __('Land', 'event-manager-extended'),
            'repeatFrequency' => __('Upprepning', 'event-manager-extended'),
            'byDay' => __('Veckodagar', 'event-manager-extended'),
            'repeatCount' => __('Antal', 'event-manager-extended'),
            'exceptDate' => __('Undantag', 'event-manager-extended'),
        ];

        return $labels[$key] ?? null;
    }

    private static function formatValue(mixed $value): string
    {
        if (self::isEmptyValue($value)) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? __('Ja', 'event-manager-extended') : __('Nej', 'event-manager-extended');
        }

        if (is_scalar($value)) {
            return html_entity_decode(trim((string) $value));
        }

        if (!is_array($value)) {
            return '—';
        }

        $value = self::maybeReduceImage($value);

        if (is_string($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $parts = array_map(self::formatValue(...), $value);
            $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== '—'));

            return $parts === [] ? '—' : implode("\n\n", $parts);
        }

        return self::formatAssociative($value);
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function formatAssociative(array $value): string
    {
        $lines = [];

        foreach ($value as $key => $item) {
            if (!is_string($key) || isset(self::COMPARE_IGNORE_NESTED_KEYS[$key]) || $key === '@type') {
                continue;
            }

            $formatted = self::formatValue($item);
            if ($formatted === '—') {
                continue;
            }

            $lines[] = self::labelForKey($key) . ': ' . $formatted;
        }

        return $lines === [] ? '—' : implode("\n", $lines);
    }

    private static function isEmptyValue(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        return false;
    }
}
