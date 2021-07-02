<?php

namespace Drupal\ypkc_salesforce_import;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManager;
use Drupal\migrate_tools\Commands\MigrateToolsCommands;

/**
 * Wrapper for Salesforce import operations.
 */
class Importer implements SalesforceImporterInterface {

  use StringTranslationTrait;

  /**
   * The name used to identify the lock.
   */
  const LOCK_NAME = 'sf_import';

  /**
   * The name of the migrate group.
   */
  const MIGRATE_GROUP = 'sf_import';

  /**
   * The path to a directory with JSON files for import.
   */
  const SOURCE_DIRECTORY = 'private://salesforce_import/json/';

  /**
   * The path to a directory for processed JSON files.
   */
  const BACKUP_DIRECTORY = 'private://salesforce_import/backup/';

  /**
   * The lock backend.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Import status.
   *
   * @var bool
   */
  protected $isEnabled = FALSE;

  /**
   * JSON backup status.
   *
   * @var bool
   */
  protected $isBackupEnabled = FALSE;

  /**
   * JSON backup limit.
   *
   * @var int
   */
  protected $backupLimit = 15;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Migration plugin manager service.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManager
   */
  protected $migrationPluginManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Migrate tool drush commands.
   *
   * @var \Drupal\migrate_tools\Commands\MigrateToolsCommands
   */
  protected $migrateToolsCommands;

  /**
   * Importer constructor.
   *
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The Logger channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\migrate\Plugin\MigrationPluginManager $migrationPluginManager
   *   Migration Plugin Manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    LockBackendInterface $lock,
    LoggerChannelInterface $logger,
    ConfigFactoryInterface $config_factory,
    MigrationPluginManager $migrationPluginManager,
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system
  ) {
    $this->lock = $lock;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->migrationPluginManager = $migrationPluginManager;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;

    $settings = $this->configFactory->get('ypkc_salesforce_import.settings');
    $this->isEnabled = (bool) $settings->get('enabled');
    $this->isBackupEnabled = (bool) $settings->get('backup_json');
    $this->backupLimit = (int) $settings->get('backup_limit');
  }

  /**
   * {@inheritdoc}
   */
  public function directoryImport($dir) {
    if (PHP_SAPI !== 'cli') {
      return;
    }

    // 'migrate_tools.commands' service is available only in Drush context.
    // That's why we can't add the service using normal DI.
    $this->migrateToolsCommands = \Drupal::service('migrate_tools.commands');

    try {
      // Results of each fetch are saved to a separated directory.
      $json_files = $this->fileSystem->scanDirectory($dir, '/\.json$/');
      if (empty($json_files)) {
        return;
      }

      // Usually we have several files for import:
      // sessions.json, classes.json, programs.json, program_categories.json.
      foreach ($json_files as $file) {
        $this->fileSystem->copy($file->uri, 'private://salesforce_import/', FileSystemInterface::EXISTS_REPLACE);
      }

      $this->migrateToolsCommands->import('', ['group' => Importer::MIGRATE_GROUP]);

      // Save JSON files only if backup of JSON files is enabled.
      if ($this->isBackupEnabled()) {
        $backup_directory = static::BACKUP_DIRECTORY;
        $this->fileSystem->prepareDirectory($backup_directory, FileSystemInterface::CREATE_DIRECTORY);
        $this->fileSystem->move($dir, $backup_directory);
      }
      else {
        $this->fileSystem->deleteRecursive($dir);
      }
    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkMigrationsStatus(): bool {
    try {
      $migrations = $this->entityTypeManager
        ->getStorage('migration')
        ->getQuery('AND')
        ->condition('migration_group', static::MIGRATE_GROUP)
        ->execute();

      $migrations = $this->migrationPluginManager->createInstances($migrations);
      foreach ($migrations as $migration_id => $migration) {
        if ($migration->getStatus() !== MigrationInterface::STATUS_IDLE) {
          $this->logger->error($this->t('Migration @migration has status @status.', [
            '@migration' => $migration_id,
            '@status' => $migration->getStatusLabel(),
          ]));
          return FALSE;
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Impossible to get migrations statuses: ' . $e->getMessage());
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    return $this->isEnabled;
  }

  /**
   * {@inheritdoc}
   */
  public function isBackupEnabled(): bool {
    return $this->isBackupEnabled;
  }

  /**
   * Returns JSON backup limit setting.
   *
   * @return int
   *   The number of folders with JSON files to store.
   */
  public function getJsonBackupLimit(): int {
    return $this->backupLimit;
  }

  /**
   * {@inheritdoc}
   */
  public function acquireLock(): bool {
    return $this->lock->acquire(static::LOCK_NAME, 1200);
  }

  /**
   * {@inheritdoc}
   */
  public function releaseLock() {
    $this->lock->release(static::LOCK_NAME);
  }

  /**
   * {@inheritdoc}
   */
  public function getJsonDirectoriesList(): array {
    $dirs = [];
    $scan = scandir(static::SOURCE_DIRECTORY);

    foreach ($scan as $file) {
      $filename = static::SOURCE_DIRECTORY . "/$file";
      if (is_dir($filename) && $file != '.' && $file != '..') {
        $dirs[] = $filename;
      }
    }

    return $dirs;
  }

}
