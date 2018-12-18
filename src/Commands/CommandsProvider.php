<?php

namespace vardot\Composer\Commands;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

class CommandsProvider implements CommandProviderCapability
{
    public function getCommands()
    {
        return array(
          new RefactorComposerCommand,
          new VersionCheckComposerCommand
        );
    }
}
