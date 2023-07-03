<?php

namespace P4\MasterTheme\Features;

use P4\MasterTheme\Feature;

/**
 * @see description().
 */
class MediaArchive extends Feature
{
    /**
     * @inheritDoc
     */
    public static function id(): string
    {
        return 'feature_media_archive';
    }

    /**
     * @inheritDoc
     */
    protected static function name(): string
    {
        return __(
            'New Media Archive (Beta)',
            'planet4-master-theme-backend'
        );
    }

    /**
     * @inheritDoc
     */
    protected static function description(): string
    {
        return __(
            'Drop-in replacement for the GPI Media Library plugin. If enabled, the plugin can be disabled.',
            'planet4-master-theme-backend'
        );
    }

    /**
     * @inheritDoc
     */
    public static function show_toggle_production(): bool
    {
        return true;
    }
}