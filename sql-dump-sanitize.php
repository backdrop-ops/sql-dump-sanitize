<?php

/**
 * @file
 * Backup and sanitize Backdrop CMS database to the filesystem.
 *
 * Required configuration variables. Copy the config.ini.example file to
 *   config.ini and replace with values for your server.
 *
 *   DB_USER = root
 *   DB_PASSWORD = pass
 *   DB_NAME = backdrop
 *   DB_HOST = localhost
 *   BACKDROP_ROOT = /var/www/html
 *   BACKUP_DESTINATION = /home/user/me/backups
 */

// Load up the config variables.
$config = parse_ini_file('config.ini');
$db_user = $config['DB_USER'];
$db_password = $config['DB_PASSWORD'];
$db_name = $config['DB_NAME'];
$db_temp = $config['DB_TEMP'];
$db_host = $config['DB_HOST'];
$backdrop_root = $config['BACKDROP_ROOT'];
$backup_destination = $config['BACKUP_DESTINATION'];
$num_keep = $config['NUM_KEEP'];
$timezone = $config['TIMEZONE'];

// If there is a CiviCRM database identified in config, set the flag and load up
// its config variables.
$civi = isset($config['DB_USER_CIVI']);
if ($civi) {
  $db_user_civi = $config['DB_USER_CIVI'];
  $db_password_civi = $config['DB_PASSWORD_CIVI'];
  $db_name_civi = $config['DB_NAME_CIVI'];
  $db_temp_civi = $config['DB_TEMP_CIVI'];
}

// Get some *.inc files we need.
require_once "$backdrop_root/core/includes/bootstrap.inc";
require_once "$backdrop_root/core/includes/password.inc";

// Check which options were passed in on the command line.
if (in_array('--quiet', $argv) || in_array('-q', $argv)) {
  $quiet = TRUE;
}
else {
  $quiet = FALSE;
}
if (in_array('--sanitize', $argv) || in_array('-s', $argv)) {
  $sanitize = TRUE;
}
else {
  $sanitize = FALSE;
}
if (in_array('--rollover', $argv) || in_array('-r', $argv)) {
  $rollover = TRUE;
}
else {
  $rollover = FALSE;
}
if (in_array('--latest', $argv) || in_array('-l', $argv)) {
  $latest = TRUE;
}
else {
  $latest = FALSE;
}

if ($sanitize) {
  _sanitize_backdrop($db_user, $db_password, $db_host, $db_name, $db_temp);
  $db_name = $db_temp;
  $backup_destination = $backup_destination . '/sanitized';
  if ($civi) {
    _sanitize_civicrm($db_user_civi, $db_password_civi, $db_host, $db_name_civi, $db_temp_civi);
    $db_name_civi = $db_temp_civi;
    $backup_destination_civi = $backup_destination . '_civi';
  }
}

date_default_timezone_set($timezone);
$date = date('F-j-Y-Gis');

// Dump DBs to files.
exec("mkdir -p $backup_destination");
$file_name = $sanitize ? "$db_name-$date-sanatized" : "$db_name-$date";
exec("mysqldump -R -h $db_host -u$db_user -p$db_password $db_name | gzip > $backup_destination/$file_name.sql.gz");

if ($civi) {
  exec("mkdir -p $backup_destination_civi");
  $file_name_civi = $sanitize ? "$db_name_civi-$date-sanatized" : "$db_name_civi-$date";
  exec("mysqldump -R -h $db_host -u$db_user_civi -p$db_password_civi $db_name_civi | gzip > $backup_destination_civi/$file_name_civi.sql.gz");
}

// Give db dump files nice names that include the date and create symlinks for
// the latest versions.
$db_name = $config['DB_NAME'];
$nice_name = $sanitize ? "$db_name-$date-sanatized" : "$db_name-$date";
exec("mv $backup_destination/$file_name.sql.gz $backup_destination/$nice_name.sql.gz");
if ($latest) {
  $sanitized = $sanitize ? '-sanitized' : '';
  if (file_exists("$backup_destination/$db_name-latest$sanitized.sql.gz")) {
    if (is_link("$backup_destination/$db_name-latest$sanitized.sql.gz")) {
      unlink("$backup_destination/$db_name-latest$sanitized.sql.gz");
    }
  }
  symlink("$backup_destination/$nice_name.sql.gz", "$backup_destination/$db_name-latest$sanitized.sql.gz");
}

