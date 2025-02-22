<?php

namespace P4\MasterTheme\Migrations;

use P4\MasterTheme\MigrationRecord;
use P4\MasterTheme\MigrationScript;

/**
 * Turn on the EN form feature everywhere.
 */
class M001EnableEnFormFeature extends MigrationScript
{
    /**
     * Perform the actual migration.
     *
     * @param MigrationRecord $record Information on the execution, can be used to add logs.
     * phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter -- interface implementation
     */
    protected static function execute(MigrationRecord $record): void
    {
        $features = get_option('planet4_features');
        $features['feature_engaging_networks'] = true;
        update_option('planet4_features', $features);
    }
    // phpcs:enable SlevomatCodingStandard.Functions.UnusedParameter
}
