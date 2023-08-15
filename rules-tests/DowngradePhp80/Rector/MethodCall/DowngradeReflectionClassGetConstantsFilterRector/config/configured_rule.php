<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

use Rector\DowngradePhp80\Rector\MethodCall\DowngradeReflectionClassGetConstantsFilterRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(DowngradeReflectionClassGetConstantsFilterRector::class);
};