if ($civi) {
  $db_name_civi = $config['DB_NAME_CIVI'];
  $nice_name_civi = $sanitize ? "$db_name_civi-$date-sanatized" : "$db_name_civi-$date";
  exec("mv $backup_destination_civi/$file_name_civi.sql.gz $backup_destination_civi/$nice_name_civi.sql.gz");
  if ($latest) {
    $sanitized = $sanitize ? '-sanitized' : '';
    if (file_exists("$backup_destination_civi/$db_name_civi-latest$sanitized.sql.gz")) {
      if (is_link("$backup_destination_civi/$db_name_civi-latest$sanitized.sql.gz")) {
        unlink("$backup_destination_civi/$db_name_civi-latest$sanitized.sql.gz");
      }
    }
    symlink("$backup_destination_civi/$nice_name_civi.sql.gz", "$backup_destination_civi/$db_name_civi-latest$sanitized.sql.gz");
  }
}

// Give feedback if the --quiet option is not set.
if (!$quiet) {
  if (file_exists("$backup_destination/$nice_name.sql.gz")) {
    print "\n\t\tBackup successful: $backup_destination/$nice_name.sql.gz\n\n";
  }
  else {
    print "\n\t\tBackup failed: Perhaps check your config.ini settings?\n\n";
  }
  if ($civi && file_exists("$backup_destination_civi/$nice_name_civi.sql.gz")) {
    print "\n\t\tBackup successful: $backup_destination_civi/$nice_name_civi.sql.gz\n";
  }
  else {
    print "\n\t\tBackup of CiviCRM failed: Perhaps check your config.ini settings?\n";
  }
}

// Remove the temporary databases used in sanitization.
if ($sanitize) {
  exec("echo \"drop database if exists $db_temp\" | mysql -u $db_user -p$db_password");
  if ($civi) {
    exec("echo \"drop database if exists $db_temp_civi\" | mysql -u $db_user_civi -p$db_password_civi");
  }
}

if ($rollover) {
  _rollover_backups($backup_destination, $num_keep);
  if ($civi) {
    _rollover_backups($backup_destination_civi, $num_keep);
  }
}

/**
 * Helper function to optionally sanitize the Backdrop database backup.
 *
 * @param string $db_user
 *   Database user with permission to create and drop databases.
 *   Passed in via DB_USER in config.ini.
 *
 * @param string $db_password
 *   Password of the $db_user; passed in DB_PASSWORD via config.ini.
 *
 * @param string $db_host
 *   Usually 'localhost' passed in via DB_HOST in config.ini.
 *
 * @param string $db_name
 *   The database to sanitize and backup.
 *   Passed in via DB_NAME in config.ini.
 *
 * @param string $db_temp
 *   The temporary database that will hold the sanitized version of the db.
 *   Passed in via DB_TEMP in config.ini.
 */
function _sanitize_backdrop($db_user, $db_password, $db_host, $db_name, $db_temp) {
  // Create the temporary database.
  exec("echo \"drop database if exists $db_temp\" | mysql -u $db_user -p$db_password");
  exec("echo \"create database $db_temp\" | mysql -u $db_user -p$db_password");

  // Dump DB and pipe into $db_temp.
  exec("mysqldump -R -h $db_host -u $db_user -p$db_password $db_name | mysql -h $db_host -u $db_user -p$db_password $db_temp");

  // Clear the cache% tables.
  _truncate_cache_tables($db_user, $db_password, $db_host, $db_name, $db_temp);

  // Get mysql connection to $db_name.
  try {
    $conn = new PDO("mysql:host=$db_host;dbname=$db_temp", $db_user, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sql = "select * from users;";
    $stmt = $conn->prepare($sql);
    $stmt->execute();

    $result = $stmt->fetchAll();
    $password = user_hash_password('password');

    foreach ($result as $row) {
      $uid = $row['uid'];
      if ($uid != 0) {
        $update = "update users
          set
            mail=\"user+$uid@localhost\",
            init=\"user+$uid@localhost\",
            pass=\"$password\"
          where uid = $uid;";
        $exec = $conn->prepare($update);
        $exec->execute();
      }
    }
  }
  catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
  }
}

