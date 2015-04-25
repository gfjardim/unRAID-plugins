<?PHP
$plugin = "unassigned.devices";
require_once("plugins/${plugin}/include/lib.php");
require_once ("webGui/include/Helpers.php");

if (isset($_POST['display'])) $display = $_POST['display'];

function render_used_and_free($partition) {
  global $display;
  if (strlen($partition['target'])) {
    if (!$display['text']) {
      echo "<td>".my_scale($partition['used'], $unit)." $unit</td>";
      echo "<td>".my_scale($partition['avail'], $unit)." $unit</td>";
    } else {
      $free = round(100*$partition['avail']/$partition['size']);
      $used = 100-$free;
      echo "<td><div class='usage-disk'><span style='margin:0;width:{$used}%' class='".usage_color($used,false)."'><span>".my_scale($partition['used'], $unit)." $unit</span></span></div></td>";
      echo "<td><div class='usage-disk'><span style='margin:0;width:{$free}%' class='".usage_color($free,true)."'><span>".my_scale($partition['avail'], $unit)." $unit</span></span></div></td>";
    }
  } else {
    echo "<td>-</td><td>-</td>";
  }
}

switch ($_POST['action']) {
  case 'get_content':
    $disks = get_all_disks_info();
    echo "<table class='usb_disks'><thead><tr><td>Device</td><td>Identification</td><td></td><td>Temp</td><td>FS</td><td>Size</td><td>Used</td><td>Free</td><td>Open files</td><td>Auto mount</td><td>Script</td></tr></thead>";
    echo "<tbody>";
    if ( count($disks) ) {
      $odd="odd";
      foreach ($disks as $disk) {
        echo "<tr class='$odd'>";
        printf( "<td><img src='/webGui/images/%s'> %s</td>", ( is_disk_running($disk['device']) ? "green-on.png":"green-blink.png" ), basename($disk['device']) );
        $disk_mounted = false;
        foreach ($disk['partitions'] as $p) if (is_mounted($p['device'])) $disk_mounted = TRUE;
        $m_button = "<td><span style='width:auto;text-align:right;'>".($disk_mounted ? "<button type='button' style='padding:2px 7px 2px 7px;' onclick=\"usb_mount('/usr/local/sbin/unassigned_umount {$disk[device]}');\"><i class='glyphicon glyphicon-export'></i> Unmount</button>" : "<button type='button' style='padding:2px 7px 2px 7px;' onclick=\"usb_mount('/usr/local/sbin/unassigned_mount {$disk[device]}');\"><i class='glyphicon glyphicon-import'></i>  Mount</button>")."</span></td>";
        echo "<td><i class='glyphicon glyphicon-hdd hdd'></i>".$disk['partitions'][0]['serial'].$m_button."</td>";
        $temp = my_temp($disk['temperature']);
        echo "<td >{$temp}</td><td>-</td><td>-</td><td>-</td><td>-</td><td>-</td>";
        echo "<td><input type='checkbox' class='automount' serial='".$disk['partitions'][0]['serial']."' ".(($disk['partitions'][0]['automount']) ? 'checked':'')."></td><td>-</td></tr>";
        foreach ($disk['partitions'] as $partition) {
          $mounted = is_mounted($partition['device']);
          echo "<tr class='$odd'><td></td><td><div>";
          $fscheck = sprintf(get_fsck_commands($partition['fstype'])['ro'], $partition['device'] );
          $icon = "<i class='glyphicon glyphicon-th-large partition'></i>";
          $fscheck = ((! $mounted) ? "<a class='exec' onclick='openWindow(\"{$fscheck}\",\"Check filesystem\",600,900);'>${icon}{$partition[part]}</a>" : "${icon}{$partition[part]}");
          echo "{$fscheck}<i class='glyphicon glyphicon-arrow-right'></i>";
          if ($mounted) {
            echo $partition['mountpoint'];
          } else {
            echo "<form method='POST' action='/plugins/${plugin}/UnassignedDevices.php?action=change_mountpoint&serial={$partition[serial]}&partition={$partition[part]}' target='progressFrame' style='display:inline;margin:0;padding:0;'>";
            echo "<span class='text exec'><a>{$partition[mountpoint]}</a></span><input class='input' type='text' name='mountpoint' value='{$partition[mountpoint]}' hidden />";
            echo "</form>";
          }
          echo "<td><span style='width:auto;text-align:right;'>".($mounted ? "<button type='button' style='padding:2px 7px 2px 7px;' onclick=\"usb_mount('/usr/local/sbin/unassigned_umount ${partition[device]}');\"><i class='glyphicon glyphicon-export'></i> Unmount</button>" : "<button type='button' style='padding:2px 7px 2px 7px;' onclick=\"usb_mount('/usr/local/sbin/unassigned_mount ${partition[device]}');\"><i class='glyphicon glyphicon-import'></i>  Mount</button>")."</span></td>";
          echo "</div></td><td>-</td>";
          echo "<td >".$partition['fstype']."</td>";
          echo "<td><span>".my_scale($partition['size'], $unit)." $unit</span></td>";
          render_used_and_free($partition);
          // $d1 = time();
          echo "<td>".(strlen($partition['target']) ? shell_exec("lsof '${partition[target]}' 2>/dev/null|grep -c -v COMMAND") : "-")."</td><td>-</td>";
          // debug("openfiles [${partition[device]}]: ".(time() - $d1));
          echo "<td><a href='/Main/EditScript?s=".urlencode($partition['serial'])."&l=".urlencode(basename($partition['mountpoint']))."&p=".urlencode($partition['part'])."'><img src='/webGui/images/default.png' style='cursor:pointer;width:16px;".( (get_config($partition['serial'],"command.{$partition[part]}")) ? "":"opacity: 0.4;" )."'></a></td>";
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
        $ct .= "<tr><td><img src='/webGui/images/green-blink.png'> missing</td><td>$serial</td><td><input type='checkbox' class='automount' serial='${serial}' ".( is_automount($serial) ? 'checked':'' )."></td><td colspan='7'><a style='cursor:pointer;' onclick='remove_disk_config(\"${serial}\")'>Remove</a></td></tr>";
      }
    }
    if (strlen($ct)) echo "<table class='tablesorter usb_absent'><thead><tr><th>Device</th><th>Serial Number</th><th>Auto mount</th><th colspan='7'>Remove config</th></tr></thead><tbody>${ct}</tbody></table>";
    echo '<script type="text/javascript">';
    echo '$(".automount").each(function(){var checked = $(this).is(":checked");$(this).switchButton({labels_placement: "right", checked:checked});});';
    echo '$(".automount").change(function(){$.post(URL,{action:"automount",serial:$(this).attr("serial"),status:$(this).is(":checked")},function(data){$(this).prop("checked",data.automount);},"json");});';
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
  case 'automount':
    $serial = urldecode(($_POST['serial']));
    $status = urldecode(($_POST['status']));
    echo json_encode(array( 'automount' => toggle_automount($serial, $status) ));
  break;
  case 'get_command':
    $serial = urldecode(($_POST['serial']));
    $part   = urldecode(($_POST['part']));
    echo json_encode(array( 'command' => get_config($serial, "command.{$part}")));
  break;
  case 'set_command':
    $serial = urldecode(($_POST['serial']));
    $part = urldecode(($_POST['part']));
    $cmd = urldecode(($_POST['command']));
    echo json_encode(array( 'result' => set_config($serial, "command.{$part}", $cmd)));
  break;
  case 'remove_config':
    $serial = urldecode(($_POST['serial']));
    echo json_encode(array( 'result' => remove_config_disk($serial)));
  break;
}
switch ($_GET['action']) {
  case 'change_mountpoint':
    $serial = urldecode($_GET['serial']);
    $partition = urldecode($_GET['partition']);
    $mountpoint = urldecode($_POST['mountpoint']);
    set_config($serial, "mountpoint.${partition}", $mountpoint);
    require_once("/usr/local/emhttp/update.htm");
    break;
}
?>