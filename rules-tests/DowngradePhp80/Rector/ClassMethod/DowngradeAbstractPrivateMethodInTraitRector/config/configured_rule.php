<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

use Rector\DowngradePhp80\Rector\ClassMethod\DowngradeAbstractPrivateMethodInTraitRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(DowngradeAbstractPrivateMethodInTraitRector::class);
};
