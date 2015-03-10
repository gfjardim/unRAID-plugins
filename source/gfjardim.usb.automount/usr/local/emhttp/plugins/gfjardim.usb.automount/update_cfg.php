<?
$plugin = "gfjardim.usb.automount";
require_once("/usr/local/emhttp/plugins/${plugin}/include/usb_mount_lib.php");

switch ($_POST['action']) {
  case 'automount':
    $serial = urldecode(($_POST['serial']));
    $status = urldecode(($_POST['status']));
    echo json_encode(array( 'automount' => toggle_automount($serial, $status) ));
  break;
  case 'get_command':
    $serial = urldecode(($_POST['serial']));
    echo json_encode(array( 'command' => get_command($serial)));
  break;
  case 'set_command':
    $serial = urldecode(($_POST['serial']));
    $cmd = urldecode(($_POST['command']));
    echo json_encode(array( 'result' => set_command($serial,$cmd)));
  break;
  case 'remove_config':
    $serial = urldecode(($_POST['serial']));
    echo json_encode(array( 'result' => remove_config_disk($serial)));
  break;
}