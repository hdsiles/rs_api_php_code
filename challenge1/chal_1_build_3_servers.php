<?php

$cloud_credentials_file = '/root/.rackspace_cloud_credentials';
$server_build_number = '3';


require_once ('/usr/lib/php5/php-opencloud/lib/rackspace.php');
require_once ('/usr/lib/php5/php-opencloud/lib/compute.php');

///////////////  Change Above //////////////////
define('INIFILE', $cloud_credentials_file);
$ini = parse_ini_file(INIFILE, TRUE);
if (!$ini) {
  printf("Unable to load .ini file [%s]\n", INIFILE);
  exit;
}

$conn = new OpenCloud\Rackspace(RACKSPACE_US, array('username' => $ini['rackspace_cloud']['NOVA_USERNAME'], 'apiKey' => $ini['rackspace_cloud']['NOVA_API_KEY']));


$isCLI = (php_sapi_name() == 'cli');

if ($isCLI == 'true') {
  $shortopts  = "R::";
  $shortopts .= "S:";
  $shortopts .= "F:";
  $shortopts .= "I:";
  $shortopts .= "V";
  
  $options = getopt($shortopts);

  /// Get the Region if its needed, will default to credentials file if it is set in that file.
  if (isset($options['R'])) {
    $working_region = $options['R'];
  }
  elseif (isset($ini['rackspace_cloud']['NOVA_REGION_NAME'])) {
    $working_region = $ini['rackspace_cloud']['NOVA_REGION_NAME'];
  }
  else {
    echo 'No region found in credentials file or given at the command line'."\n";
    usage();
  }

  /// Get Server Name
  if (isset($options['S'])) {
    $server_name = $options['S'];
    if (!(preg_match("/^([a-z0-9]+-)*[a-z0-9]+$/i", $server_name))) {
      print "Server name $server_name is not a valid name.\n";
      usage();
    }
  }
  else {
    usage();
  }
  
  /// Get Flavor (Server Size)
  if (isset($options['F'])) {
    $server_size = ($options['F'] + 1);
    if (!(preg_match("/^[0-9]$/", $server_size))) {
      print "Server size should be a single digit for the size/flavor.\n";
      usage();
    }
  }
  else {
    $server_size = '2';
  }
  
  /// Get Image (uuid) to install with.
  if (isset($options['I'])) {
    $install_image = $options['I'];
    if (!(preg_match("/^([a-z0-9]+-)*[a-z0-9]+$/i", $install_image))) {
      print "Server image $install_image does not seem to be a valid format.\n";
      usage();
    }    
  }
  else {
    $install_image = 'c195ef3b-9195-4474-b6f7-16e5bd86acd0';
  }
  
  /// Show Progress
  if (isset($options['V'])) {
    $verbose='1';
  }
  


  /// Input checks are done, server build work below here:

  $compute = $conn->Compute('cloudServersOpenStack', $working_region);

  /// Loop for challenge to SDK, production probably would not be a loop like this
  print "\n";

  for ($c=1; $c<=$server_build_number; $c++) {
    $server_name_c = "$server_name"."$c";
    $servers[$c] = $compute->Server();
    $servers[$c]->Create(array('name' => $server_name_c, 'flavor' => $compute->Flavor($server_size), 'image' => $compute->Image($install_image)));
    print "Starting Build on: $server_name_c\n";
  }

  print "\n-- Displaying info below as it becomes availiable --\n";
  $build_complete = '0';
  $update_sent=array();
  while ($build_complete < $server_build_number) {
    sleep(15);
    foreach ($servers as $serv_key => $server) {
      $server->Refresh($server->id);
      if (in_array($server->status, array('ACTIVE', 'ERROR'))) {
        $server->ip('4');
        printf("Completed: %s   uuid: %-38s   %3d%% ip: %-24s  root pass: %-18s\n", $server->name, $server->id, $server->progress, $server->addresses->public[0]->addr, $server->adminPass);
        $build_complete++;
        unset($servers[$serv_key]);
      }
      elseif ((isset($verbose)) && ($verbose == '1')) {
        if ( (isset($server->addresses->public[0]->addr)) && (!(empty($server->addresses->public[0]->addr))) ) {
          $server->ip('4');
          printf(" Building: %s   uuid: %-38s   %3d%%  ip: %-24s  root pass: %-18s\n", $server->name, $server->id, $server->progress, $server->addresses->public[0]->addr, $server->adminPass);
        }
        else {
          printf(" Building: %s   uuid: %-38s   %3d%%  Waiting on Networking...\n", $server->name, $server->id, $server->progress);
        }
      }
      else {
        if ( (isset($server->addresses->public[0]->addr)) && (!(empty($server->addresses->public[0]->addr))) && (!(array_key_exists($serv_key, $update_sent))) ) {
          $server->ip('4');
          printf(" Building: %s   uuid: %-38s   %3d%%  ip: %-24s  root pass: %-18s\n", $server->name, $server->id, $server->progress, $server->addresses->public[0]->addr, $server->adminPass);
          $update_sent[$serv_key] = $serv_key;
        }
        continue;
      }
    }
  }
//  print_r($server);
  print "End Program.\n\n";
}
else {
  echo '<br><h2>This script is currently CLI (command line) only</h2>';
}



////////////////////////// Functions ////////////////////////////

//// Usage
function usage() {
echo "\n";
echo <<<PRINTUSAGE
  Required Options (Must be set in credentials file or specified here):
    -R=region	     # Region (will default to credentials if set with NOVA_REGION_NAME).
    -S="Server Name" # Server Name (In this example the server name will be iterated 3 times).
    -F="Flavor"	     # Size - Mem:  1) 512MB   2) 1GB   3) 2GB   4) 4GB   5) 8GB   6) 15GB   7) 30GB  (This example will choose 512MB).
    -I="Image"       # What Image UUID to build from. (This example will build from debian6.06 - 8ae428cd-0490-4f3a-818f-28213a7286b0 ).
    -V		     # Verbose output, Show more progress during build processes.
    
    Example:  php chal_1_build_3_servers.php -R=ORD -S"web" -F"1" -I"8ae428cd-0490-4f3a-818f-28213a7286b0"

PRINTUSAGE;
echo "\n";
exit();
}
?>
