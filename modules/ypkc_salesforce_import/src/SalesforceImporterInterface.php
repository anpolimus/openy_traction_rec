<?php

namespace Drupal\ypkc_salesforce_import;

interface SalesforceImporterInterface {

  /**
   * Imports all fetched JSON files in the directory.
   *
   * @param string $dir
   *   The directory with fetched JSON data.
   */
  public function directoryImport(string $dir);

  /**
   * Check salesforce migrations statuses.
   */
  public function checkMigrationsStatus(): bool;

  /**
   * Checks import status.
   *
   * @return bool
   *   TRUE if the salesforce import is enabled.
   */
  public function isEnabled(): bool;

  /**
   * Checks Salesforce JSON files backup status.
   *
   * @return bool
   *   TRUE if JSON files backup is enabled.
   */
  public function isBackupEnabled(): bool;

  /**
   * Acquires Salesforce import lock.
   *
   * @return bool
   *   Lock status.
   */
  public function acquireLock(): bool;

  /**
   * Releases Salesforce lock.
   */
  public function releaseLock();

  /**
   * Provides a list of directories with the fetched JSON files.
   *
   * @return array
   *   The array of directories paths.
   */
  public function getJsonDirectoriesList(): array;

}
