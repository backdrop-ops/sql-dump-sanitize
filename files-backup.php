<?php
/**
 * @file
 * Backup the files directory of a Backdrop CMS site.
 */

// Check if we already have config array.
if (!isset($config)) {
  $config = parse_ini_file('config.ini');
}

// Get path to files directory.
$db_name = $config['DB_NAME'];
$backdrop_root = $config['BACKDROP_ROOT'];
$destination = $config['BACKUP_DESTINATION'];
$num_keep = $config['NUM_KEEP'];
$timezone = $config['TIMEZONE'];

// Check which options were passed in on the command line.
if (in_array('--rollover_files', $argv) || in_array('-rf', $argv)) {
  $rollover_files = TRUE;
}
else {
  $rollover_files = FALSE;
}
if (in_array('--latest', $argv) || in_array('-l', $argv)) {
  $latest = TRUE;
}
else {
  $latest = FALSE;
}

// Get timestamp.
date_default_timezone_set($timezone);
$date = date('F-j-Y-Gis');

// Make backup.
exec(
  "tar czf $db_name-files-$date.tar.gz -C $backdrop_root files/ &&
  mkdir -p $destination/files_backups &&
  mv $db_name-files-$date.tar.gz $destination/files_backups"
);
if ($latest) {
  if (file_exists("$destination/files_backups/$db_name-files-latest.tar.gz")) {
    if (is_link("$destination/files_backups/$db_name-files-latest.tar.gz")) {
      unlink("$destination/files_backups/$db_name-files-latest.tar.gz");
    }
  }
  symlink("$destination/files_backups/$db_name-files-$date.tar.gz", "$destination/files_backups/$db_name-files-latest.tar.gz");
}

if ($rollover_files) {
  _rollover_files_backups($destination, $num_keep);
}

/**
 * Helper function to delete stale files backups.
 *
 * @param string $backup_destination
 *   The path to the directory where you would like to delete stale backups.
 *
 * @param int $num_keep
 *   The number of backups you would like to keep.  Defaults to 3.
 */
function _rollover_files_backups($backup_destination, $num_keep = 3) {
  $filemtime_keyed_array = [];
  $bups = scandir($backup_destination . '/files_backups');
  foreach ($bups as $key => $b) {
    if (is_link($b)) continue;
    if (strpos($b, '.tar.gz') === FALSE) {
      unset($bups[$key]);
    }
    else {
      $my_key = filemtime("$backup_destination/files_backups/$b");
      $filemtime_keyed_array[$my_key] = $b;
    }
  }
  ksort($filemtime_keyed_array);
  $newes_bups_first = array_reverse($filemtime_keyed_array);
  $k = 0;
  foreach ($newes_bups_first as $bup) {
    if ($k > ($num_keep - 1)) {
      exec("rm $backup_destination/files_backups/$bup");
    }
    $k++;
  }
}
