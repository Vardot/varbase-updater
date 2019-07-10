<?php

namespace vardot\Composer\Commands;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

/**
 * Commands provider.
 */
class CommandsProvider implements CommandProviderCapability {

  /**
   * Get Commands.
   *
   * @return type
   */
  public function getCommands() {
    return array (
      new RefactorComposerCommand,
      new VersionCheckComposerCommand
    );
  }

}
