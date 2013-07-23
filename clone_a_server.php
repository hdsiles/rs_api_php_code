<?php

$cloud_credentials_file = '/path/to/.credentials_file';

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
  $shortopts .= "C:";
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

  /// Get Server Name that will be the clone of the original
  if (isset($options['S'])) {
    $server_name = $options['S'];
    if (!(preg_match("/^([a-z0-9]+-)*[a-z0-9]+$/i", $server_name))) {
      print "New Server name $server_name is not a valid name.\n";
      usage();
    }
  }
  else {
    print "Missing New Server name\n";
    usage();
  }
  
  /// Get Flavor (Server Size of new cloned server)
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

  /// Get Image Name to create prior to server build.
  if (isset($options['I'])) {
    $image_name = $options['I'];
    if (!(preg_match("/^([a-z0-9]+-)*[a-z0-9]+$/i", $image_name))) {
      print "Image name $image_name is not a valid name.\n";
      usage();
    }
  }
  else {
    print "Missing image name that will be created\n";
    usage();
  }

  /// Get UUID to clone from.
  if (isset($options['C'])) {
    $clone_uuid = $options['C'];
    if (!(preg_match("/^([a-z0-9]+-)*[a-z0-9]+$/i", $clone_uuid))) {
      print "UUID for Server does not seem to be a valid format.\n";
      usage();
    }    
  }
  else {
    print "Missing source server uuid.\n";
    usage();
  }
  
  /// Show Progress
  if (isset($options['V'])) {
    $verbose='1';
  }
  
  /// Input checks are done, image and server build work below here:
  $compute = $conn->Compute('cloudServersOpenStack', $working_region);
  print "\n";

  $sourceServ = $compute->Server("$clone_uuid");

  /// Create an image from a server
  $tstamp_id = time();
  $sourceServ->CreateImage("$image_name", array('clonetag' => $tstamp_id));

  while (true) {
    $imagelist = $compute->ImageList(TRUE, array('type' => 'SNAPSHOT', 'serverRef' => "$clone_uuid"));
    while($image = $imagelist->Next()) {
      if ( (isset($image->metadata->clonetag)) && ($image->metadata->clonetag == $tstamp_id) ) {
        $clone_name = $image->name;
        $install_image = $image->id;
        if (!(isset($image_start))) {
          print 'Building Image: '.$clone_name.'   uuid: '.$install_image."\n";
          $image_start='1';
        }
        if (isset($verbose)) {
          print 'Image Progress: '.$image->progress.'%'."\n";
        }
        if ($image->progress == '100') {
          print "\n";
          break 2;
        }
      }
    }
    sleep(15);
  }


  /// Collect Server info and begin build on data given.
  $server = $compute->Server();
  $server->Create(array('name' => $server_name, 'flavor' => $compute->Flavor($server_size), 'image' => $compute->Image($install_image)));
  print "Starting Build on: $server_name\n";

  print "\n-- Displaying info below as it becomes availiable --\n";


  /// Watch server build and get back info about server.
  $update_sent = '0';
  while (true) {
    sleep(15);
    $server->Refresh($server->id);
    if (in_array($server->status, array('ACTIVE', 'ERROR'))) {
      $server->ip('4');
      printf("Completed: %s   uuid: %-38s   %3d%%  ip: %-24s  root pass: %-18s\n", $server->name, $server->id, $server->progress, $server->addresses->public[0]->addr, $server->adminPass);
      break;
    }
    elseif (isset($verbose)) {
      if ( (isset($server->addresses->public[0]->addr)) && (!(empty($server->addresses->public[0]->addr))) ) {
        $server->ip('4');
        printf(" Building: %s   uuid: %-38s   %3d%%  ip: %-24s  root pass: %-18s\n", $server->name, $server->id, $server->progress, $server->addresses->public[0]->addr, $server->adminPass);
      }
      else {
        printf(" Building: %s   uuid: %-38s   %3d%%  Waiting on Networking...\n", $server->name, $server->id, $server->progress);
      }
    }
    else {
      if ( (isset($server->addresses->public[0]->addr)) && (!(empty($server->addresses->public[0]->addr))) && ($update_sent == '0') ) {
        $server->ip('4');
        printf(" Building: %s   uuid: %-38s   %3d%%  ip: %-24s  root pass: %-18s\n", $server->name, $server->id, $server->progress, $server->addresses->public[0]->addr, $server->adminPass);
        $update_sent = '1';
      }
      continue;
    }
  }
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
    -C Clone Server  # UUID of the server that will be imaged, and used as a template for the new server.
    -S "Server Name" # Server Name The new server you are creating.
    -F Flavor	     # Size - Mem:  1) 512MB   2) 1GB   3) 2GB   4) 4GB   5) 8GB   6) 15GB   7) 30GB  (This example defaults to 512MB).
    -I "Image Name"  # What Image name you are creating. ie: "My test image".
    -V		     # Verbose output, Show more progress during build processes.
    
    Example:  php chal_2_clone_server.php -R=ORD -C"abc123eb-86ts-1234-128673sh67" -S"new test" -F"1" -I"My image of source server"

PRINTUSAGE;
echo "\n";
exit();
}
?>