/**
 * Helper function to optionally sanitize the CiviCRM database backup.
 *
 * @param string $db_user
 *   Database user with permission to create and drop databases.
 *   Passed in via DB_USER_CIVI in config.ini.
 *
 * @param string $db_password
 *   Password of the $db_user; passed in DB_PASSWORD_CIVI via config.ini.
 *
 * @param string $db_host
 *   Usually 'localhost' passed in via DB_HOST in config.ini.
 *
 * @param string $db_name
 *   The database to sanitize and backup.
 *   Passed in via DB_NAME_CIVI in config.ini.
 *
 * @param string $db_temp
 *   The temporary database that will hold the sanitized version of the db.
 *   Passed in via DB_TEMP_CIVI in config.ini.
 */
function _sanitize_civicrm($db_user, $db_password, $db_host, $db_name, $db_temp) {
  // Create the TMP_DB database.
  exec("echo \"drop database if exists $db_temp\" | mysql -u $db_user -p$db_password");
  exec("echo \"create database $db_temp\" | mysql -u $db_user -p$db_password");

  // Dump DB and pipe into $db_temp.
  exec("mysqldump -R -h $db_host -u $db_user -p$db_password $db_name | mysql -h $db_host -u $db_user -p$db_password $db_temp");

  // Clear the civicrm_%cache tables.
  _truncate_cache_tables_civi($db_user, $db_password, $db_host, $db_name, $db_temp);

  // Get mysql connection to $db_name. Sanitize tables that contain personally
  // identifiable information.
  try {
    $conn = new PDO("mysql:host=$db_host;dbname=$db_temp", $db_user, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get the uf_match table to use to ensure synchrony between Backdrop and
    // CiviCRM email addresses for user accounts. Use it to build a list of
    // account email addresses indexed on contact ID.
    $sql = "select * from civicrm_uf_match;";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $uf_match = $stmt->fetchAll();
    foreach ($uf_match as $row) {
      $uid = $row['uf_id'];
      $contact_emails[$row['contact_id']] = "user+$uid@localhost";
    }

    // civicrm_address
    _sanitize_table($conn, 'civicrm_address', 'id', array(
      'street_address',
      'city',
      'country_id',
      'state_province_id',
      'postal_code',
    ), $row);

    // civicrm_contact
    _sanitize_table($conn, 'civicrm_contact', 'id', array(
      'sort_name',
      'display_name',
      'nick_name',
      'legal_name',
      'last_name',
      'addressee_display',
    ), $row);

    // civicrm_email
    _sanitize_table($conn, 'civicrm_email', 'contact_id', array(
      'email',
    ), $row, $contact_emails);

    // civicrm_uf_match
    _sanitize_table($conn, 'civicrm_uf_match', 'contact_id', array(
      'uf_name',
    ), $row, $contact_emails);

  }
  catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
  }
}

/**
 * Helper function to sanitize one or more columns of a table with a value based
 * on the id of the row.
 *
 * @param PDO $conn
 *   The database connection.
 *
 * @param string $table
 *   The db table that is being sanitized.
 *
 * @param string $id_name
 *   The table column that should be used as a distinguishing value for the
 *   sanitized data.
 *
* @param array $cols
 *   A list of the table columns that should be sanitized.
 *
 * @param array $row
 *   The current value of all columns of the row, including $id_name. Only
 *   non-empty values will get a sanitized value, which consists of the column
 *   name from $cols and the value in column $id_name.
 *
 * @param array $contact_emails
 *   An array of user account email addresses indexed on the contact ID.
 */
function _sanitize_table($conn, $table, $id_name, $cols, $row, $contact_emails = array()) {
  $sql = "select * from $table;";
  $stmt = $conn->prepare($sql);
  $stmt->execute();
  $result = $stmt->fetchAll();
  foreach($result as $row) {
    $id_value = $row[$id_name];
    $setters = array();
    foreach ($cols as $col) {
      if (!empty($row[$col])) {
        if ($table == 'civicrm_address' && $col == 'country_id') {
          // Set all countries to the USA
          $setters[] = " $col=\"1228\"";
        }
        elseif ($table == 'civicrm_address' && $col == 'state_province_id') {
          // Set all states to California
          $setters[] = " $col=\"1004\"";
        }
        elseif ($table == 'civicrm_address' && $col == 'postal_code') {
          // Set all postal codes to a fictitious value
          $setters[] = " $col=\"98765\"";
        }
        elseif ($table == 'civicrm_email' && $col == 'email') {
          // $id_value is the contact ID
          if (isset($contact_emails[$id_value])) {
            $email = $contact_emails[$id_value];
          }
          else {
            $email = "contact+$id_value@localhost";
          }
          $setters[] = " $col=\"$email\"";
        }
        elseif ($table == 'civicrm_uf_match' && $col == 'uf_name') {
          // $id_value is the contact ID
          if (isset($contact_emails[$id_value])) {
            $email = $contact_emails[$id_value];
          }
          else {
            $email = "contact+$id_value@localhost";
          }
          $setters[] = " $col=\"$email\"";
        }
        else {
          // For all other fields, just use the column name and $id_value.
          $setters[] = " $col=\"$col+$id_value\"";
        }
      }
    }
    if (empty($setters)) {
      continue;
    }
    $update = "update $table set " . implode(', ', $setters) . " where $id_name = $id_value;";
    $exec = $conn->prepare($update);
    $exec->execute();
  }
}

