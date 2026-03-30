<?php

namespace Drupal\hide_obsolete_files\Commands;

use Drupal\Core\File\FileExists;
use Drupal\file\Entity\File;
use Drush\Commands\DrushCommands;

/**
 * A Drush commandfile for hiding obsolete files by obfuscating filenames.
 */
class HideObsoleteFilesCommands extends DrushCommands {

  /**
   * Hide obsolete files by obfuscating filenames.
   *
   * @usage hof:hide
   *
   * @command hof:hide
   */
  public function hideObsolete($bundle = 'document') {
    $count = 0;
    $fids = getFiles($bundle);

    foreach ($fids as $fid) {
      toggleFileObsolete($fid);
      $count++;
    }

    \Drupal::logger('hide_obsolete_files')->info('Processed @count files.', ['@count' => $count]);
  }

  /**
   * Revert all obfuscated filenames.
   *
   * @usage hof:revert
   *
   * @command hof:revert
   */
  public function revertObsolete() {
    $fids = \Drupal::entityQuery('file')->accessCheck(FALSE)->execute();

    foreach ($fids as $fid) {
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
      $filepath = $file->getFileUri();
      $filepath_obsolete = $filepath . '_obsolete751995';

      $file_system = \Drupal::service('file_system');
      // Returns available filepath or FALSE if file exists in file system (has been marked obsolete).
      $file_not_marked_obsolete = $file_system->getDestinationFilename($filepath_obsolete, FileExists::Error);

      if (!$file_not_marked_obsolete) {
        // Rename existing file by REMOVING "_obsolete751995".
        $file_system->move($filepath_obsolete, $filepath, FileExists::Replace);

        \Drupal::logger('hide_obsolete_files')->info('File @old renamed to @new', ['@old' => $filepath_obsolete, '@new' => $filepath]);
      }
    }
  }

  /**
   * Gets managed file IDs by media bundle.
   */
  private function getFiles($bundle) {
    $fids_by_bundle = [];

    $fids = \Drupal::entityQuery('file')->accessCheck(FALSE)->execute();
    $file_usage = \Drupal::service('file.usage');

    foreach ($fids as $fid) {
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
      $usage = $file_usage->listUsage($file);

      if (isset($usage['file']['media'])) {
        $usage_list = array_keys($usage['file']['media']);

        foreach ($usage_list as $mid) {
          $media = \Drupal::entityTypeManager()->getStorage('media')->load($mid);

          if ($media->bundle() === $bundle) {
            $fids_by_bundle[] = $fid;
          }
        }
      }
    }

    return $fids_by_bundle;
  }

  /**
   * Gets file usage and checks against current entity revisions.
   */
  private function getFileUsage(int $fid) {
    $file_usage = \Drupal::service('file.usage');

    $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
    $usage = $file_usage->listUsage($file);

    if (isset($usage['file']['media'])) {
      $usage_list = array_keys($usage['file']['media']);

      foreach ($usage_list as $mid) {
        $media = \Drupal::entityTypeManager()->getStorage('media')->load($mid);
        $media_usage = \Drupal::service('entity_usage.usage')->listSources($media);

        // Scan paragraph usages.
        if (isset($media_usage['paragraph']) && is_array($media_usage['paragraph'])) {
          foreach ($media_usage['paragraph'] as $paragraph) {
            foreach ($paragraph as $revision) {
              $revision_entity = \Drupal::entityTypeManager()->getStorage('paragraph')->loadRevision($revision['source_vid']);

              // Current revision found.
              if ($revision_entity->isDefaultRevision()) {
                return TRUE;
              }
            }
          }
        }

        // Scan node usages.
        if (isset($media_usage['node']) && is_array($media_usage['node'])) {
          foreach ($media_usage['node'] as $node) {
            foreach ($node as $revision) {
              $revision_entity = \Drupal::entityTypeManager()->getStorage('node')->loadRevision($revision['source_vid']);

              // Current revision found.
              if ($revision_entity->isDefaultRevision()) {
                return TRUE;
              }
            }
          }
        }
      }
    }

    return FALSE;
  }

  /**
   * Adds or removes obsolete flag from filename.
   */
  private function toggleFileObsolete(int $fid) {
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
    $filepath = $file->getFileUri();
    $filepath_obsolete = $filepath . '_obsolete751995';

    $file_system = \Drupal::service('file_system');
    // Returns available filepath or FALSE if file exists in file system (has been marked obsolete).
    $file_not_marked_obsolete = $file_system->getDestinationFilename($filepath_obsolete, FileExists::Error);

    $file_has_usage = getFileUsage($file->id());

    if ($file_not_marked_obsolete && !$file_has_usage) {
      // Rename existing file by ADDING "_obsolete751995".
      $file_system->move($filepath, $filepath_obsolete, FileExists::Replace);

      \Drupal::logger('hide_obsolete_files')->info('File @old renamed to @new', ['@old' => $filepath, '@new' => $filepath_obsolete]);
      return;
    }

    if (!$file_not_marked_obsolete && $file_has_usage) {
      // Rename existing file by REMOVING "_obsolete751995".
      $file_system->move($filepath_obsolete, $filepath, FileExists::Replace);

      \Drupal::logger('hide_obsolete_files')->info('File @old renamed to @new', ['@old' => $filepath_obsolete, '@new' => $filepath]);
      return;
    }
  }
}
