<?php

declare(strict_types=1);

namespace EventManagerExtended\ExternalContent;

/**
 * Post types mapped to schema types in Settings → Post type schema settings.
 */
class EventSchemaSettings
{
    public const SCHEMA_TYPE_EVENT = 'Event';

    private const SCHEMA_SETTINGS_OPTION = 'options_post_type_schema_types';

    /**
     * @var array<string, string>|null
     */
    private static ?array $schemaTypeByPostType = null;

    /**
     * Whether Event import review is enabled.
     */
    public static function isReviewEnabled(): bool
    {
        return defined('EVENT_MANAGER_EXTENDED_IMPORT_REVIEW')
            && EVENT_MANAGER_EXTENDED_IMPORT_REVIEW === true;
    }

    /**
     * Whether a post type is connected to schema type Event.
     */
    public static function isEventPostType(string $postType): bool
    {
        if ($postType === '') {
            return false;
        }

        return (self::schemaTypesByPostType()[$postType] ?? null) === self::SCHEMA_TYPE_EVENT;
    }

    /**
     * @return array<string, string>
     */
    public static function schemaTypesByPostType(): array
    {
        if (self::$schemaTypeByPostType !== null) {
            return self::$schemaTypeByPostType;
        }

        self::$schemaTypeByPostType = [];
        $rowCount = (int) get_option(self::SCHEMA_SETTINGS_OPTION, 0);

        if ($rowCount < 1) {
            return self::$schemaTypeByPostType;
        }

        foreach (range(0, $rowCount - 1) as $index) {
            $mappedPostType = get_option(self::SCHEMA_SETTINGS_OPTION . "_{$index}_post_type", '');
            $schemaType = get_option(self::SCHEMA_SETTINGS_OPTION . "_{$index}_schema_type", '');

            if (!is_string($mappedPostType) || $mappedPostType === '' || !is_string($schemaType) || $schemaType === '') {
                continue;
            }

            self::$schemaTypeByPostType[$mappedPostType] = $schemaType;
        }

        return self::$schemaTypeByPostType;
    }
}
