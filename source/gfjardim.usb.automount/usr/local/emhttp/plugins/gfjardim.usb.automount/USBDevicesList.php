<?PHP
$plugin = "gfjardim.usb.automount";
require_once("/usr/local/emhttp/plugins/${plugin}/include/usb_mount_lib.php");

function my_temp($val, $unit, $dot) {
  return ($val>0 ? str_replace('.',$dot,$unit=='C' ? $val : round(9/5*$val+32))."&deg;$unit" : '*');
}

switch ($_POST['action']) {
  case 'get_content':
  $disks = get_all_disks_info();
  echo "<table class='usb_disks'><thead><tr><td>Device</td><td>Identification</td><td>Temp</td><td>FS</td><td>Size</td><td>Used</td><td>Free</td><td>Open files</td><td>Auto mount</td><td>Script</td></tr></thead>";
  echo "<tbody>";
  if ( count($disks) ) {
    $odd="odd";
    foreach ($disks as $disk) {
      echo "<tr class='$odd'>";
      $dev = sprintf( "<img src='/webGui/images/%s'> %s", ( is_shared($disk['label']) ? "green-on.png":"red-on.png" ), basename($disk['device']) );
      echo "<td>$dev</td>";
      echo "<td>".$disk['partitions'][0]['serial']."</td>";
      $temp = my_temp($disk['temperature'], $_POST['unit'], $_POST['dot']);
      echo "<td >{$temp}</td><td>-</td><td>-</td><td>-</td><td>-</td><td>-</td>";
      echo "<td><input type='checkbox' class='automount' serial='".$disk['partitions'][0]['serial']."' ".(($disk['partitions'][0]['automount']) ? 'checked':'')."></td><td>-</td></tr>";
      foreach ($disk['partitions'] as $partition) {
        $mounted = is_mounted($partition['device']);
        echo "<tr class='$odd'><td></td><td><div>";
        $fscheck = sprintf(get_fsck_commands($partition['fstype'])['ro'], $partition['device'] );
        $fscheck = (! $mounted) ? "<span class='exec' onclick='openWindow(\"{$fscheck}\",\"Check filesystem\",600,900);'>" : "<span>";
        echo "<i class='glyphicon glyphicon-hdd'></i>&nbsp;&nbsp;<b>{$fscheck}Partition {$partition[part]}</b></span>&nbsp;&nbsp;<i class='glyphicon glyphicon-arrow-right'></i>&nbsp;&nbsp;";
        if ($mounted) {
          echo $partition['mountpoint'];
        } else {
          echo "<form method='POST' action='/plugins/${plugin}/USBDevicesList.php?action=change_mountpoint&serial={$partition[serial]}&partition={$partition[part]}' target='progressFrame' style='display:inline;margin:0;padding:0;'>";
          echo "<span class='text exec'><a>{$partition[mountpoint]}</a></span><input class='input' type='text' name='mountpoint' value='{$partition[mountpoint]}' hidden />";
          echo "</form>";
        }
        echo "<span style='width:auto;text-align:right;'>&nbsp;&nbsp;&nbsp;".($mounted ? "<button type='button' style='padding:2px 7px 2px 7px;' onclick=\"usb_mount('/usr/local/sbin/usb_umount ${partition[device]}');\"><i class='glyphicon glyphicon-export'></i> Unmount</button>" : "<button type='button' style='padding:2px 7px 2px 7px;' onclick=\"usb_mount('/usr/local/sbin/usb_mount ${partition[device]}');\"><i class='glyphicon glyphicon-import'></i>  Mount</button>")."</span>&nbsp;&nbsp;&nbsp;";
        echo "</div></td><td>-</td>";
        echo "<td >".$partition['fstype']."</td>";
        echo "<td>".$partition['size']."</td>";
        echo "<td>".$partition['used']."</td>";
        echo "<td>".$partition['avail']."</td>";
        echo "<td>".(strlen($partition['target']) ? shell_exec("lsof '${partition[target]}' 2>/dev/null|grep -c -v COMMAND") : "-")."</td><td>-</td>";
        echo "<td><a href='/Main/EditScript?serial=".urlencode($partition['serial'])."&label=".urlencode($partition['label'])."'><img src='/webGui/images/default.png' style='cursor:pointer;width:16px;".( (get_config($partition['serial'],"command")) ? "":"opacity: 0.4;" )."'></a></td>";
      }
      echo "</tr>";
      $odd = ($odd == "odd") ? "even" : "odd";
    }
  } else {
    echo "<tr><td colspan='12' style='text-align:center;font-weight:bold;'>No usb disks present.</td></tr>";
  }
  echo "</tbody></table>";

  $config_file = $GLOBALS["paths"]["config_file"];
  $config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
  $disks_serials = array();
  foreach ($disks as $disk) $disks_serials[] = $disk['partitions'][0]['serial'];
  $ct = "";
  foreach ($config as $serial => $value) {
    if (! preg_grep("#${serial}#", $disks_serials)){
      $ct .= "<tr><td><img src='/webGui/images/green-blink.png'> missing</td><td>$serial</td><td><input type='checkbox' class='automount' serial='${serial}' ".( is_automount($serial) ? 'checked':'' )."></td><td><a href='/Main/EditScript?serial=${serial}'>".basename($value['command'])."</a></td><td colspan='7'><span style='cursor:pointer;' onclick='remove_disk_config(\"${serial}\")'>Remove</a></td></tr>";
    }
  }
  if (strlen($ct)) echo "<table class='tablesorter usb_absent'><thead><tr><th>Device</th><th>Serial Number</th><th>Auto mount</th><th>Script</th><th colspan='7'>Remove config</th></tr></thead><tbody>${ct}</tbody></table>";
  echo '<script type="text/javascript">';
  echo '$(".automount").each(function(){var checked = $(this).is(":checked");$(this).switchButton({labels_placement: "right", checked:checked});});';
  echo '$(".automount").change(function(){$.post("/plugins/'.$plugin.'/update_cfg.php",{action:"automount",serial:$(this).attr("serial"),status:$(this).is(":checked")},function(data){$(this).prop("checked",data.automount);},"json");});';
  echo "$('.text').click(showInput);$('.input').blur(hideInput)";
  echo '</script>';
  break;
  case 'detect':
  if (is_file("/var/state/${plugin}")) {
    echo json_encode(array("reload" => true));
  } else {
    echo json_encode(array("reload" => false));
  }
  break;
  case 'remove_hook':
  @unlink("/var/state/${plugin}");
  break;
  case 'change_mountpoint':
  echo "teste";
  break;
}
switch ($_GET['action']) {
  case 'change_mountpoint':
    $serial = urldecode($_GET['serial']);
    $partition = urldecode($_GET['partition']);
    $mountpoint = $partition."|".$_POST['mountpoint'];
    set_config($serial, "mountpoint-part".$partition, $mountpoint);
    require_once("/usr/local/emhttp/update.htm");
    break;
}
?>