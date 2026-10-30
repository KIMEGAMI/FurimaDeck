<?php

namespace App\Services;

use Aws\Command;

final class FurimaDeckR2UploadCommandOptions
{
    public static function removeUnsupportedAcl(Command $command): void
    {
        unset($command['ACL']);
    }
}