/**
 * Helper function to delete stale backups.
 *
 * @param string $backup_destination
 *   The path to the directory where you would like to delete stale backups.
 *
 * @param int $num_keep
 *   The number of backups you would like to keep.  Defaults to 3.
 */
function _rollover_backups($backup_destination, $num_keep = 3) {
  $filemtime_keyed_array = [];
  $bups = scandir($backup_destination);
  foreach ($bups as $key => $b) {
    if (is_link($b)) continue;
    if (strpos($b, '.sql.gz') === FALSE) {
      unset($bups[$key]);
    }
    else {
      $my_key = filemtime("$backup_destination/$b");
      $filemtime_keyed_array[$my_key] = $b;
    }
  }
  ksort($filemtime_keyed_array);
  $newes_bups_first = array_reverse($filemtime_keyed_array);
  $k = 0;
  foreach ($newes_bups_first as $bup) {
    if ($k > ($num_keep - 1)) {
      exec("rm $backup_destination/$bup");
    }
    $k++;
  }
}

/**
 * Helper function to truncate Backdrop cache tables.
 *
 * @param string $db_user
 *   Database user with permission to create and drop databases.
 *   Passed in via DB_USER in config.ini.
 *
 * @param string $db_password
 *   Password of the $db_user; passed in DB_PASSWORD via config.ini.
 *
 * @param string $db_host
 *   Usually 'localhost' passed in via DB_HOST in config.ini.
 *
 * @param string $db_name
 *   The database to sanitize and backup.
 *   Passed in via DB_NAME in config.ini.
 *
 * @param string $db_temp
 *   The temporary database that will hold the sanitized version of the db.
 *   Passed in via DB_TEMP in config.ini.
 */
function _truncate_cache_tables($db_user, $db_password, $db_host, $db_name, $db_temp) {
  try {
    $conn = new PDO("mysql:host=$db_host;dbname=$db_temp", $db_user, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "SELECT concat('TRUNCATE TABLE `', TABLE_NAME, '`;')
      FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_NAME LIKE 'cache%' and table_schema=\"$db_name\";";

    $statement = $conn->prepare($sql);
    $statement->execute();

    $result = $statement->fetchAll();
    foreach ($result as $r) {
      $clear_statement = $conn->prepare($r[0]);
      $clear_statement->execute();
    }
  }
  catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
  }
}

/**
 * Helper function to truncate CiviCRM cache tables.
 *
 * @param string $db_user
 *   Database user with permission to create and drop databases.
 *   Passed in via DB_USER_CIVI in config.ini.
 *
 * @param string $db_password
 *   Password of the $db_user; passed in DB_PASSWORD_CIVI via config.ini.
 *
 * @param string $db_host
 *   Usually 'localhost' passed in via DB_HOST in config.ini.
 *
 * @param string $db_name
 *   The database to sanitize and backup.
 *   Passed in via DB_NAME_CIVI in config.ini.
 *
 * @param string $db_temp
 *   The temporary database that will hold the sanitized version of the db.
 *   Passed in via DB_TEMP_CIVI in config.ini.
 */
function _truncate_cache_tables_civi($db_user, $db_password, $db_host, $db_name, $db_temp) {
  try {
    $conn = new PDO("mysql:host=$db_host;dbname=$db_temp", $db_user, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "SELECT concat('TRUNCATE TABLE `', TABLE_NAME, '`;')
      FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_NAME LIKE 'civicrm_%cache' and table_schema=\"$db_name\";";

    $statement = $conn->prepare($sql);
    $statement->execute();

    $result = $statement->fetchAll();
    foreach ($result as $r) {
      $clear_statement = $conn->prepare($r[0]);
      $clear_statement->execute();
    }
  }
  catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
  }
}
