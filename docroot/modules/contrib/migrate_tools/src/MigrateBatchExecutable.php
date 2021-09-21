<?php

namespace Drupal\migrate_tools;

use Drupal\migrate\MigrateMessage;
use Drupal\migrate\MigrateMessageInterface;
use Drupal\migrate\Plugin\MigrationInterface;

/**
 * Defines a migrate executable class for batch migrations through UI.
 */
class MigrateBatchExecutable extends MigrateExecutable {

  /**
   * Representing a batch import operation.
   */
  const BATCH_IMPORT = 1;

  /**
   * Indicates if we need to update existing rows or skip them.
   *
   * @var int
   */
  protected $updateExistingRows = 0;

  /**
   * Indicates if we need import dependent migrations also.
   *
   * @var int
   */
  protected $checkDependencies = 0;

  /**
   * Indicates if synchronization is needed.
   *
   * @var bool
   */
  protected $syncSource = FALSE;

  /**
   * The current batch context.
   *
   * @var array
   */
  protected $batchContext = [];

  /**
   * Plugin manager for migration plugins.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManagerInterface
   */
  protected $migrationPluginManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(MigrationInterface $migration, MigrateMessageInterface $message, array $options = []) {

    if (isset($options['update'])) {
      $this->updateExistingRows = $options['update'];
    }

    if (isset($options['force'])) {
      $this->checkDependencies = $options['force'];
    }

    if (isset($options['sync'])) {
      $this->syncSource = $options['sync'];
    }

    parent::__construct($migration, $message, $options);


    $this->migrationPluginManager = \Drupal::getContainer()->get('plugin.manager.migration');
  }

  /**
   * Sets the current batch content so listeners can update the messages.
   *
   * @param array|\DrushBatchContext $context
   *   The batch context.
   */
  public function setBatchContext(&$context) {
    $this->batchContext = &$context;
  }

  /**
   * Gets a reference to the current batch context.
   *
   * @return array
   *   The batch context.
   */
  public function &getBatchContext() {
    return $this->batchContext;
  }

  /**
   * Setup batch operations for running the migration.
   */
  public function batchImport() {
    // Create the batch operations for each migration that needs to be executed.
    // This includes the migration for this executable, but also the dependent
    // migrations.
    $operations = $this->batchOperations([$this->migration], 'import', [
      'limit' => $this->itemLimit,
      'update' => $this->updateExistingRows,
      'force' => $this->checkDependencies,
      'sync' => $this->syncSource,
    ]);

    if (count($operations) > 0) {
      $batch = [
        'operations' => $operations,
        'title' => $this->t('Migrating %migrate', ['%migrate' => $this->migration->label()]),
        'init_message' => $this->t('Start migrating %migrate', ['%migrate' => $this->migration->label()]),
        'progress_message' => $this->t('Migrating %migrate', ['%migrate' => $this->migration->label()]),
        'error_message' => $this->t('An error occurred while migrating %migrate.', ['%migrate' => $this->migration->label()]),
        'finished' => '\Drupal\migrate_tools\MigrateBatchExecutable::batchFinishedImport',
      ];

      batch_set($batch);
    }
  }

  /**
   * Helper to generate the batch operations for importing migrations.
   *
   * @param \Drupal\migrate\Plugin\MigrationInterface[] $migrations
   *   The migrations.
   * @param string $operation
   *   The batch operation to perform.
   * @param array $options
   *   The migration options.
   *
   * @return array
   *   The batch operations to perform.
   */
  protected function batchOperations(array $migrations, $operation, array $options = []) {
    $operations = [];
    foreach ($migrations as $id => $migration) {

      if (!empty($options['update'])) {
        $migration->getIdMap()->prepareUpdate();
      }

      if (!empty($options['force'])) {
        $migration->set('requirements', []);
      }
      else {
        $dependencies = $migration->getMigrationDependencies();
        if (!empty($dependencies['required'])) {
          $required_migrations = $this->migrationPluginManager->createInstances($dependencies['required']);
          // For dependent migrations will need to be migrate all items.
          $operations = array_merge($operations, $this->batchOperations($required_migrations, $operation, [
            'limit' => 0,
            'update' => $options['update'],
            'force' => $options['force'],
            'sync' => $options['sync'],
          ]));
        }
      }

      $operations[] = [
        '\Drupal\migrate_tools\MigrateBatchExecutable::batchProcessImport',
        [$migration->id(), $options],
      ];
    }

    return $operations;
  }

  /**
   * Batch 'operation' callback.
   *
   * @param string $migration_id
   *   The migration id.
   * @param array $options
   *   The batch executable options.
   * @param array|\DrushBatchContext $context
   *   The sandbox context.
   */
  public static function batchProcessImport($migration_id, array $options, &$context) {
    if (empty($context['sandbox'])) {
      $context['finished'] = 0;
      $context['sandbox'] = [];
      $context['sandbox']['total'] = 0;
      $context['sandbox']['counter'] = 0;
      $context['sandbox']['batch_limit'] = 0;
      $context['sandbox']['operation'] = MigrateBatchExecutable::BATCH_IMPORT;
    }

    // Prepare the migration executable.
    $message = new MigrateMessage();
    /** @var \Drupal\migrate\Plugin\MigrationInterface $migration */
    $migration = \Drupal::getContainer()->get('plugin.manager.migration')->createInstance($migration_id, isset($options['configuration']) ? $options['configuration'] : []);
    unset($options['configuration']);

    // Each batch run we need to reinitialize the counter for the migration.
    if (!empty($options['limit']) && isset($context['results'][$migration->id()]['@numitems'])) {
      $options['limit'] = $options['limit'] - $context['results'][$migration->id()]['@numitems'];
    }

    $executable = new MigrateBatchExecutable($migration, $message, $options);

    if (empty($context['sandbox']['total'])) {
      $context['sandbox']['total'] = $executable->getSource()->count();
      $context['sandbox']['batch_limit'] = $executable->calculateBatchLimit($context);
      $context['results'][$migration->id()] = [
        '@numitems' => 0,
        '@created' => 0,
        '@updated' => 0,
        '@failures' => 0,
        '@ignored' => 0,
        '@name' => $migration->id(),
      ];
    }

    // Every iteration, we reset out batch counter.
    $context['sandbox']['batch_counter'] = 0;

    // Make sure we know our batch context.
    $executable->setBatchContext($context);

    // Do the import.
    $result = $executable->import();

    // Store the result; will need to combine the results of all our iterations.
    $context['results'][$migration->id()] = [
      '@numitems' => $context['results'][$migration->id()]['@numitems'] + $executable->getProcessedCount(),
      '@created' => $context['results'][$migration->id()]['@created'] + $executable->getCreatedCount(),
      '@updated' => $context['results'][$migration->id()]['@updated'] + $executable->getUpdatedCount(),
      '@failures' => $context['results'][$migration->id()]['@failures'] + $executable->getFailedCount(),
      '@ignored' => $context['results'][$migration->id()]['@ignored'] + $executable->getIgnoredCount(),
      '@name' => $migration->id(),
    ];

    // Do some housekeeping.
    if (
      $result != MigrationInterface::RESULT_INCOMPLETE
    ) {
      $context['finished'] = 1;
    }
    else {
      $context['sandbox']['counter'] = $context['results'][$migration->id()]['@numitems'];
      if ($context['sandbox']['counter'] <= $context['sandbox']['total']) {
        $context['finished'] = ((float) $context['sandbox']['counter'] / (float) $context['sandbox']['total']);
        $context['message'] = t('Importing %migration (@percent%).', [
          '%migration' => $migration->label(),
          '@percent' => (int) ($context['finished'] * 100),
        ]);
      }
    }

  }

  /**
   * Finished callback for import batches.
   *
   * @param bool $success
   *   A boolean indicating whether the batch has completed successfully.
   * @param array $results
   *   The value set in $context['results'] by callback_batch_operation().
   * @param array $operations
   *   If $success is FALSE, contains the operations that remained unprocessed.
   */
  public static function batchFinishedImport($success, array $results, array $operations) {
    if ($success) {
      foreach ($results as $migration_id => $result) {
        $singular_message = "Processed 1 item (@created created, @updated updated, @failures failed, @ignored ignored) - done with '@name'";
        $plural_message = "Processed @numitems items (@created created, @updated updated, @failures failed, @ignored ignored) - done with '@name'";
        \Drupal::messenger()->addStatus(\Drupal::translation()->formatPlural($result['@numitems'],
          $singular_message,
          $plural_message,
          $result));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkStatus() {
    $status = parent::checkStatus();

    if ($status == MigrationInterface::RESULT_COMPLETED) {
      // Do some batch housekeeping.
      $context = $this->getBatchContext();

      if (!empty($context['sandbox']) && $context['sandbox']['operation'] == MigrateBatchExecutable::BATCH_IMPORT) {
        $context['sandbox']['batch_counter']++;
        if ($context['sandbox']['batch_counter'] >= $context['sandbox']['batch_limit']) {
          $status = MigrationInterface::RESULT_INCOMPLETE;
        }
      }
    }

    return $status;
  }

  /**
   * Calculates how much a single batch iteration will handle.
   *
   * @param array|\DrushBatchContext $context
   *   The sandbox context.
   *
   * @return float
   *   The batch limit.
   */
  public function calculateBatchLimit($context) {
    // TODO Maybe we need some other more sophisticated logic here?
    return ceil($context['sandbox']['total'] / 100);
  }

}
